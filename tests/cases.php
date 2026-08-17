<?php

/**
 * The cases.
 *
 * Seven groups, matching what the shared bootstrap is responsible for:
 * configuration, a single session startup, the session cookie's attributes,
 * compatibility with sessions and remember-me cookies that already exist, error
 * handling, what a database failure does to a request, and every entry point
 * loading the bootstrap.
 *
 * The machinery they are written against lives in tests/run.php.
 */

/*
 * ---------------------------------------------------------------------------
 * 1. Configuration
 * ---------------------------------------------------------------------------
 */

t_group('configuration', function () {
	$fixture = mcm_fixture('config-defaults');
	$result  = mcm_cli($fixture, 'probe.php');
	$report  = mcm_report($result['stdout']);

	t_same(0, $result['status'], 'a page with a usable configuration exits cleanly');
	t_same('defined', isset($report['bootstrap']) ? $report['bootstrap'] : '', 'the bootstrap ran');
	t_same('', $result['log'], 'a healthy request logs nothing');

	// The defaults, every one of which a site can run on unchanged.
	$defaults = array(
		'const_MCM_ERROR_REPORTING'         => (string) (E_ALL & ~E_NOTICE),
		'const_MCM_DISPLAY_ERRORS'          => 'false',
		'const_MCM_LOG_ERRORS'              => 'true',
		'const_MCM_ERROR_LOG'               => '',
		'const_MCM_GENERIC_ERROR_MESSAGE'   => rtrim(mcm_generic_message(), "\n"),
		'const_MCM_SESSION_COOKIE_LIFETIME' => '0',
		'const_MCM_SESSION_COOKIE_PATH'     => '/',
		'const_MCM_SESSION_COOKIE_SAMESITE' => 'Lax',
		'const_MCM_SESSION_COOKIE_SECURE'   => 'null',
	);
	foreach ($defaults as $name => $expected) {
		t_same($expected, isset($report[$name]) ? $report[$name] : '(absent)', 'default ' . substr($name, 6));
	}

	// An existing config.php is loaded first, so its values win outright and the
	// site needs no configuration edits at all.
	$fixture = mcm_fixture('config-precedence', array('config' => array(
		'MCM_SESSION_COOKIE_PATH'     => '/movies/',
		'MCM_SESSION_COOKIE_SAMESITE' => 'Strict',
		'MCM_SESSION_COOKIE_LIFETIME' => 600,
		'MCM_GENERIC_ERROR_MESSAGE'   => 'Temporarily unavailable.',
	)));
	$report = mcm_report(mcm_cli($fixture, 'probe.php')['stdout']);

	t_same('/movies/', $report['const_MCM_SESSION_COOKIE_PATH'], 'config.php wins over the default cookie path');
	t_same('Strict', $report['const_MCM_SESSION_COOKIE_SAMESITE'], 'config.php wins over the default SameSite');
	t_same('600', $report['const_MCM_SESSION_COOKIE_LIFETIME'], 'config.php wins over the default lifetime');
	t_same('Temporarily unavailable.', $report['const_MCM_GENERIC_ERROR_MESSAGE'], 'config.php wins over the default failure message');
	t_same('1209600', $report['const_COOKIE_RUNTIME'], 'settings the bootstrap does not own are left alone');

	// Every way the configuration can be unusable. In each case the exit status
	// says the request failed, the client is told nothing beyond the generic
	// message, and the reason is in the log instead.
	$broken = array(
		'missing' => array(
			'fixture' => array('config_file' => false),
			'logged'  => 'configuration file is missing or unreadable',
			'private' => 'config.php',
		),
		'a missing required setting' => array(
			'fixture' => array('config' => array('DB_NAME' => null)),
			'logged'  => 'configuration is unusable: DB_NAME (not defined)',
			'private' => 'DB_NAME',
		),
		'an empty required setting' => array(
			'fixture' => array('config' => array('DB_USER' => '')),
			'logged'  => 'configuration is unusable: DB_USER (empty)',
			'private' => 'DB_USER',
		),
		'a missing database password' => array(
			'fixture' => array('config' => array('DB_PASS' => null)),
			'logged'  => 'configuration is unusable: DB_PASS (not defined)',
			'private' => 'DB_PASS',
		),
		'a non-numeric cookie lifetime' => array(
			'fixture' => array('config' => array('COOKIE_RUNTIME' => 'two weeks')),
			'logged'  => 'configuration is unusable: COOKIE_RUNTIME (not a number)',
			'private' => 'COOKIE_RUNTIME',
		),
		'a config file that does not parse' => array(
			'fixture' => array('config_raw' => "<?php\n\ndefine('DB_HOST' 'localhost')\n"),
			'logged'  => 'syntax error',
			'private' => 'syntax error',
		),
	);

	$index = 0;
	foreach ($broken as $description => $case) {
		$fixture = mcm_fixture('config-broken-' . $index++, $case['fixture']);
		$result  = mcm_cli($fixture, 'probe.php');

		t_ok($result['status'] !== 0, 'configuration ' . $description . ': the request fails');
		t_same(mcm_generic_message(), $result['stdout'], 'configuration ' . $description . ': the client gets the generic message and nothing else');
		t_contains($case['logged'], $result['log'], 'configuration ' . $description . ': the reason is logged');
		t_lacks($case['private'], $result['stdout'], 'configuration ' . $description . ': the detail stays out of the response');
	}

	// An unreadable file is a separate path from a missing one, and only worth
	// asserting when the tests are not running as a user that ignores the mode.
	$fixture = mcm_fixture('config-unreadable', array('unreadable' => true));
	if (is_readable($fixture['public'] . '/inc/config/config.php')) {
		t_skip('an unreadable configuration is refused', 'this user can read a mode 0000 file');
	} else {
		$result = mcm_cli($fixture, 'probe.php');
		t_ok($result['status'] !== 0, 'configuration unreadable: the request fails');
		t_same(mcm_generic_message(), $result['stdout'], 'configuration unreadable: the client gets the generic message and nothing else');
		t_contains('configuration file is missing or unreadable', $result['log'], 'configuration unreadable: the reason is logged');
	}

	// A configuration file has to be inert unless the bootstrap is what loaded
	// it. The web server rules deny direct access already; the file does not
	// depend on them being in place.
	$fixture = mcm_fixture('config-direct-access');
	$server  = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/inc/config/example_config.php');
		t_same(403, $response['status'], 'a direct request for a config file is refused');
		t_same('Forbidden', $response['body'], 'a direct request for a config file reveals nothing');
	} catch (Exception $exception) {
		t_ok(false, 'the direct configuration access case ran', $exception->getMessage());
	}
	mcm_server_stop($server);
});

/*
 * ---------------------------------------------------------------------------
 * 2. One session startup, and a safe second include
 * ---------------------------------------------------------------------------
 */

t_group('single session startup', function () {
	$bootstrap = MCM_REPO_ROOT . '/inc/bootstrap.php';
	$source    = file_get_contents($bootstrap);

	// The file mentions session_start() in prose as well as calling it, which is
	// exactly why this check reads tokens rather than text.
	t_ok(substr_count($source, 'session_start(') > 1, 'the bootstrap mentions session_start() more than once as text');
	t_same(1, mcm_count_calls($bootstrap, 'session_start'), 'the bootstrap calls session_start() exactly once');

	$total = 0;
	$files = array();
	foreach (mcm_php_sources(MCM_REPO_ROOT) as $file) {
		$calls = mcm_count_calls($file, 'session_start');
		if ($calls > 0) {
			$files[] = substr($file, strlen(MCM_REPO_ROOT) + 1) . ' (' . $calls . ')';
		}
		$total += $calls;
	}
	t_same(1, $total, 'the whole application calls session_start() exactly once');
	t_same(array('inc/bootstrap.php (1)'), $files, 'the one call is the bootstrap');

	// Renaming the cookie would sign out every current visitor, so the name has
	// to stay at the server default: nothing may call session_name().
	$renames = 0;
	foreach (mcm_php_sources(MCM_REPO_ROOT) as $file) {
		$renames += mcm_count_calls($file, 'session_name');
	}
	t_same(0, $renames, 'nothing renames the session cookie');

	// A second entry into the file has to stop before it can do anything: the
	// marker is defined ahead of every side effect, and it is what config files
	// key off.
	$flat = mcm_flat_source($bootstrap);
	t_ok(
		strpos($flat, "define ( 'MCM_BOOTSTRAP'") < strpos($flat, 'session_start ('),
		'the marker is defined before the session is started'
	);
	t_ok(
		strpos($flat, "define ( 'MCM_BOOTSTRAP'") < strpos($flat, '$mcm_config_file'),
		'the marker is defined before the configuration is loaded'
	);

	// Including the bootstrap twice in one request has to be harmless.
	$fixture = mcm_fixture('double-include');
	$result  = mcm_cli($fixture, 'probe_double.php');
	$report  = mcm_report($result['stdout']);

	t_same(0, $result['status'], 'including the bootstrap twice exits cleanly');
	t_contains('double_include_survived=yes', $result['stdout'], 'the page after a second include still runs');
	t_same($report['session_id'], $report['session_id_before_second_include'], 'the second include leaves the session id alone');
	t_same((string) PHP_SESSION_ACTIVE, $report['session_status'], 'the session is still the one that was started');
	t_same('', $result['log'], 'a second include logs nothing, so no second session was started');

	$server = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe_double.php');
		t_same(200, $response['status'], 'a second include over HTTP still returns a page');
		t_same(1, count(mcm_header_values($response, 'Set-Cookie')), 'a second include issues exactly one session cookie');
		t_same('', $response['log'], 'a second include over HTTP logs nothing');
	} catch (Exception $exception) {
		t_ok(false, 'the double include case ran over HTTP', $exception->getMessage());
	}
	mcm_server_stop($server);
});

/*
 * ---------------------------------------------------------------------------
 * 3. Session cookie attributes, read off the wire
 * ---------------------------------------------------------------------------
 */

t_group('session cookie attributes', function () {
	$fixture = mcm_fixture('cookies');
	$server  = mcm_server_start($fixture);

	try {
		$response = mcm_http($server, '/probe.php');
		$cookies  = mcm_header_values($response, 'Set-Cookie');

		t_same(200, $response['status'], 'the probe page renders');
		t_same(1, count($cookies), 'exactly one session cookie is issued');

		$cookie = isset($cookies[0]) ? $cookies[0] : '';
		t_matches('/^PHPSESSID=/', $cookie, 'the cookie name is still the server default');
		t_matches('/;\s*path=\/(;|$)/i', $cookie, 'the cookie is scoped to the whole site');
		t_matches('/;\s*HttpOnly(;|$)/i', $cookie, 'the cookie is HttpOnly');
		t_matches('/;\s*SameSite=Lax(;|$)/i', $cookie, 'the cookie is SameSite=Lax');
		t_not_matches('/;\s*domain=/i', $cookie, 'the cookie carries no domain, so it stays host-only');
		t_not_matches('/;\s*secure(;|$)/i', $cookie, 'the cookie is not secure over plain HTTP');
		t_not_matches('/;\s*(expires|max-age)=/i', $cookie, 'the cookie expires with the browser session');

		// Both signals the bootstrap trusts for "this request was over HTTPS".
		$response = mcm_http($server, '/probe_https.php');
		$cookie   = implode('', mcm_header_values($response, 'Set-Cookie'));
		t_matches('/;\s*secure(;|$)/i', $cookie, 'the cookie is secure when the server reports HTTPS');

		$response = mcm_http($server, '/probe_https_port.php');
		$cookie   = implode('', mcm_header_values($response, 'Set-Cookie'));
		t_matches('/;\s*secure(;|$)/i', $cookie, 'the cookie is secure when the request arrived on the TLS port');

		// The captcha is the one include-tree file the browser requests directly,
		// so it has to come out of the same bootstrap intact.
		$response = mcm_http($server, '/inc/showCaptcha.php');
		t_same(200, $response['status'], 'the captcha endpoint renders');
		t_contains('image/png', implode('', mcm_header_values($response, 'Content-Type')), 'the captcha is still a PNG');
		t_same("\x89PNG", substr($response['body'], 0, 4), 'the captcha body is a real PNG');
		t_matches('/;\s*HttpOnly(;|$)/i', implode('', mcm_header_values($response, 'Set-Cookie')), 'the captcha gets the same session cookie');
		t_same('', $response['log'], 'the captcha logs nothing');
	} catch (Exception $exception) {
		t_ok(false, 'the cookie attribute cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	// A site that has finished moving to HTTPS can pin the flag on.
	$fixture = mcm_fixture('cookies-secure', array('config' => array(
		'MCM_SESSION_COOKIE_SECURE'   => true,
		'MCM_SESSION_COOKIE_PATH'     => '/movies/',
		'MCM_SESSION_COOKIE_SAMESITE' => 'Strict',
	)));
	$server = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe.php');
		$cookie   = implode('', mcm_header_values($response, 'Set-Cookie'));
		t_matches('/;\s*secure(;|$)/i', $cookie, 'a configured secure flag applies even over plain HTTP');
		t_matches('/;\s*path=\/movies\/(;|$)/i', $cookie, 'a configured cookie path reaches the response header');
		t_matches('/;\s*SameSite=Strict(;|$)/i', $cookie, 'a configured SameSite reaches the response header');
	} catch (Exception $exception) {
		t_ok(false, 'the configured-cookie cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);
});

/*
 * ---------------------------------------------------------------------------
 * 4. Sessions and remember-me cookies that already exist
 * ---------------------------------------------------------------------------
 */

t_group('existing session compatibility', function () {
	$fixture  = mcm_fixture('existing-sessions');
	$existing = '1234567890abcdef1234567890abcdef';
	$forged   = 'ffffffff00000000ffffffff00000000';

	mcm_seed_session($fixture, $existing, array('user_name' => 'already-signed-in', 'user_id' => 7));

	$server = mcm_server_start($fixture);
	try {
		// A visitor who was signed in before any of this shipped.
		$response = mcm_http($server, '/probe.php', array('Cookie: PHPSESSID=' . $existing));
		$report   = mcm_report($response['body']);

		t_same(200, $response['status'], 'an existing session still renders');
		t_same($existing, $report['session_id'], 'the existing session identifier is kept');
		t_contains('already-signed-in', $report['session_json'], 'the existing session data survives');
		t_same(0, count(mcm_header_values($response, 'Set-Cookie')), 'no new cookie is issued to a visitor who already has one');

		// An identifier the server never issued must not be adopted.
		$response = mcm_http($server, '/probe.php', array('Cookie: PHPSESSID=' . $forged));
		$report   = mcm_report($response['body']);
		$cookies  = mcm_header_values($response, 'Set-Cookie');

		t_same(200, $response['status'], 'a forged identifier still gets a page');
		t_ok($report['session_id'] !== $forged, 'a forged identifier is not adopted');
		t_same(1, count($cookies), 'a forged identifier is replaced with a freshly issued one');
		t_lacks($forged, implode('', $cookies), 'the forged identifier is not handed back');
		t_same('[]', $report['session_json'], 'a forged identifier carries no session data');

		// The remember-me cookie belongs to the login code, not the bootstrap.
		$remember = 'already-signed-in/aaaabbbbccccdddd/eeeeffff00001111';
		$response = mcm_http($server, '/probe.php', array('Cookie: rememberme=' . $remember . '; PHPSESSID=' . $existing));
		$report = mcm_report($response['body']);

		t_contains($remember, $report['cookies_json'], 'the remember-me cookie arrives unmodified');
		t_lacks('rememberme', implode('', mcm_header_values($response, 'Set-Cookie')), 'the remember-me cookie is never rewritten');
		t_same(0, count(mcm_header_values($response, 'Set-Cookie')), 'a request carrying both cookies is issued neither');
	} catch (Exception $exception) {
		t_ok(false, 'the existing-session cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);
});

/*
 * ---------------------------------------------------------------------------
 * 5. Error, exception and fatal handling
 * ---------------------------------------------------------------------------
 */

t_group('error handling', function () {
	$fixture = mcm_fixture('errors');
	$seed    = $fixture['seed'];
	$server  = mcm_server_start($fixture);

	// Each of these ends the request, and each takes a different route through
	// the bootstrap: the exception handler, the error handler, and - for the
	// compile-time fatal, which is not a Throwable - the shutdown handler. The
	// expected log label is what tells them apart, so it has to be the specific
	// one: a substring such as "error" matches almost any line, including the
	// wrong handler's.
	$fatal = array(
		'an uncaught exception'  => array('page' => 'fault_exception.php', 'logged' => 'Uncaught Exception'),
		'an uncaught Error'      => array('page' => 'fault_error.php', 'logged' => 'Uncaught Error'),
		'a file that does not compile' => array('page' => 'fault_fatal.php', 'logged' => 'Uncaught ParseError'),
		'a compile-time fatal'   => array('page' => 'fault_compile.php', 'logged' => 'Compile error'),
		'a fatal user error'     => array('page' => 'fault_user_error.php', 'logged' => 'User error'),
	);

	try {
		foreach ($fatal as $description => $case) {
			$response = mcm_http($server, '/' . $case['page']);

			t_same(500, $response['status'], $description . ': the response says the request failed');
			t_same(mcm_generic_message(), $response['body'], $description . ': the client gets the generic message and nothing else');
			t_lacks($seed, $response['body'], $description . ': the seeded secret never reaches the client');
			t_contains($seed, $response['log'], $description . ': the seeded secret is in the log');
			t_contains($case['logged'], $response['log'], $description . ': the failure is classified in the log');
			t_same(1, substr_count($response['body'], 'Sorry'), $description . ': exactly one failure body is sent');
		}

		t_contains('#0', mcm_http($server, '/fault_exception.php')['log'], 'an uncaught exception logs its stack trace');
		t_lacks('this line is never reached', mcm_http($server, '/fault_compile.php')['body'], 'a compile-time fatal stops the page');

		// The shutdown handler is the only one that sees a compile-time fatal,
		// so it gets its own assertions rather than sharing the table's.
		$response = mcm_http($server, '/fault_compile.php');
		t_contains('Cannot redeclare', $response['log'], 'the compile-time fatal is the duplicate declaration, not something else');
		t_lacks('Uncaught', $response['log'], 'the compile-time fatal never reaches the exception handler');
		t_lacks('Cannot redeclare', $response['body'], 'the shutdown handler tells the client nothing about the fatal');

		// A trace is logged for the frames it names, not for the arguments those
		// frames were called with: a login frame carries a visitor's password
		// and a connection frame carries the database's.
		$response = mcm_http($server, '/fault_trace_args.php');
		t_same(500, $response['status'], 'a failure inside a call with secret arguments fails the request');
		t_same(mcm_generic_message(), $response['body'], 'a failure inside a call with secret arguments says nothing to the client');
		t_contains('the database went away mid-login', $response['log'], 'the failure itself is logged');
		t_contains('mcm_signs_someone_in', $response['log'], 'the trace still names the frame that failed');
		t_lacks($seed, $response['log'], 'the arguments that frame was called with are not logged');

		// ... and the scrubber that covers runtimes which cannot drop them.
		$response = mcm_http($server, '/probe_scrub.php');
		t_contains('Login->loginWithPostData', $response['body'], 'scrubbing a trace keeps the frame');
		t_contains("'...'", $response['body'], 'scrubbing a trace replaces the arguments');
		t_lacks($seed, $response['body'], 'scrubbing a trace removes the secret argument');

		// A warning is not a reason to stop serving a page that used to work.
		$response = mcm_http($server, '/fault_warning.php');
		t_same(200, $response['status'], 'a warning does not fail the request');
		t_contains('request completed', $response['body'], 'a warning does not stop the page');
		t_contains('Warning', $response['log'], 'a warning is logged');
		t_contains($seed, $response['log'], 'the warning detail is logged');
		t_lacks($seed, $response['body'], 'the warning detail never reaches the client');

		// Diagnostics the code deliberately suppressed stay suppressed.
		$response = mcm_http($server, '/fault_suppressed.php');
		t_same(200, $response['status'], 'a suppressed diagnostic does not fail the request');
		t_contains('request completed', $response['body'], 'a suppressed diagnostic does not stop the page');
		t_same('', $response['log'], 'a suppressed diagnostic logs nothing');

		// The same page, twice, to show the configured level is what decides.
		$response = mcm_http($server, '/fault_user_warning.php');
		t_same(200, $response['status'], 'a user warning does not fail the request');
		t_contains($seed, $response['log'], 'a user warning is logged at the default level');
	} catch (Exception $exception) {
		t_ok(false, 'the error handling cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	$fixture = mcm_fixture('errors-filtered', array('config' => array(
		'MCM_ERROR_REPORTING' => E_ALL & ~E_USER_WARNING,
	)));
	$result = mcm_cli($fixture, 'fault_user_warning.php');
	t_same(0, $result['status'], 'a filtered level does not fail the request');
	t_contains('request completed', $result['stdout'], 'a filtered level does not stop the page');
	t_same('', $result['log'], 'a level the configuration filters out is not logged');
});

/*
 * ---------------------------------------------------------------------------
 * 6. Database failures
 * ---------------------------------------------------------------------------
 *
 * No database is involved in running these: an outage is simulated by pointing
 * the configuration at an address nothing is listening on, which is the same
 * thing the driver sees when the real server is down.
 */

t_group('database failure', function () {
	// Distinctive enough that finding it anywhere means it came from here.
	$password = 'db-password-must-never-be-logged';

	// Port 1 is privileged, so nothing in this suite can be listening on it and
	// the connection is refused immediately. DB_HOST reaches the DSN verbatim,
	// which is what lets a port be pinned without inventing a second setting.
	$fixture = mcm_fixture('db-outage', array('config' => array(
		'DB_HOST' => '127.0.0.1;port=1',
		'DB_PASS' => $password,
	)));

	$server = mcm_server_start($fixture);
	try {
		// 1. A page that cannot be served without the database.
		$response = mcm_http($server, '/probe_db.php');

		t_same(500, $response['status'], 'an outage: the response says the request failed');
		t_same(mcm_generic_message(), $response['body'], 'an outage: the client gets the generic message and nothing else');
		t_same(1, substr_count($response['body'], 'Sorry'), 'an outage: exactly one failure body is sent');
		t_lacks('the query would run here', $response['body'], 'an outage: the request stops before the query runs');

		// The failure modes the acceptance criteria name, one assertion each.
		$leaks = array(
			'SQLSTATE'           => 'no driver error code',
			'Connection refused' => 'no driver message',
			'mysql:host'         => 'no connection string',
			'127.0.0.1'          => 'no database address',
			$password            => 'no database password',
			'#0 '                => 'no stack trace',
			'bootstrap.php'      => 'no server-side path',
		);
		foreach ($leaks as $needle => $description) {
			t_lacks($needle, $response['body'], 'an outage: the response carries ' . $description);
		}

		// What the log has to keep, so an outage can still be diagnosed.
		t_contains('Database error', $response['log'], 'an outage: the log classifies the failure');
		t_contains('probe_db', $response['log'], 'an outage: the log names what the connection was for');
		t_contains('SQLSTATE[HY000] [2002]', $response['log'], "an outage: the log keeps the driver's own message");

		// ... and what it must not keep, however useful it would be.
		t_lacks($password, $response['log'], 'an outage: the password is not logged');
		t_lacks('mysql:host', $response['log'], 'an outage: the connection string is not logged');
		t_lacks('PDO->__construct', $response['log'], 'an outage: the connection frame is not logged');

		// 2. The failure must not turn into a different failure while being
		// handled. Carrying on with a connection that is not one used to raise
		// "Call to a member function prepare() on bool", which replaced the real
		// cause in the log and is the shape this case exists to rule out.
		t_lacks('Call to a member function', $response['log'], 'an outage: no second failure is raised while handling the first');
		t_contains('connection failed', $response['log'], 'an outage: the log holds the cause, not a knock-on error');
		t_lacks('Uncaught', $response['log'], 'an outage: the failure is handled, not left to the exception handler');

		// 3. The non-fatal shape, which the login and registration code needs.
		$response = mcm_http($server, '/probe_db_soft.php');

		t_same(200, $response['status'], 'a caller that handles the outage itself still gets a page');
		t_contains('connection=null', $response['body'], 'a failed connection is reported as null');
		t_contains('request completed', $response['body'], 'a failed connection does not stop the caller');
		t_contains('SQLSTATE[HY000] [2002]', $response['log'], 'a failed connection is logged anyway');
		t_lacks('SQLSTATE', $response['body'], 'a failed connection tells the client nothing');
		t_lacks($password, $response['body'], 'a failed connection does not put the password on the page');

		// 4. A real entry point, end to end. rename_list.php is the smallest of
		// them: two parameters, one connection, one query.
		$response = mcm_http($server, '/rename_list.php?movie_list_id=1&list_name=anything');

		t_same(500, $response['status'], 'a real page during an outage: the response says the request failed');
		t_same(mcm_generic_message(), $response['body'], 'a real page during an outage: the client gets the generic message and nothing else');
		t_lacks('SQLSTATE', $response['body'], 'a real page during an outage: no driver message reaches the client');
		t_lacks($password, $response['body'], 'a real page during an outage: no credential reaches the client');
		t_lacks('greatsuccess', $response['body'], 'a real page during an outage: the page does not claim it worked');
		t_lacks('UPDATE movie_lists', $response['body'], 'a real page during an outage: no query text reaches the client');
		t_contains('rename_list', $response['log'], 'a real page during an outage: the log names the page');
		t_contains('SQLSTATE[HY000] [2002]', $response['log'], 'a real page during an outage: the cause is logged');
		t_lacks('Call to a member function', $response['log'], 'a real page during an outage: no knock-on failure');

		// A page that never reaches the database is unaffected by the outage.
		$response = mcm_http($server, '/rename_list.php');
		t_same(200, $response['status'], 'a page that stops on its own input check is unaffected');
		t_contains('No movie list id given', $response['body'], 'the input check still answers');
	} catch (Exception $exception) {
		t_ok(false, 'the database failure cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	// The same failure from a command line process, where there are no headers
	// to send and the exit status is what reports it.
	$result = mcm_cli($fixture, 'probe_db.php');
	t_ok($result['status'] !== 0, 'an outage fails the request off the web as well');
	t_same(mcm_generic_message(), $result['stdout'], 'an outage off the web says nothing more either');

	/* Whole-source checks ---------------------------------------------------- */

	// One place builds a connection, so one place has to be careful with the
	// credentials - and it is the place whose failure handling is tested above.
	$connectors = array();
	$total      = 0;
	foreach (mcm_php_sources(MCM_REPO_ROOT) as $file) {
		$count = mcm_count_new($file, 'PDO');
		if ($count > 0) {
			$connectors[] = substr($file, strlen(MCM_REPO_ROOT) + 1) . ' (' . $count . ')';
		}
		$total += $count;
	}
	t_same(1, $total, 'the whole application opens a database connection in exactly one place');
	t_same(array('inc/bootstrap.php (1)'), $connectors, 'the one place is the bootstrap');

	// The password is read where the connection is opened and nowhere else.
	$readers = array();
	foreach (mcm_php_sources(MCM_REPO_ROOT) as $file) {
		if (mcm_count_constant_reads($file, 'DB_PASS') > 0) {
			$readers[] = substr($file, strlen(MCM_REPO_ROOT) + 1);
		}
	}
	t_same(array('inc/bootstrap.php'), $readers, 'the database password is read in one file only');

	// Nothing dumps a value into the response any more. This is the check that
	// would have caught the original var_dump($errors) lines.
	$dumpers = array();
	foreach (mcm_php_sources(MCM_REPO_ROOT) as $file) {
		$count = mcm_count_debug_output($file);
		if ($count > 0) {
			$dumpers[] = substr($file, strlen(MCM_REPO_ROOT) + 1) . ' (' . $count . ')';
		}
	}
	t_same(array(), $dumpers, 'no application code dumps a value into the response');

	// The checks above have to have teeth, so they are pointed at copies that
	// reintroduce exactly what was removed. Nothing here touches the checkout.
	$scratch = $fixture['public'] . '/regression_check.php';

	file_put_contents($scratch, "<?php\n\n// A page that talks about new PDO in a comment.\n\$x = 1;\n");
	t_same(0, mcm_count_new($scratch, 'PDO'), 'the connection check reads code, not comments');

	file_put_contents($scratch, "<?php\n\n\$db = new PDO('mysql:host=' . DB_HOST, DB_USER, DB_PASS);\n");
	t_same(1, mcm_count_new($scratch, 'PDO'), 'the connection check finds a hand-rolled connection');
	t_same(1, mcm_count_constant_reads($scratch, 'DB_PASS'), 'the password check finds a second reader');

	file_put_contents($scratch, "<?php\n\n// var_dump(\$errors) in a comment is not a call.\ndefine('DB_PASS', 'x');\n");
	t_same(0, mcm_count_debug_output($scratch), 'the debug-output check reads code, not comments');
	t_same(0, mcm_count_constant_reads($scratch, 'DB_PASS'), 'defining the password is not reading it');

	file_put_contents($scratch, "<?php\n\nvar_dump(\$errors);\nprint_r(\$errors);\n");
	t_same(2, mcm_count_debug_output($scratch), 'the debug-output check finds a reintroduced dump');

	unlink($scratch);
});

/*
 * ---------------------------------------------------------------------------
 * 7. Entry-point inclusion
 * ---------------------------------------------------------------------------
 */

t_group('entry point inclusion', function () {
	$entryPoints = mcm_entry_points(MCM_REPO_ROOT);

	t_ok(count($entryPoints) >= 19, 'the entry points were found', count($entryPoints) . ' found');
	t_ok(in_array(MCM_REPO_ROOT . '/index.php', $entryPoints, true), 'the document root is covered');
	t_ok(in_array(MCM_REPO_ROOT . '/inc/showCaptcha.php', $entryPoints, true), 'the captcha endpoint is covered');

	foreach ($entryPoints as $file) {
		$name     = substr($file, strlen(MCM_REPO_ROOT) + 1);
		$problems = mcm_check_entry_point($file, MCM_REPO_ROOT);
		t_same(array(), $problems, $name . ' loads the bootstrap first, once, with require_once');
	}

	// The check has to have teeth, so it is pointed at deliberately broken
	// copies. Nothing here touches the checkout.
	$fixture = mcm_fixture('entry-points');
	$broken  = array(
		'a page that never loads the bootstrap' => array(
			'source'  => "<?php\n\necho 'hello';\n",
			'problem' => 'does not include the bootstrap at all',
		),
		'a page that loads it after other code' => array(
			'source'  => "<?php\n\n\$x = 1;\nrequire_once(__DIR__ . '/inc/bootstrap.php');\n",
			'problem' => 'not the first statement',
		),
		'a page that loads it twice' => array(
			'source'  => "<?php\n\nrequire_once(__DIR__ . '/inc/bootstrap.php');\nrequire_once(__DIR__ . '/inc/bootstrap.php');\n",
			'problem' => 'includes the bootstrap 2 times',
		),
		'a page that uses require instead of require_once' => array(
			'source'  => "<?php\n\nrequire(__DIR__ . '/inc/bootstrap.php');\n",
			'problem' => 'non-once form',
		),
		// The page mentions __DIR__ after the include on purpose: a check that
		// scans the whole file instead of the include statement accepts this.
		'a page that relies on the include path' => array(
			'source'  => "<?php\n\nrequire_once('inc/bootstrap.php');\n\n\$template = __DIR__ . '/inc/views/logged_in.php';\n",
			'problem' => 'not anchored',
		),
	);

	foreach ($broken as $description => $case) {
		$path = $fixture['public'] . '/broken_entry_point.php';
		file_put_contents($path, $case['source']);
		$problems = mcm_check_entry_point($path, $fixture['public']);
		t_contains($case['problem'], implode(' | ', $problems), 'the check rejects ' . $description);
	}
	unlink($fixture['public'] . '/broken_entry_point.php');
});
