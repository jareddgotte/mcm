<?php

/**
 * The cases.
 *
 * The first eight groups match what the shared bootstrap is responsible for:
 * configuration, a single session startup, the session cookie's attributes,
 * compatibility with sessions and remember-me cookies that already exist, error
 * handling, where a redirect is allowed to send a visitor, what a database
 * failure does to a request, and every entry point loading the bootstrap.
 *
 * Three cover the shared security primitives in inc/security.php and the way
 * inc/classes/Login.php and inc/classes/Registration.php use them: token
 * generation, password compatibility, and renewing the session identifier
 * whenever a visitor's authentication state changes.
 *
 * Four cover inc/guards.php: what each guard allows, what it refuses, what a
 * refusal is allowed to say, and which endpoints have adopted it.
 *
 * One covers the endpoints that write a movie list: that an anonymous request
 * is refused before a connection is opened, and that a request which is not the
 * shape the page sends gets a bounded refusal rather than reaching a query.
 *
 * One group is optional and is the only one that needs anything beyond a PHP
 * CLI: given a database server binary it runs a private, throw-away server and
 * drives the authentication paths - and the three actors a list mutation has to
 * tell apart - against the tracked schema. Without one it skips loudly and says
 * what that leaves uncovered.
 *
 * The last two cover what the bootstrap's escaping helpers do with a hostile
 * string, and the bounded validation a submitted list name has to pass.
 *
 * The machinery they are written against lives in tests/run.php, and the
 * database group's own in tests/database.php.
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
		// PHP would have written the argument truncated to its first 15
		// characters, so that prefix is what proves it is gone.
		t_lacks(substr($seed, 0, 15), $response['log'], 'the arguments that frame was called with are not logged');

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
 * 6. Redirects, the canonical host, and HTTPS
 * ---------------------------------------------------------------------------
 *
 * The host a redirect names has to come from the configuration, never from the
 * request. "movies.example.test" below is the configured host; "attacker.example"
 * is what a request asks for, and must never come back.
 */

t_group('canonical host and https', function () {
	$canonical = 'movies.example.test';
	$origin    = 'https://' . $canonical;

	/* Whole-source checks ---------------------------------------------------- */

	// inc/libs/ is third-party code, and PHPMailer reads SERVER_NAME to name the
	// sending host in a mail header, which is not a redirect. Everything the
	// project itself wrote is covered.
	$ours = array();
	foreach (mcm_php_sources(MCM_REPO_ROOT) as $file) {
		if (strpos($file, MCM_REPO_ROOT . '/inc/libs/') !== 0) {
			$ours[] = $file;
		}
	}
	t_ok(count($ours) >= 20, 'the application sources were found', count($ours) . ' found');
	t_same(array(), mcm_request_derived_headers($ours, MCM_REPO_ROOT), 'no response header is built from the request host');

	// HSTS is deliberately not part of this: a browser remembers it for far
	// longer than the switch that turns the redirect off, and this site is
	// deployed manually.
	t_same(array(), mcm_hsts_sources(MCM_REPO_ROOT), 'nothing enables strict transport security');

	// Both checks are pointed at deliberately broken copies, because a check
	// that cannot fail proves nothing. Nothing here touches the checkout.
	$fixture = mcm_fixture('redirect-static');
	$broken  = $fixture['public'] . '/broken_redirect.php';

	file_put_contents($broken, "<?php\n\nheader('Location: http://' . \$_SERVER['HTTP_HOST'] . '/index.php');\n");
	t_same(
		array('broken_redirect.php: \'Location: http://\' . $_SERVER [ \'HTTP_HOST\' ] . \'/index.php\''),
		mcm_request_derived_headers(array($broken), $fixture['public']),
		'the check catches a redirect built from the request host'
	);

	// The same line as a comment is not a redirect, and neither is the constant
	// on its own. Both appear in the real sources, which is why this matters.
	file_put_contents($broken, "<?php\n\n// header('Location: http://' . \$_SERVER['HTTP_HOST'] . '/');\n\$host = \$_SERVER['HTTP_HOST'];\n");
	t_same(array(), mcm_request_derived_headers(array($broken), $fixture['public']), 'the check ignores a mention that sends no header');

	file_put_contents($fixture['public'] . '/.htaccess', "# Strict-Transport-Security stays out of this file\nOptions -Indexes\n");
	t_same(array(), mcm_hsts_sources($fixture['public']), 'the HSTS check ignores a comment about it');

	file_put_contents($fixture['public'] . '/.htaccess', "Header always set Strict-Transport-Security \"max-age=31536000\"\n");
	t_same(array('.htaccess'), mcm_hsts_sources($fixture['public']), 'the HSTS check catches a web-server rule that sets it');

	unlink($broken);
	unlink($fixture['public'] . '/.htaccess');

	/* What goes into a Location header ---------------------------------------- */

	// With a canonical host configured, every destination comes back absolute,
	// HTTPS, and on that host - whatever was handed in.
	$fixture = mcm_fixture('redirect-targets', array('config' => array(
		'MCM_CANONICAL_HOST' => $canonical,
		'MCM_FORCE_HTTPS'    => false,
	)));
	$result = mcm_cli($fixture, 'probe_redirect.php');
	$report = mcm_report($result['stdout']);

	t_same(0, $result['status'], 'the redirect probe runs');
	t_same($origin, $report['origin'], 'the canonical origin is the configured host over HTTPS');

	$targets = array(
		'target_root'              => $origin . '/',
		'target_page'              => $origin . '/index.php',
		'target_query'             => $origin . '/share.php?id=7',
		'target_subdir'            => $origin . '/movies',
		// A destination without a leading slash is still a destination here.
		'target_bare'              => $origin . '/index.php',
		'target_empty'             => $origin . '/',
		// The paths below arrive naming another site. Only the path survives.
		'target_absolute'          => $origin . '/index.php',
		'target_origin_only'       => $origin . '/',
		'target_protocol_relative' => $origin . '/attacker.example/index.php',
		'target_backslashed'       => $origin . '/attacker.example/index.php',
	);
	foreach ($targets as $name => $expected) {
		t_same($expected, isset($report[$name]) ? $report[$name] : '(absent)', 'redirect target: ' . substr($name, 7));
	}

	// Whatever the destination, the visitor cannot be sent off this site, and
	// nothing can be smuggled into the header after it.
	foreach ($report as $name => $value) {
		if (strpos($name, 'target_') !== 0) {
			continue;
		}
		t_ok(strpos($value, $origin . '/') === 0, 'redirect target ' . substr($name, 7) . ' stays on the canonical origin', $value);
	}
	t_lacks('X-Injected', $report['target_injected_header'], 'a destination cannot smuggle a second header in');
	t_same($origin . '/index.php', $report['target_injected_header'], 'a destination stops at the first control character');

	// With no canonical host the site keeps working: the destinations are the
	// same places, named relative to whatever host the visitor is on, and the
	// request still cannot choose the host.
	$fixture = mcm_fixture('redirect-relative');
	$report  = mcm_report(mcm_cli($fixture, 'probe_redirect.php')['stdout']);

	t_same('', $report['origin'], 'no configured host means no origin');
	t_same('no', $report['enforced'], 'HTTPS is not enforced without a host to enforce it to');
	t_same('/index.php', $report['target_page'], 'a destination stays a path on the current host');
	t_same('/share.php?id=7', $report['target_query'], 'a relative destination keeps its query');
	t_same('/index.php', $report['target_absolute'], 'a destination naming another site loses it');
	t_same('/attacker.example/index.php', $report['target_protocol_relative'], 'a protocol-relative destination becomes a path');
	foreach ($report as $name => $value) {
		if (strpos($name, 'target_') === 0) {
			t_ok(substr($value, 0, 2) !== '//', 'relative target ' . substr($name, 7) . ' cannot be read as another origin', $value);
		}
	}

	// A canonical host written as a URL means the same thing as a bare one.
	$fixture = mcm_fixture('redirect-host-forms', array('config' => array(
		'MCM_CANONICAL_HOST' => 'https://' . $canonical . '/',
		'MCM_FORCE_HTTPS'    => false,
	)));
	$report = mcm_report(mcm_cli($fixture, 'probe_redirect.php')['stdout']);
	t_same($origin, $report['origin'], 'a canonical host written as a URL is understood');

	$fixture = mcm_fixture('redirect-host-path', array('config' => array(
		'MCM_CANONICAL_HOST' => $canonical . '/movies/',
		'MCM_FORCE_HTTPS'    => false,
	)));
	$report = mcm_report(mcm_cli($fixture, 'probe_redirect.php')['stdout']);
	t_same($origin, $report['origin'], 'a path written after the canonical host is dropped');

	// One that cannot be a host is refused rather than used, and the site stays
	// up on relative redirects while the log says what is wrong.
	$fixture = mcm_fixture('redirect-host-invalid', array('config' => array(
		'MCM_CANONICAL_HOST' => 'not a host name',
	)));
	$result = mcm_cli($fixture, 'probe_redirect.php');
	$report = mcm_report($result['stdout']);
	t_same(0, $result['status'], 'an unusable canonical host does not break the page');
	t_same('', $report['origin'], 'an unusable canonical host is not used');
	t_same('/index.php', $report['target_page'], 'an unusable canonical host falls back to a path');
	t_contains('MCM_CANONICAL_HOST is not a usable host name', $result['log'], 'an unusable canonical host is logged');

	/* The enforcement path, over the wire -------------------------------------- */

	$fixture = mcm_fixture('https-forced', array('config' => array(
		'MCM_CANONICAL_HOST' => $canonical,
	)));
	$server = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe.php');
		t_same(302, $response['status'], 'a plain HTTP request is redirected');
		t_same(array($origin . '/probe.php'), mcm_header_values($response, 'Location'), 'the redirect goes to the same address over HTTPS');
		t_same(0, count(mcm_header_values($response, 'Set-Cookie')), 'no session cookie is issued over plain HTTP');
		t_same(array(), mcm_header_values($response, 'Strict-Transport-Security'), 'the redirect carries no HSTS header');
		t_same('', $response['log'], 'the redirect logs nothing');

		$response = mcm_http($server, '/probe.php?list=7&sort=title');
		t_same(array($origin . '/probe.php?list=7&sort=title'), mcm_header_values($response, 'Location'), 'the redirect keeps the query string');

		// The whole point: the request asks to be sent somewhere else and is not.
		$response = mcm_http($server, '/probe.php', array('Host: attacker.example'));
		t_same(array($origin . '/probe.php'), mcm_header_values($response, 'Location'), 'a spoofed Host header does not change the destination');
		t_lacks('attacker.example', implode(' ', mcm_header_values($response, 'Location')), 'the spoofed host is not handed back');

		// A form submission has to keep its method, or the visitor loses what
		// they typed.
		$response = mcm_http($server, '/probe.php', array(), 'POST');
		t_same(307, $response['status'], 'a form submission is redirected without changing method');
		t_same(array($origin . '/probe.php'), mcm_header_values($response, 'Location'), 'a form submission is redirected to HTTPS');

		// Permanence is what makes a redirect hard to take back.
		t_ok(mcm_http($server, '/probe.php')['status'] !== 301, 'the redirect is not a permanent one');

		// The captcha is requested by the browser directly and goes the same way.
		$response = mcm_http($server, '/inc/showCaptcha.php');
		t_same(302, $response['status'], 'the captcha endpoint is redirected too');
		t_same(array($origin . '/inc/showCaptcha.php'), mcm_header_values($response, 'Location'), 'the captcha redirect keeps its address');

		// A request that already arrived over HTTPS is served, not redirected.
		$response = mcm_http($server, '/probe_https.php');
		t_same(200, $response['status'], 'an HTTPS request is served rather than redirected');
		t_contains('bootstrap=defined', $response['body'], 'an HTTPS request still gets its page');
		t_same(1, count(mcm_header_values($response, 'Set-Cookie')), 'an HTTPS request still gets its session cookie');

		// The forwarded header is a client-supplied claim, and is not believed
		// unless the configuration says there is a proxy setting it.
		$response = mcm_http($server, '/probe.php', array('X-Forwarded-Proto: https'));
		t_same(302, $response['status'], 'a forwarded-protocol header is ignored by default');
	} catch (Exception $exception) {
		t_ok(false, 'the HTTPS enforcement cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	// The command line has no request to redirect, so the suite's own CLI cases
	// and any maintenance script keep working.
	$report = mcm_report(mcm_cli($fixture, 'probe_redirect.php')['stdout']);
	t_same('yes', $report['enforced'], 'enforcement is on for this fixture');
	t_same($origin . '/index.php', $report['target_page'], 'a command line run is not redirected');

	/* Turning it off ----------------------------------------------------------- */

	// The way back, if HTTPS turns out wrong on the live site: one line in the
	// configuration, no code change.
	$fixture = mcm_fixture('https-switched-off', array('config' => array(
		'MCM_CANONICAL_HOST' => $canonical,
		'MCM_FORCE_HTTPS'    => false,
	)));
	$server = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe.php');
		t_same(200, $response['status'], 'MCM_FORCE_HTTPS false serves plain HTTP again');
		t_contains('bootstrap=defined', $response['body'], 'the page comes back after enforcement is switched off');
		t_same(array(), mcm_header_values($response, 'Location'), 'nothing is redirected once enforcement is off');

		// The host in a redirect is still the configured one: switching
		// enforcement off does not put the request back in charge.
		$response = mcm_http($server, '/probe_redirect.php', array('Host: attacker.example'));
		t_contains('target_page=' . $origin . '/index.php', $response['body'], 'redirects still name the configured host');
		t_lacks('//attacker.example', $response['body'], 'the request host is still never an origin');
	} catch (Exception $exception) {
		t_ok(false, 'the switched-off cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	// MCM_FORCE_HTTPS on its own cannot enforce anything: there is no HTTPS
	// address to send anyone to that the request did not supply.
	$fixture = mcm_fixture('https-forced-without-host', array('config' => array(
		'MCM_FORCE_HTTPS' => true,
	)));
	$server = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe.php');
		t_same(200, $response['status'], 'enforcement without a canonical host serves the page');
		t_contains('MCM_FORCE_HTTPS is on but MCM_CANONICAL_HOST is not set', $response['log'], 'enforcement without a canonical host is logged');
	} catch (Exception $exception) {
		t_ok(false, 'the enforcement-without-a-host case ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	/* A proxy that terminates TLS ---------------------------------------------- */

	// Where the web server never sees the TLS itself, believing the proxy is
	// the difference between a working site and an endless redirect.
	$fixture = mcm_fixture('https-forwarded', array('config' => array(
		'MCM_CANONICAL_HOST'        => $canonical,
		'MCM_TRUST_FORWARDED_PROTO' => true,
	)));
	$server = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe.php', array('X-Forwarded-Proto: https'));
		t_same(200, $response['status'], 'a trusted proxy reporting HTTPS is served, not redirected');

		$response = mcm_http($server, '/probe.php', array('X-Forwarded-Proto: http'));
		t_same(302, $response['status'], 'a trusted proxy reporting plain HTTP is still redirected');
	} catch (Exception $exception) {
		t_ok(false, 'the forwarded-protocol cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	/* The application route this started with ---------------------------------- */

	// share.php with no list on it has always redirected to the directory it
	// lives in. It still does; it just no longer lets the request pick the host.
	$fixture = mcm_fixture('share-redirect');
	$server  = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/share.php', array('Host: attacker.example'));
		t_same(302, $response['status'], 'a share link with no list still redirects');
		t_same(array('/'), mcm_header_values($response, 'Location'), 'the share redirect still goes to the site root');
		t_lacks('attacker.example', implode(' ', mcm_header_values($response, 'Location')), 'the share redirect cannot be pointed at another site');
	} catch (Exception $exception) {
		t_ok(false, 'the share redirect case ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	$fixture = mcm_fixture('share-redirect-canonical', array('config' => array(
		'MCM_CANONICAL_HOST' => $canonical,
		'MCM_FORCE_HTTPS'    => false,
	)));
	$server = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/share.php', array('Host: attacker.example'));
		t_same(array($origin . '/'), mcm_header_values($response, 'Location'), 'the share redirect uses the configured host over HTTPS');
	} catch (Exception $exception) {
		t_ok(false, 'the canonical share redirect case ran', $exception->getMessage());
	}
	mcm_server_stop($server);
});

/*
 * ---------------------------------------------------------------------------
 * 7. Database failures
 * ---------------------------------------------------------------------------
 *
 * No database is involved in running these: an outage is simulated by pointing
 * the configuration at an address nothing is listening on, which is the same
 * thing the driver sees when the real server is down.
 */

t_group('database failure', function () {
	// Distinctive enough that finding it anywhere means it came from here, and
	// short on purpose: PHP truncates a string argument in a stack trace at 15
	// characters, so a long password would go missing from a trace that did in
	// fact leak it and the assertions below would pass for the wrong reason.
	$password = 'pw-never-log-1';

	// Port 1 is privileged, so nothing in this suite can be listening on it and
	// the connection is refused immediately. DB_HOST reaches the DSN verbatim,
	// which is what lets a port be pinned without inventing a second setting.
	$fixture = mcm_fixture('db-outage', array('config' => array(
		'DB_HOST' => '127.0.0.1;port=1',
		'DB_PASS' => $password,
	)));

	// The real entry point below is a list endpoint, and a list endpoint refuses
	// a request with nobody behind it before it ever opens a connection. Being
	// signed in is what lets this group reach the outage it is about.
	$signed_in = 'd0d0d0d0d0d0d0d0d0d0d0d0d0d0d0d0';
	mcm_seed_signed_in($fixture, $signed_in, array('user_name' => 'outage-user', 'user_id' => 4, 'user_logged_in' => 1));
	$as_signed_in = mcm_session_headers($signed_in);

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
		// them: two parameters, one connection, one query. It is a POST carrying
		// this session's token, because that is now the only kind of request it
		// serves - which is what lets it get as far as the outage.
		$response = mcm_http_post($server, '/rename_list.php', array('movie_list_id' => 1, 'list_name' => 'anything'), $as_signed_in);

		t_same(500, $response['status'], 'a real page during an outage: the response says the request failed');
		t_same(mcm_generic_message(), $response['body'], 'a real page during an outage: the client gets the generic message and nothing else');
		t_lacks('SQLSTATE', $response['body'], 'a real page during an outage: no driver message reaches the client');
		t_lacks($password, $response['body'], 'a real page during an outage: no credential reaches the client');
		t_lacks('greatsuccess', $response['body'], 'a real page during an outage: the page does not claim it worked');
		t_lacks('UPDATE movie_lists', $response['body'], 'a real page during an outage: no query text reaches the client');
		t_contains('rename_list', $response['log'], 'a real page during an outage: the log names the page');
		t_contains('SQLSTATE[HY000] [2002]', $response['log'], 'a real page during an outage: the cause is logged');
		t_lacks('Call to a member function', $response['log'], 'a real page during an outage: no knock-on failure');

		// 5. A statement that fails once it is running - the other half of the
		// helper, and the half the old code turned into "Execute error: ..." on
		// the page. Driver-independent, so SQLite stands in for the real server.
		if (!extension_loaded('pdo_sqlite')) {
			t_skip('a failing query', 'this PHP has no pdo_sqlite to stand in for the database');
		} else {
			// A statement that works is left alone: the helper reports success,
			// the row lands, and the page finishes.
			$response = mcm_http($server, '/probe_db_query.php?mode=ok');
			t_same(200, $response['status'], 'a query that works still renders');
			t_contains('result=true', $response['body'], 'a query that works reports success');
			t_contains('rows=2', $response['body'], 'a query that works still writes its row');
			t_contains('the page carried on', $response['body'], 'a query that works does not stop the page');
			t_same('', $response['log'], 'a query that works logs nothing');

			foreach (array('exceptions' => '', 'a silent driver' => '?mode=silent') as $mode => $query) {
				$response = mcm_http($server, '/probe_db_query.php' . $query);

				t_same(500, $response['status'], 'a failing query with ' . $mode . ': the response says the request failed');
				t_same(mcm_generic_message(), $response['body'], 'a failing query with ' . $mode . ': the client gets the generic message and nothing else');
				t_lacks('the page carried on', $response['body'], 'a failing query with ' . $mode . ': the request stops there');
				t_lacks('UNIQUE constraint', $response['body'], 'a failing query with ' . $mode . ': no driver message reaches the client');
				t_lacks('INSERT INTO', $response['body'], 'a failing query with ' . $mode . ': no query text reaches the client');

				t_contains('Database error', $response['log'], 'a failing query with ' . $mode . ': the log classifies the failure');
				t_contains('name that is already taken', $response['log'], 'a failing query with ' . $mode . ': the log names what the query was for');
				t_contains('UNIQUE constraint failed', $response['log'], 'a failing query with ' . $mode . ": the log keeps the driver's own message");
				t_contains('[query: INSERT INTO mcm_probe', $response['log'], 'a failing query with ' . $mode . ': the log keeps the statement');
			}
		}

		// A page that never reaches the database is unaffected by the outage. The
		// name is validated before the connection is opened, so a name the page
		// refuses is answered the same during an outage as at any other time.
		$response = mcm_http_post($server, '/rename_list.php', array('movie_list_id' => 1, 'list_name' => str_repeat('a', 65)), $as_signed_in);
		t_same(200, $response['status'], 'a page that stops on its own input check is unaffected');
		t_contains('List name is longer than 64 characters', $response['body'], 'the input check still answers');
		t_same('', $response['log'], 'the input check answered without reaching the outage');

		// And a request with nobody behind it stops earlier still: the guard
		// refuses it, and no connection is attempted for it either.
		$response = mcm_http_post($server, '/rename_list.php', array('movie_list_id' => 1, 'list_name' => 'anything'));
		t_same(401, $response['status'], 'an anonymous request stops before the connection');
		t_lacks('SQLSTATE', $response['log'], 'an anonymous request never reaches the outage');

		// So does one that is signed in but carries no token, and one that is
		// not a POST at all. Neither opens a connection either, which is the
		// whole of "refused without changing data" on a fixture with no
		// database to change.
		$response = mcm_http_post($server, '/rename_list.php', array('movie_list_id' => 1, 'list_name' => 'anything'), array('Cookie: PHPSESSID=' . $signed_in));
		t_same(403, $response['status'], 'a request with no token stops before the connection');
		t_lacks('SQLSTATE', $response['log'], 'a request with no token never reaches the outage');

		$response = mcm_http($server, '/rename_list.php?movie_list_id=1&list_name=anything', $as_signed_in);
		t_same(405, $response['status'], 'a GET stops before the connection');
		t_lacks('SQLSTATE', $response['log'], 'a GET never reaches the outage');
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
 * 8. Entry-point inclusion
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

/*
 * ---------------------------------------------------------------------------
 * 9. Tokens: how they are generated, compared, and carried in a cookie
 * ---------------------------------------------------------------------------
 */

t_group('secure tokens', function () {
	// COOKIE_SECRET_KEY is spelled out rather than left to the fixture default,
	// because this group builds a remember-me cookie by hand and has to hash it
	// with the same key the fixture runs on.
	$secret  = 'test-cookie-secret';
	$fixture = mcm_fixture('tokens', array('config' => array('COOKIE_SECRET_KEY' => $secret)));
	$result  = mcm_cli($fixture, 'probe_tokens.php');
	$report  = mcm_report($result['stdout']);

	t_same(0, $result['status'], 'the token page exits cleanly');
	t_same('', $result['log'], 'generating tokens logs nothing');

	// Lengths are in characters because that is what the columns holding them
	// are measured in: 64 for users.user_rememberme_token, 40 for
	// user_activation_hash and user_password_reset_hash. A token that outgrew
	// its column would be cut short on the way into the database and never
	// match again.
	$lengths = array(
		'token_a'       => 64,
		'token_b'       => 64,
		'token_c'       => 64,
		'token_default' => 64,
		'token_40'      => 40,
		// An odd length is rounded up, so a token always comes from whole bytes.
		'token_odd'     => 8,
		'token_zero'    => 2,
	);
	foreach ($lengths as $name => $length) {
		t_same($length, strlen($report[$name]), $name . ' is ' . $length . ' characters long');
		t_matches('/^[0-9a-f]+$/', $report[$name], $name . ' is lowercase hexadecimal, so it fits the column it is stored in');
	}
	t_same('16', $report['bytes_length'], 'the byte source returns as many bytes as it was asked for');

	$tokens = array($report['token_a'], $report['token_b'], $report['token_c']);
	t_same(3, count(array_unique($tokens)), 'three tokens from one request are three different tokens');

	// Different requests too, which is where a generator seeded once per process
	// would give itself away.
	$across = array();
	for ($request = 0; $request < 4; $request++) {
		$another  = mcm_report(mcm_cli($fixture, 'probe_tokens.php')['stdout']);
		$across[] = $another['token_a'];
	}
	t_same(4, count(array_unique($across)), 'tokens from separate requests are all different');

	// Constant-time comparison still has to be a correct comparison.
	$equality = array(
		'equals_same'      => 'yes',
		'equals_different' => 'no',
		'equals_prefix'    => 'no',
		'equals_longer'    => 'no',
		'equals_empty'     => 'yes',
		'equals_missing'   => 'no',
		'equals_token'     => 'yes',
		'equals_flipped'   => 'no',
	);
	foreach ($equality as $name => $expected) {
		t_same($expected, $report[$name], 'comparison: ' . substr($name, 7) . ' is ' . $expected);
	}

	// Pin PHP's pseudo-random generator and ask twice. A token built on
	// mt_rand(), whatever is done to the value afterwards, comes out identical
	// both times.
	$first  = mcm_report(mcm_cli($fixture, 'probe_seeded_token.php')['stdout']);
	$second = mcm_report(mcm_cli($fixture, 'probe_seeded_token.php')['stdout']);

	t_same($first['legacy_token'], $second['legacy_token'], 'the fixed seed does pin the pseudo-random generator, so the next case means something');
	t_ok($first['token'] !== $second['token'], 'a token does not follow from the seeded pseudo-random generator');
	t_ok($first['token'] !== $first['legacy_token'], 'a token is not the value the previous implementation would have produced');

	// A remember-me cookie exactly as the site issued them before this change:
	// a token from the old sha256(mt_rand()) generator, hashed here rather than
	// through the code being tested.
	$legacy_token  = hash('sha256', '1804289383');
	$legacy_cookie = '7:' . $legacy_token . ':' . hash('sha256', '7:' . $legacy_token . $secret);
	$report        = mcm_report(mcm_cli($fixture, 'probe_rememberme.php', array('MCM_TEST_COOKIE' => $legacy_cookie))['stdout']);

	t_same('yes', $report['valid'], 'a remember-me cookie issued before this change is still accepted');
	t_same('7', $report['user_id'], 'the user id is read out of an existing cookie');
	t_same($legacy_token, $report['token'], 'the token is read out of an existing cookie unchanged, so the stored one still matches it');

	t_same('yes', $report['fresh_valid'], 'a cookie issued now is accepted');
	t_same('yes', $report['fresh_roundtrip'], 'a cookie issued now reads back as what went into it');
	t_matches('/^7:[0-9a-f]{64}:[0-9a-f]{64}$/', $report['fresh_cookie'], 'a cookie issued now has the same shape as the ones already in browsers');

	$refusals = array(
		'tampered_hash_valid'  => 'a cookie whose hash was altered is refused',
		'tampered_token_valid' => 'a cookie whose token was altered is refused',
		'malformed_valid'      => 'a cookie that is not in the expected format is refused',
		'two_part_valid'       => 'a cookie missing its hash is refused',
		'empty_token_valid'    => 'a correctly hashed cookie with no token is refused',
	);
	foreach ($refusals as $name => $description) {
		t_same('no', $report[$name], $description);
	}

	// The generators that used to produce these values are gone from the files
	// that issue them.
	$login        = MCM_REPO_ROOT . '/inc/classes/Login.php';
	$registration = MCM_REPO_ROOT . '/inc/classes/Registration.php';
	$security     = MCM_REPO_ROOT . '/inc/security.php';

	foreach (array($login, $registration, $security) as $file) {
		$name = substr($file, strlen(MCM_REPO_ROOT) + 1);
		foreach (array('mt_rand', 'rand', 'uniqid') as $legacy) {
			t_same(0, mcm_count_calls($file, $legacy), $name . ' does not call ' . $legacy . '()');
		}
	}
	t_same(1, mcm_count_calls($security, 'random_bytes'), 'the generator is built on random_bytes()');

	// Each kind of token comes from the shared generator, in the method that
	// issues it.
	t_same(1, mcm_method_calls($login, 'newRememberMeCookie', 'mcm_random_token'), 'the remember-me token comes from the shared generator');
	t_same(1, mcm_method_calls($login, 'setPasswordResetDatabaseTokenAndSendMail', 'mcm_random_token'), 'the password reset token comes from the shared generator');
	t_same(1, mcm_method_calls($registration, 'registerNewUser', 'mcm_random_token'), 'the activation token comes from the shared generator');

	// And each check of a token against one from the request is constant-time.
	t_same(1, mcm_method_calls($login, 'checkIfEmailVerificationCodeIsValid', 'mcm_hash_equals'), 'the password reset code is compared in constant time');
	t_same(1, mcm_method_calls($login, 'loginWithCookieData', 'mcm_remember_me_cookie_parts'), 'the remember-me cookie is checked through the shared, constant-time reader');
	t_same(1, mcm_count_calls($security, 'hash_equals'), 'the constant-time comparison is PHP\'s own where it exists');
});

/*
 * ---------------------------------------------------------------------------
 * 10. Passwords stored before this change, and rehashing them quietly
 * ---------------------------------------------------------------------------
 */

t_group('password compatibility', function () {
	// Hashes the site itself wrote, kept here as literals: an account whose
	// owner has not signed in since has exactly this in its row. Nothing about
	// them may stop working.
	$password = 'correct horse battery staple';
	$cost_10  = '$2y$10$RLh/QrnMk2TZ/3G5qtm2uuLYc7WwgcNnhTppI0R.c8/b52LIFGCgK';
	$cost_04  = '$2y$04$xjHsQvxzWpnzTPVko1JOn.ujh4KjOGjNXjsgwt0AeKyb7bottCsWe';

	// The cost factor the shipped example configuration carries, as a string.
	$fixture = mcm_fixture('passwords', array('config' => array('HASH_COST_FACTOR' => '10')));
	$result  = mcm_cli($fixture, 'probe_passwords.php', array(
		'MCM_TEST_PASSWORD' => $password,
		'MCM_TEST_HASH'     => $cost_10,
	));
	$report = mcm_report($result['stdout']);

	t_same(0, $result['status'], 'the password page exits cleanly');
	t_same('', $result['log'], 'checking a password logs nothing');
	t_same('{"cost":10}', $report['options'], 'the configured cost factor is what hashing uses');
	t_same('yes', $report['verify'], 'a password stored before this change still signs its owner in');
	t_same('no', $report['verify_wrong'], 'the wrong password is still refused');
	t_same('no', $report['verify_empty_hash'], 'an account with no stored hash cannot be signed into');
	t_same('no', $report['verify_garbage_hash'], 'an unreadable stored hash cannot be signed into');
	t_same('no', $report['needs_rehash'], 'a hash that already matches the configured cost is left alone');
	t_same('no', $report['needs_rehash_empty'], 'an empty stored hash is not something to recalculate');

	// The same password, hashed with a weaker cost factor. This is what
	// opportunistic rehashing exists for.
	$report = mcm_report(mcm_cli($fixture, 'probe_passwords.php', array(
		'MCM_TEST_PASSWORD' => $password,
		'MCM_TEST_HASH'     => $cost_04,
	))['stdout']);

	t_same('yes', $report['verify'], 'a hash written with a weaker cost factor still signs its owner in');
	t_same('yes', $report['needs_rehash'], 'a weaker hash is picked out to be recalculated');
	t_same('yes', $report['stored_still_verifies'], 'the stored hash keeps working while the new one is being written');
	t_same('yes', $report['recalculated_differs'], 'the recalculated hash is a new one');
	t_same('yes', $report['recalculated_verify'], 'the recalculated hash accepts the same password, so nobody is asked to reset anything');
	t_same('no', $report['recalculated_wrong'], 'the recalculated hash still refuses the wrong password');
	t_same('no', $report['recalculated_needs_rehash'], 'the recalculated hash settles, so a login does not rewrite the row every time');
	t_matches('/^\$2y\$10\$/', $report['recalculated'], 'the recalculated hash carries the configured cost factor');

	// Raising the cost factor is the other way an existing hash becomes one to
	// recalculate.
	$fixture = mcm_fixture('passwords-costlier', array('config' => array('HASH_COST_FACTOR' => '12')));
	$report  = mcm_report(mcm_cli($fixture, 'probe_passwords.php', array(
		'MCM_TEST_PASSWORD' => $password,
		'MCM_TEST_HASH'     => $cost_10,
	))['stdout']);

	t_same('{"cost":12}', $report['options'], 'a raised cost factor reaches the hashing');
	t_same('yes', $report['verify'], 'raising the cost factor does not stop existing passwords verifying');
	t_same('yes', $report['needs_rehash'], 'raising the cost factor marks existing hashes for recalculation');
	t_matches('/^\$2y\$12\$/', $report['recalculated'], 'the recalculated hash uses the raised cost factor');
	t_same('yes', $report['recalculated_verify'], 'the recalculated hash accepts the same password');

	// A configuration that never defined a cost factor, and ones that define an
	// unusable value: all fall back to PHP's own default rather than failing.
	$unusable = array(
		'no cost factor at all' => null,
		'a cost factor that is not a number' => 'ten',
		'a cost factor outside the accepted range' => '99',
	);
	$index = 0;
	foreach ($unusable as $description => $value) {
		$fixture = mcm_fixture('passwords-default-' . $index++, array('config' => array('HASH_COST_FACTOR' => $value)));
		$result  = mcm_cli($fixture, 'probe_passwords.php', array(
			'MCM_TEST_PASSWORD' => $password,
			'MCM_TEST_HASH'     => $cost_10,
		));
		$report = mcm_report($result['stdout']);

		t_same('[]', $report['options'], $description . ': hashing falls back to PHP\'s own default');
		t_same('yes', $report['verify'], $description . ': existing passwords still verify');
		t_same('yes', $report['recalculated_verify'], $description . ': a newly calculated hash accepts the password');
		t_same('', $result['log'], $description . ': nothing is logged');
	}
});

/*
 * ---------------------------------------------------------------------------
 * 11. Renewing the session identifier when the visitor's state changes
 * ---------------------------------------------------------------------------
 */

t_group('session identifier renewal', function () {
	$fixture  = mcm_fixture('regenerate');
	$existing = 'aaaabbbbccccddddeeeeffff00001111';
	$for_late = '11110000ffffeeeeddddccccbbbbaaaa';

	// Two visitors who were signed in before any of this shipped.
	mcm_seed_session($fixture, $existing, array('user_name' => 'already-signed-in', 'user_id' => 7));
	mcm_seed_session($fixture, $for_late, array('user_name' => 'already-signed-in', 'user_id' => 7));

	$server = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe_regenerate.php', array('Cookie: PHPSESSID=' . $existing));
		$report   = mcm_report($response['body']);
		$cookies  = implode(' ', mcm_header_values($response, 'Set-Cookie'));

		t_same(200, $response['status'], 'a page that authenticates a visitor renders');
		t_same($existing, $report['session_id_before'], 'the request started on the identifier the browser sent');
		t_same('yes', $report['regenerated'], 'the transition renews the session identifier');
		t_ok($report['session_id'] !== $existing, 'the identifier the session ends on is a different one');
		t_matches('/^[A-Za-z0-9,-]+$/', $report['session_id'], 'the session still has an identifier afterwards');
		t_contains('transitioning-user', $report['session_json'], 'the session data survives the renewal, so the visitor stays signed in');
		t_contains('PHPSESSID=' . $report['session_id'], $cookies, 'the browser is told to keep the new identifier');
		t_lacks($existing, $cookies, 'the identifier the visitor arrived with is not handed back');
		t_same('', $response['log'], 'renewing the identifier logs nothing');

		// The point of the renewal: the identifier an attacker could have
		// planted beforehand stops working, rather than becoming a signed-in
		// one.
		$files = mcm_session_files($fixture);
		t_ok(in_array($report['session_id'], $files, true), 'the renewed session is the one held on the server');
		t_ok(!in_array($existing, $files, true), 'the session the old identifier pointed at is gone');

		$response = mcm_http($server, '/probe.php', array('Cookie: PHPSESSID=' . $existing));
		$after    = mcm_report($response['body']);
		t_ok($after['session_id'] !== $existing, 'the old identifier is not adopted again afterwards');
		t_same('[]', $after['session_json'], 'the old identifier carries no signed-in data any more');

		// A visitor who had no session at all when the transition happened.
		$response = mcm_http($server, '/probe_regenerate.php');
		$report   = mcm_report($response['body']);
		$cookies  = implode(' ', mcm_header_values($response, 'Set-Cookie'));

		t_same('yes', $report['regenerated'], 'a visitor with no session yet also gets a renewed identifier');
		t_ok($report['session_id_before'] !== '' && $report['session_id'] !== $report['session_id_before'], 'the identifier the request started with is not the one it ends with');
		t_contains('PHPSESSID=' . $report['session_id'], $cookies, 'the browser is told to keep the final identifier');
		t_lacks($report['session_id_before'], $cookies, 'the identifier the request started with is not left in the browser');

		// Output has already started: renewing is impossible, and the page has
		// to say so to the log and carry on regardless.
		$response = mcm_http($server, '/probe_regenerate_late.php', array('Cookie: PHPSESSID=' . $for_late));
		$report   = mcm_report($response['body']);

		t_same(200, $response['status'], 'a transition after output started still returns the page');
		t_same('no', $report['regenerated'], 'a renewal that could not happen is not reported as having happened');
		t_same($for_late, $report['session_id'], 'the identifier is left as it was when it could not be renewed');
		t_contains('request_completed=yes', $response['body'], 'the request the visitor made still finishes');
		t_contains('output had already started', $response['log'], 'the reason is in the log');
		t_lacks('output had already started', $response['body'], 'the reason stays out of the response');
	} catch (Exception $exception) {
		t_ok(false, 'the session renewal cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	// The other way it can fail: no session is open at all.
	$result = mcm_cli($fixture, 'probe_regenerate_closed.php');
	$report = mcm_report($result['stdout']);
	t_same(0, $result['status'], 'a renewal with no session open does not fail the request');
	t_same('no', $report['regenerated'], 'a renewal with no session open reports that it did not happen');
	t_contains('request_completed=yes', $result['stdout'], 'the page still finishes');
	t_contains('no session is active', $result['log'], 'the reason is in the log');
	t_lacks('no session is active', $result['stdout'], 'the reason stays out of the response');

	// Every authentication-sensitive transition renews the identifier, and the
	// fact is read from that transition's own code rather than from the file as
	// a whole.
	$login       = MCM_REPO_ROOT . '/inc/classes/Login.php';
	$transitions = array(
		'loginWithPostData'   => 'a successful login',
		'loginWithCookieData' => 'a login from a remember-me cookie',
		'editUserPassword'    => 'a password change',
		'editNewPassword'     => 'a password reset',
	);
	foreach ($transitions as $method => $description) {
		t_same(1, mcm_method_calls($login, $method, 'mcm_session_regenerate_id'), $description . ' renews the session identifier');
	}
	t_same(count($transitions), mcm_count_calls($login, 'mcm_session_regenerate_id'), 'those transitions are the only places that renew it');

	// The rehash sits in the login path too, and only there.
	t_same(1, mcm_method_calls($login, 'loginWithPostData', 'mcm_password_needs_rehash'), 'a successful login is where a stored hash is reconsidered');
	t_same(1, mcm_method_calls($login, 'loginWithPostData', 'mcm_password_verify'), 'a login verifies the password through the shared helper');
	foreach (array($login, MCM_REPO_ROOT . '/inc/classes/Registration.php') as $file) {
		$name = substr($file, strlen(MCM_REPO_ROOT) + 1);
		t_same(0, mcm_count_calls($file, 'password_hash'), $name . ' hashes passwords through the shared helper only');
		t_same(0, mcm_count_calls($file, 'password_verify'), $name . ' verifies passwords through the shared helper only');
		t_same(0, mcm_count_calls($file, 'password_needs_rehash'), $name . ' asks about rehashing through the shared helper only');
	}

	// The per-method check has to have teeth: a call in a neighbouring method
	// must not count. Nothing here touches the checkout.
	$fixture = mcm_fixture('method-scope');
	$path    = $fixture['public'] . '/scoped_example.php';
	$source  = <<<'PHP'
<?php

class Example
{
	public function withCall($argument = array())
	{
		if (true) {
			mcm_session_regenerate_id();
		}
	}

	public function withoutCall($argument = '')
	{
		// mcm_session_regenerate_id() is only mentioned here.
		$note = "a string that interpolates {$argument} and names mcm_session_regenerate_id";
		return $note;
	}
}
PHP;
	file_put_contents($path, $source);

	t_same(1, mcm_method_calls($path, 'withCall', 'mcm_session_regenerate_id'), 'the per-method check finds the call in the method it names');
	t_same(0, mcm_method_calls($path, 'withoutCall', 'mcm_session_regenerate_id'), 'the per-method check does not see a neighbouring method\'s call');
	t_same(0, mcm_method_calls($path, 'noSuchMethod', 'mcm_session_regenerate_id'), 'the per-method check reports nothing for a method that is not there');
	unlink($path);
});

/*
 * ---------------------------------------------------------------------------
 * 12. The guard helpers that answer without a request
 * ---------------------------------------------------------------------------
 */

t_group('guard helpers', function () {
	$fixture = mcm_fixture('guard-units');
	$result  = mcm_cli($fixture, 'guard_units.php');
	$report  = mcm_report($result['stdout']);

	t_same(0, $result['status'], 'the helper page runs cleanly');
	t_same('', $result['log'], 'the helpers log nothing of their own');

	// Identifiers. Everything that is not a run of digits is refused, so a
	// caller can never hand a query or a comparison something else.
	$identifiers = array(
		'positive_int_int'      => '7',
		'positive_int_string'   => "7",
		'positive_int_padded'   => '7',
		'positive_int_zero'     => 'NULL',
		'positive_int_negative' => 'NULL',
		'positive_int_empty'    => 'NULL',
		'positive_int_trailing' => 'NULL',
		'positive_int_sql'      => 'NULL',
		'positive_int_spaced'   => 'NULL',
		'positive_int_float'    => 'NULL',
		'positive_int_bool'     => 'NULL',
		'positive_int_null'     => 'NULL',
		'positive_int_array'    => 'NULL',
	);
	foreach ($identifiers as $name => $expected) {
		t_same($expected, isset($report[$name]) ? $report[$name] : '(absent)', 'identifier: ' . substr($name, 13));
	}

	// The constant-time comparison. A difference anywhere is still a difference,
	// and a prefix of the right answer is not the right answer.
	$comparisons = array(
		'equals_same'         => 'true',
		'equals_first_byte'   => 'false',
		'equals_last_byte'    => 'false',
		'equals_prefix'       => 'false',
		'equals_longer'       => 'false',
		'equals_both_empty'   => 'true',
		'equals_known_empty'  => 'false',
		'equals_given_empty'  => 'false',
		// A caller that forwards whatever arrived in the request must not be
		// able to turn a comparison into a TypeError.
		'equals_non_string'   => 'false',
	);
	foreach ($comparisons as $name => $expected) {
		t_same($expected, isset($report[$name]) ? $report[$name] : '(absent)', 'comparison: ' . $name);
	}

	// Tokens.
	t_same('64', $report['token_length'], 'a token is 64 hex characters');
	t_same('true', $report['token_hex'], 'a token is hex and nothing else');
	t_same('true', $report['token_unique'], 'two tokens in one request differ');

	// The command line has no request method, so nothing there is a POST.
	t_same('', $report['method'], 'there is no request method outside a request');
	t_same('false', $report['is_post'], 'nothing outside a request is a POST');

	// Anything from the client that reaches a log line is one line, and short.
	t_same('list 11', $report['log_detail_plain'], 'ordinary detail is passed through');
	t_same('first?second??third', $report['log_detail_control'], 'a value cannot break the log into more lines');
	t_same('103', $report['log_detail_length'], 'a long value is truncated');

	// The other half of the same rule: a rendering that keeps only the type is
	// not a diagnostic. A value that is not a string still has to arrive in the
	// log as itself, which is why each of these asserts the value and not just
	// the word in front of it. The application may not call var_export() and
	// friends at all (see the debug-output check), so this is the rendering that
	// has to carry them.
	t_same('boolean true', $report['log_detail_true'], 'a true value reaches the log as its value');
	t_same('boolean false', $report['log_detail_false'], 'a false value reaches the log as its value');
	t_same('null', $report['log_detail_null'], 'a null value reaches the log as its value');
	t_same('integer 11', $report['log_detail_int'], 'an integer reaches the log as its value');
	t_same('double 1.5', $report['log_detail_float'], 'a float reaches the log as its value');
	// Only what cannot be written on one line falls back to the type alone.
	t_same('array', $report['log_detail_non_string'], 'a value with no one-line rendering is reduced to its type');

	// The refusal bodies: fixed strings, chosen so that two different reasons
	// sharing a status are indistinguishable from outside.
	t_same('{"error":"bad_request","message":"The request could not be processed."}', $report['body_400'], 'the 400 body');
	t_same('{"error":"authentication_required","message":"You must be signed in to do that."}', $report['body_401'], 'the 401 body');
	t_same('{"error":"forbidden","message":"You are not allowed to do that."}', $report['body_403'], 'the 403 body');
	t_same('{"error":"method_not_allowed","message":"That request method is not allowed here."}', $report['body_405'], 'the 405 body');
	t_same('400', $report['status_unknown'], 'a status the catalogue does not carry becomes 400');
	t_same('403', $report['status_known'], 'a catalogued status is kept');
	t_same('403', $report['status_string'], 'a status that arrives as a string is still recognised');
	t_same($report['body_400'], $report['body_unknown'], 'an uncatalogued status still gets a fixed body');
});

/*
 * ---------------------------------------------------------------------------
 * 13. Who is signed in, and which token the session holds
 * ---------------------------------------------------------------------------
 */

t_group('guard identity', function () {
	$fixture = mcm_fixture('guard-identity');

	// One session per shape a real one can have, including the shapes an older
	// runtime leaves on disk.
	$sessions = array(
		'signed_in'      => array('id' => 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', 'data' => array('user_name' => 'signed-in-user', 'user_id' => 7, 'user_logged_in' => 1)),
		'string_flag'    => array('id' => 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2', 'data' => array('user_name' => 'legacy-user', 'user_id' => '7', 'user_logged_in' => '1')),
		'signed_out'     => array('id' => 'a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3', 'data' => array('user_name' => 'half-way-user', 'user_logged_in' => 0)),
		'no_flag'        => array('id' => 'a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4', 'data' => array('user_name' => 'flagless-user')),
		'no_id'          => array('id' => 'a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5', 'data' => array('user_name' => 'idless-user', 'user_logged_in' => 1)),
		'unusable_id'    => array('id' => 'a6a6a6a6a6a6a6a6a6a6a6a6a6a6a6a6', 'data' => array('user_name' => 'odd-id-user', 'user_id' => 'not-a-number', 'user_logged_in' => 1)),
		'empty_name'     => array('id' => 'a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7', 'data' => array('user_name' => '', 'user_id' => 7, 'user_logged_in' => 1)),
	);
	foreach ($sessions as $session) {
		mcm_seed_session($fixture, $session['id'], $session['data']);
	}

	$server = mcm_server_start($fixture);
	try {
		$expected = array(
			// name           logged in  user id  user name
			'signed_in'   => array('true', '7', "'signed-in-user'"),
			'string_flag' => array('true', '7', "'legacy-user'"),
			'signed_out'  => array('false', 'NULL', 'NULL'),
			'no_flag'     => array('false', 'NULL', 'NULL'),
			'no_id'       => array('true', 'NULL', "'idless-user'"),
			'unusable_id' => array('true', 'NULL', "'odd-id-user'"),
			'empty_name'  => array('false', 'NULL', 'NULL'),
		);

		foreach ($expected as $name => $answers) {
			$response = mcm_http($server, '/guard_identity.php', array('Cookie: PHPSESSID=' . $sessions[$name]['id']));
			$report   = mcm_report($response['body']);

			t_same($answers[0], $report['logged_in'], $name . ': signed in?');
			t_same($answers[1], $report['user_id'], $name . ': which user id?');
			t_same($answers[2], $report['user_name'], $name . ': which user name?');
		}

		// A request with no session at all is nobody.
		$report = mcm_report(mcm_http($server, '/guard_identity.php')['body']);
		t_same('false', $report['logged_in'], 'a visitor with no session is not signed in');
		t_same('NULL', $report['user_id'], 'a visitor with no session has no user id');
		t_same('NULL', $report['user_name'], 'a visitor with no session has no user name');
	} catch (Exception $exception) {
		t_ok(false, 'the identity cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);
});

t_group('guard csrf tokens', function () {
	$fixture = mcm_fixture('guard-csrf');
	$mine    = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
	$theirs  = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';

	mcm_seed_session($fixture, $mine, array('user_name' => 'signed-in-user', 'user_id' => 7, 'user_logged_in' => 1));
	mcm_seed_session($fixture, $theirs, array('user_name' => 'another-user', 'user_id' => 8, 'user_logged_in' => 1));

	$server = mcm_server_start($fixture);
	try {
		// A session that has never been handed a token accepts nothing, which is
		// what stops an empty token from matching an empty session.
		$report = mcm_report(mcm_http($server, '/guard_csrf.php', array('Cookie: PHPSESSID=' . $mine))['body']);

		t_same('(none)', $report['session_token_before'], 'a fresh session carries no token');
		t_same('false', $report['valid_submitted'], 'a session with no token of its own accepts nothing');
		t_same('false', $report['valid_empty'], 'an empty token is refused');
		t_same('false', $report['valid_stranger'], "a token this session never held is refused");
		t_same('false', $report['valid_array'], 'a token that is not a string is refused');

		$token = $report['token'];
		t_matches('/^[0-9a-f]{64}$/', $token, 'the token handed out is 32 random bytes in hex');
		t_same($token, $report['token_again'], 'asking twice in one request gives the same token');

		// The token lasts as long as the session: a second tab must not
		// invalidate the first.
		$report = mcm_report(mcm_http($server, '/guard_csrf.php', array('Cookie: PHPSESSID=' . $mine))['body']);
		t_same($token, $report['session_token_before'], 'the session keeps the token it was given');
		t_same($token, $report['token'], 'a later request is handed the same token');

		// The allow path, in both the shapes a caller can submit.
		$report = mcm_report(mcm_http_post($server, '/guard_csrf.php', array('csrf_token' => $token), array('Cookie: PHPSESSID=' . $mine))['body']);
		t_same('POST', $report['method'], 'the request arrived as a POST');
		t_same('true', $report['is_post'], 'a POST is recognised as one');
		t_same($token, $report['submitted'], 'the submitted field is read');
		t_same('true', $report['valid_submitted'], 'the session token submitted as a field is accepted');

		$report = mcm_report(mcm_http_post($server, '/guard_csrf.php', array(), array(
			'Cookie: PHPSESSID=' . $mine,
			'X-CSRF-Token: ' . $token,
		))['body']);
		t_same('true', $report['valid_submitted'], 'the session token submitted as a header is accepted');

		// The reject paths. Every one of these is a token that is almost right.
		$wrong = array(
			'a token with its last character changed' => substr($token, 0, 63) . ($token[63] === 'f' ? 'e' : 'f'),
			'a token with its first character changed' => ($token[0] === 'f' ? 'e' : 'f') . substr($token, 1),
			'a token missing its last character' => substr($token, 0, 63),
			'a token with a character added'     => $token . 'a',
			'the same token upper-cased'         => strtoupper($token),
			'an empty token'                     => '',
		);
		foreach ($wrong as $description => $candidate) {
			$report = mcm_report(mcm_http_post($server, '/guard_csrf.php', array('csrf_token' => $candidate), array('Cookie: PHPSESSID=' . $mine))['body']);
			t_same('false', $report['valid_submitted'], 'refused: ' . $description);
		}

		// A field that is not a string at all cannot be mistaken for one.
		$report = mcm_report(mcm_http_post($server, '/guard_csrf.php', array('csrf_token' => array($token)), array('Cookie: PHPSESSID=' . $mine))['body']);
		t_same('(none)', $report['submitted'], 'a token submitted as an array is not read as a token');
		t_same('false', $report['valid_submitted'], 'refused: a token submitted as an array');

		// One session's token is worthless in another.
		$report = mcm_report(mcm_http_post($server, '/guard_csrf.php', array('csrf_token' => $token), array('Cookie: PHPSESSID=' . $theirs))['body']);
		t_same('false', $report['valid_submitted'], "refused: another session's token");
		t_ok($report['token'] !== $token, 'each session is handed its own token');

		// A session seeded with a token of its own, which is how every case
		// below drives a signed-in browser. The suite writes the session key out
		// as a literal rather than loading the application to ask for it, so
		// this is where that literal is checked: the page reports the token it
		// found, and it is the one that was seeded. Get the key wrong and every
		// one of those cases would be exercising a session with no token at all.
		$seeded = 'b3b3b3b3b3b3b3b3b3b3b3b3b3b3b3b3';
		mcm_seed_signed_in($fixture, $seeded, array('user_name' => 'seeded-user', 'user_id' => 9, 'user_logged_in' => 1));

		$report = mcm_report(mcm_http($server, '/guard_csrf.php', array('Cookie: PHPSESSID=' . $seeded))['body']);
		t_same(mcm_session_token($seeded), $report['session_token_before'], 'a seeded session carries the token the suite gave it');
		t_same(mcm_session_token($seeded), $report['token'], 'and is handed that same token rather than a new one');

		$report = mcm_report(mcm_http_post($server, '/guard_csrf.php', array(), mcm_session_headers($seeded))['body']);
		t_same('true', $report['valid_submitted'], 'a request built from mcm_session_headers() carries a token that session accepts');
	} catch (Exception $exception) {
		t_ok(false, 'the CSRF token cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);
});

/*
 * ---------------------------------------------------------------------------
 * 14. What each guard allows, and what it refuses
 * ---------------------------------------------------------------------------
 */

t_group('guard rejections', function () {
	$fixture    = mcm_fixture('guard-rejections');
	$seed       = $fixture['seed'];
	$signed_in  = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';
	$signed_out = 'c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2';
	$other_user = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';

	mcm_seed_session($fixture, $signed_in, array('user_name' => 'signed-in-user', 'user_id' => 7, 'user_logged_in' => 1));
	mcm_seed_session($fixture, $signed_out, array('user_name' => 'signed-out-user', 'user_logged_in' => 0));
	mcm_seed_session($fixture, $other_user, array('user_name' => 'another-user', 'user_id' => 8, 'user_logged_in' => 1));

	$forbidden    = '{"error":"forbidden","message":"You are not allowed to do that."}';
	$unauthorised = '{"error":"authentication_required","message":"You must be signed in to do that."}';
	$wrong_method = '{"error":"method_not_allowed","message":"That request method is not allowed here."}';

	$server = mcm_server_start($fixture);
	try {
		/* POST-only. */
		$response = mcm_http_post($server, '/guard_require_post.php');
		t_same(200, $response['status'], 'require_post allows a POST');
		t_same("reached\n", $response['body'], 'require_post lets the page run');

		$response = mcm_http($server, '/guard_require_post.php');
		t_same(405, $response['status'], 'require_post refuses a GET');
		t_same($wrong_method, $response['body'], 'require_post answers with the fixed body');
		t_lacks('reached', $response['body'], 'require_post stops the page');
		t_contains('POST', implode('', mcm_header_values($response, 'Allow')), 'a refused method is told which one is allowed');
		t_contains('application/json', implode('', mcm_header_values($response, 'Content-Type')), 'a refusal is JSON');
		// The exact value, not a substring: the session's own cache limiter
		// already emits "no-store, no-cache, must-revalidate", so a substring
		// check here would pass whether or not the refusal set anything.
		t_same(array('no-store'), mcm_header_values($response, 'Cache-Control'), 'a refusal replaces the caching headers with its own');
		t_contains('method GET is not allowed here', $response['log'], 'the refused method is logged');

		/* Signed in. */
		$response = mcm_http_post($server, '/guard_require_login.php', array(), array('Cookie: PHPSESSID=' . $signed_in));
		t_same(200, $response['status'], 'require_login allows a signed-in user');
		t_same("reached user=signed-in-user\n", $response['body'], 'require_login lets the page run');

		foreach (array('no session' => '', 'a signed-out session' => $signed_out) as $description => $session) {
			$headers  = $session === '' ? array() : array('Cookie: PHPSESSID=' . $session);
			$response = mcm_http_post($server, '/guard_require_login.php', array(), $headers);

			t_same(401, $response['status'], 'require_login refuses ' . $description);
			t_same($unauthorised, $response['body'], 'require_login answers ' . $description . ' with the fixed body');
			t_lacks('reached', $response['body'], 'require_login stops the page for ' . $description);
			t_contains('no signed-in user', $response['log'], 'the reason is logged for ' . $description);
		}

		/* CSRF. */
		$token = mcm_report(mcm_http($server, '/guard_csrf.php', array('Cookie: PHPSESSID=' . $signed_in))['body'])['token'];

		$response = mcm_http_post($server, '/guard_require_csrf.php', array('csrf_token' => $token), array('Cookie: PHPSESSID=' . $signed_in));
		t_same(200, $response['status'], 'require_csrf allows the session token');
		t_same("reached\n", $response['body'], 'require_csrf lets the page run');

		$refused = array(
			'no token at all'            => array(),
			'an empty token'             => array('csrf_token' => ''),
			'a token that is almost right' => array('csrf_token' => substr($token, 0, 63) . ($token[63] === 'f' ? 'e' : 'f')),
			"another session's token"    => array('csrf_token' => str_repeat('9', 64)),
		);
		foreach ($refused as $description => $fields) {
			$response = mcm_http_post($server, '/guard_require_csrf.php', $fields, array('Cookie: PHPSESSID=' . $signed_in));

			t_same(403, $response['status'], 'require_csrf refuses ' . $description);
			t_same($forbidden, $response['body'], 'require_csrf answers ' . $description . ' with the fixed body');
			t_lacks('reached', $response['body'], 'require_csrf stops the page for ' . $description);
			t_contains('no valid CSRF token', $response['log'], 'the reason is logged for ' . $description);
			t_lacks($token, $response['log'], 'the session token is never written to the log for ' . $description);
		}

		/* Ownership. */
		$response = mcm_http_post($server, '/guard_ownership.php');
		$report   = mcm_report($response['body']);

		if (isset($report['sqlite']) && $report['sqlite'] === 'missing') {
			t_skip('the ownership cases', 'this runtime has no SQLite driver to build the fixture table with');
		} else {
			$ownership = array(
				'own_list'            => 'true',
				'own_list_as_strings' => 'true',
				'other_users_list'    => 'false',
				'missing_list'        => 'false',
				'injected_id'         => 'false',
				'zero_id'             => 'false',
				'empty_id'            => 'false',
				'no_user'             => 'false',
				'zero_user'           => 'false',
			);
			foreach ($ownership as $name => $expected) {
				t_same($expected, isset($report[$name]) ? $report[$name] : '(absent)', 'ownership: ' . $name);
			}

			$response = mcm_http_post($server, '/guard_require_list_owner.php', array('movie_list_id' => 11), array('Cookie: PHPSESSID=' . $signed_in));
			t_same(200, $response['status'], 'require_list_owner allows the owner');
			t_same("reached list=11\n", $response['body'], 'require_list_owner hands back the validated identifier');

			// Every way of not owning a list answers identically, so a response
			// never says whether a list exists.
			$denied = array(
				"another user's list"    => array('session' => $signed_in, 'fields' => array('movie_list_id' => 12), 'logged' => 'not owned by user 7'),
				'a list that does not exist' => array('session' => $signed_in, 'fields' => array('movie_list_id' => 99), 'logged' => 'not owned by user 7'),
				'an identifier that is not a number' => array('session' => $signed_in, 'fields' => array('movie_list_id' => 'abc'), 'logged' => 'not a positive integer'),
				'no identifier at all'   => array('session' => $signed_in, 'fields' => array(), 'logged' => 'not a positive integer'),
				'a visitor who is not signed in' => array('session' => $signed_out, 'fields' => array('movie_list_id' => 11), 'logged' => 'no signed-in user'),
				"the owner's list, asked for by somebody else" => array('session' => $other_user, 'fields' => array('movie_list_id' => 11), 'logged' => 'not owned by user 8'),
			);
			foreach ($denied as $description => $case) {
				$response = mcm_http_post($server, '/guard_require_list_owner.php', $case['fields'], array('Cookie: PHPSESSID=' . $case['session']));

				t_same(403, $response['status'], 'require_list_owner refuses ' . $description);
				t_same($forbidden, $response['body'], 'require_list_owner answers ' . $description . ' with the same body as every other refusal');
				t_lacks('reached', $response['body'], 'require_list_owner stops the page for ' . $description);
				t_contains($case['logged'], $response['log'], 'the reason is logged for ' . $description);
			}

			// Both halves of what a refusal is for, on one request: the value
			// that caused it is in the log, where somebody diagnosing this can
			// read it, and it is not in the response, where the client could.
			// A log that says only "an identifier was not a positive integer"
			// cannot be used to find out what was sent, so the value itself is
			// what is asserted here.
			$response = mcm_http_post($server, '/guard_require_list_owner.php', array('movie_list_id' => $seed), array('Cookie: PHPSESSID=' . $signed_in));

			t_same(403, $response['status'], 'a refused identifier gets the ordinary refusal');
			t_contains($seed, $response['log'], 'the refused identifier itself reaches the log');
			t_contains('not a positive integer', $response['log'], 'the log says why it was refused');
			t_same($forbidden, $response['body'], 'the response is the same fixed body as every other refusal');
			t_lacks($seed, $response['body'], 'the refused identifier never reaches the client');
			// The body is not the only thing the client reads.
			t_lacks($seed, mcm_header_text($response), 'the refused identifier never reaches the response headers');
		}

		/* The refusal itself: detail goes to the log, never to the client. */
		$statuses = array(
			400 => '{"error":"bad_request","message":"The request could not be processed."}',
			401 => $unauthorised,
			403 => $forbidden,
			405 => $wrong_method,
			599 => '{"error":"bad_request","message":"The request could not be processed."}',
		);
		foreach ($statuses as $status => $body) {
			$response = mcm_http($server, '/guard_json_error.php?status=' . $status);

			t_same($status === 599 ? 400 : $status, $response['status'], 'a refusal with status ' . $status);
			t_same($body, $response['body'], 'the body of a refusal with status ' . $status);
			t_lacks($seed, $response['body'], 'the private detail never reaches the client for status ' . $status);
			t_lacks($seed, mcm_header_text($response), 'the private detail never reaches the headers for status ' . $status);
			t_contains($seed, $response['log'], 'the private detail is logged for status ' . $status);
			t_lacks('never reached', $response['body'], 'nothing after the refusal runs for status ' . $status);
		}
	} catch (Exception $exception) {
		t_ok(false, 'the guard rejection cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);
});

/*
 * ---------------------------------------------------------------------------
 * 15. The guards are additive, and who has adopted them
 * ---------------------------------------------------------------------------
 */

t_group('guards are additive', function () {
	$guards = MCM_REPO_ROOT . '/inc/guards.php';

	t_ok(file_exists($guards), 'the guards live in inc/guards.php');

	// A constant-time comparison and an ordinary one behave identically, so no
	// case that runs a request can tell them apart: this is the only thing that
	// catches the comparison being swapped for ===. It reads the function's own
	// tokens because a check over the whole file would be satisfied by a
	// hash_equals() somewhere else, and it reads them in inc/security.php,
	// which is where the shared comparison lives and where the bootstrap loads
	// it from.
	//
	// Only the byte comparison is asserted. The length comparison in that
	// function is deliberate - hash_equals() lets the length leak too - so the
	// assertion is on what the function calls, not on it containing no
	// comparison at all.
	$security = MCM_REPO_ROOT . '/inc/security.php';
	$body     = mcm_method_tokens($security, 'mcm_hash_equals');
	t_ok(count($body) > 0, 'mcm_hash_equals() is declared');
	t_same(1, mcm_count_calls_in($body, 'hash_equals'), 'the token comparison uses hash_equals()');
	t_same(0, mcm_count_calls_in($body, 'strcmp'), 'the token comparison does not fall back to strcmp()');
	t_same(0, mcm_count_calls_in($body, 'substr'), 'the token comparison does not compare a prefix');

	// And the validity check has to route through it rather than comparing the
	// session's token itself.
	$body = mcm_method_tokens($guards, 'mcm_csrf_token_is_valid');
	t_ok(count($body) > 0, 'mcm_csrf_token_is_valid() is declared');
	t_same(1, mcm_count_calls_in($body, 'mcm_hash_equals'), 'the CSRF check compares through mcm_hash_equals()');
	t_same(0, mcm_count_calls_in($body, 'hash_equals'), 'the CSRF check does not compare on its own');

	// Which entry points load the guards, named one by one. Adoption is
	// deliberate and endpoint by endpoint, so this list grows only when an issue
	// adopts them somewhere; a page that starts loading them without being added
	// here fails the suite rather than passing quietly.
	$callers = array();
	foreach (mcm_php_sources(MCM_REPO_ROOT) as $file) {
		if ($file === $guards) {
			continue;
		}
		foreach (mcm_include_statements($file) as $statement) {
			foreach ($statement['literals'] as $literal) {
				if (substr($literal, -10) === 'guards.php') {
					$callers[] = substr($file, strlen(MCM_REPO_ROOT) + 1);
				}
			}
		}
	}
	sort($callers);
	t_same(mcm_guarded_entry_points(), $callers, 'the guards are loaded by exactly the endpoints that have adopted them');

	// Which of the four each of them asks, and in the one order that can answer
	// them without a refusal arriving after something has already been written.
	// What a guard refuses is proven by driving requests at it; this is the
	// anchor on the wiring behind that, and it is the only place the order
	// itself is stated, because no request can observe the difference between
	// "refused before the connection" and "refused before the connection, in
	// this sequence".
	foreach (mcm_guarded_entry_points() as $page) {
		$path   = MCM_REPO_ROOT . '/' . $page;
		$source = mcm_flat_source($path);

		// One page loads this file without being guarded by it: index.php needs
		// mcm_csrf_token() to hand the browser a token, and guards nothing. It
		// has always served a page to whoever asked, and still does.
		if (in_array($page, mcm_unguarded_guard_users(), true)) {
			foreach (mcm_guard_functions() as $guard) {
				t_same(0, mcm_count_calls($path, $guard), $page . ' loads the guards and calls ' . $guard . '() nowhere');
			}
			continue;
		}

		// And the TMDb proxy adopts some of them for a read. The two that do not
		// apply are asserted absent by name rather than left unsaid: a read has
		// no row for a token to protect, and refusing a GET would turn away the
		// public sharing page. Which of the other two each operation asks for is
		// driven as requests in the proxy groups below.
		if (in_array($page, mcm_read_guarded_guard_users(), true)) {
			t_same(0, mcm_count_calls($path, 'mcm_require_post'), $page . ' does not refuse a read for its method');
			t_same(0, mcm_count_calls($path, 'mcm_require_csrf'), $page . ' asks for no token on a read');
			continue;
		}

		t_same(1, mcm_count_calls($path, 'mcm_require_post'), $page . ' refuses anything that is not a POST, exactly once');
		t_same(1, mcm_count_calls($path, 'mcm_require_csrf'), $page . " asks for this session's token, exactly once");

		$post  = strpos($source, 'mcm_require_post');
		$login = strpos($source, 'mcm_require_login');
		$csrf  = strpos($source, 'mcm_require_csrf');
		$open  = strpos($source, 'mcm_db_or_fail');
		$owner = strpos($source, 'mcm_require_list_owner');

		t_ok($post !== false && $login !== false && $csrf !== false, $page . ' asks all three of the questions that need no database');
		t_ok($post < $login, $page . ' settles the method before it looks at the session');
		t_ok($login < $csrf, $page . ' settles who is asking before it checks their token');
		t_ok($open !== false && $csrf < $open, $page . ' settles the token before it opens a connection');
		if ($owner !== false) {
			t_ok($open < $owner, $page . ' opens the connection before it asks whose list this is');
		}

		// And a value the request carries is read from the POST body only. The
		// method guard above is what refuses a GET; this is what leaves the page
		// unable to take a value off a query string even if that guard were ever
		// dropped, exactly as the owner in each WHERE clause is the second layer
		// under the ownership guard.
		t_lacks('$_GET', $source, $page . ' reads no request value from the query string');
	}

	// The guards are a file of declarations: loading them has to be silent, and
	// on its own must not produce a page.
	$fixture  = mcm_fixture('guards-additive');
	$server   = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/inc/guards.php');
		t_same(200, $response['status'], 'the guards file is inert when it is loaded');
		t_same('', $response['body'], 'the guards file produces no output of its own');
		t_same('', $response['log'], 'the guards file logs nothing of its own');
	} catch (Exception $exception) {
		t_ok(false, 'the inert-guards case ran', $exception->getMessage());
	}
	mcm_server_stop($server);
});

/*
 * ---------------------------------------------------------------------------
 * 16. The token's round trip: into the page, and back out of the browser
 * ---------------------------------------------------------------------------
 *
 * The guards above answer "was this request made from a page this site handed
 * out". That is only worth anything if the page really does hand the token out,
 * really does send it back, and really does keep it to itself on the way.
 *
 * Which half of that is proven how is worth saying plainly, because the two
 * halves are not proven the same way. The server half - a request carrying no
 * token, or the wrong one, is refused, and refused without changing a row - is
 * behavioural: it is driven as real requests in the endpoint groups below and,
 * against real rows, in the database ones. This group is the browser half, and
 * there is no JavaScript runtime here to execute that with, nor will there be.
 * Every assertion below therefore reads the file. What it can say is what the
 * source holds and in what order - which call the prefilter's body makes, which
 * answer it consults before making it, which URL and method each request names.
 * What it cannot say is what a browser does when it runs any of that.
 *
 * Reading is narrowed to the construct in question for the same reason the PHP
 * checks above narrow theirs: the prefilter's own body, and the object literal
 * of each call, so a fact about one of them cannot be satisfied by something
 * else in the file.
 */

t_group('the csrf token reaches the browser and stays inside this site', function () {
	$guards = MCM_REPO_ROOT . '/inc/guards.php';
	$view   = MCM_REPO_ROOT . '/inc/views/logged_in.php';
	$script = MCM_REPO_ROOT . '/js/mc.js';

	/* 1. Into the page, once, and nowhere else --------------------------- */

	// Which pages mint a token at all. A sharing link is not authenticated and
	// its view must never be handed one; neither must the login or registration
	// pages, which have no session to bind it to yet. Derived from the source
	// on purpose: the claim is about every file in the project, and a new page
	// that started handing the token out would have to answer to this line.
	$minting = array();
	foreach (mcm_php_sources(MCM_REPO_ROOT) as $file) {
		if ($file === $guards) {
			continue;
		}
		if (mcm_count_calls($file, 'mcm_csrf_token') > 0) {
			$minting[] = substr($file, strlen(MCM_REPO_ROOT) + 1);
		}
	}
	sort($minting);
	t_same(array('inc/views/logged_in.php'), $minting, 'the signed-in view is the only page that hands out a token');

	// And it hands it over as a value inside a script block, through the
	// escaper for that destination. A token in a URL would end up in a history
	// entry, a referrer and a server log; mcm_js() is the only escaper that can
	// be right here, and mcm_url() would be the sign it had gone the other way.
	$view_source = mcm_flat_source($view);
	t_contains('mcm_js ( mcm_csrf_token ( ) )', $view_source, 'the token is escaped for the script block it is embedded in');
	t_lacks('mcm_url ( mcm_csrf_token', $view_source, 'the token is never escaped as part of a URL');
	t_same(0, mcm_count_calls($view, 'mcm_redirect'), 'the signed-in view builds no redirect at all, so none can carry it');

	// The public sharing page asks for none of this and must keep working
	// exactly as it did. Its own script file makes no request that could need a
	// token, which is why nothing on that page had to change.
	foreach (array('share.php', 'inc/views/share.php') as $public) {
		foreach (mcm_guard_functions() as $guard) {
			t_same(0, mcm_count_calls(MCM_REPO_ROOT . '/' . $public, $guard), $public . ' never calls ' . $guard . '(): a sharing link is not authenticated');
		}
	}
	t_same(array(), mcm_js_ajax_calls(MCM_REPO_ROOT . '/js/share.js'), 'the sharing page makes no request of its own');

	/* 2. Back out of the browser ----------------------------------------- */

	// Every request js/mc.js makes back to this site, and there are exactly as
	// many of them as there are endpoints requiring a token. Each is a POST,
	// because a GET is now refused on the method alone.
	// The endpoints that require a token: every file that loads the guards, less
	// the page that only hands the token out and less the read-only proxy, which
	// asks for no token and is called by nothing in the browser yet.
	$endpoints = array_values(array_diff(
		mcm_guarded_entry_points(),
		mcm_unguarded_guard_users(),
		mcm_read_guarded_guard_users()
	));
	$calls     = mcm_js_ajax_calls($script);
	$targets   = array();
	foreach ($calls as $call) {
		t_same('POST', $call['type'], 'js/mc.js requests ' . ($call['url'] === '' ? '(an unreadable call)' : $call['url']) . ' with POST');
		$targets[] = $call['url'];
	}
	sort($targets);
	t_same($endpoints, $targets, 'the endpoints the page calls are exactly the ones that now require a POST and a token');

	// One shared place decides what goes on a request, rather than nine call
	// sites each remembering to.
	$source    = file_get_contents($script);
	$prefilter = mcm_js_call_body($script, '$.ajaxPrefilter');
	t_ok($prefilter !== '', 'js/mc.js installs a shared prefilter');
	t_same(1, substr_count($source, '$.ajaxPrefilter('), 'and exactly one of them');
	t_contains('setRequestHeader', $prefilter, "the filter's body calls setRequestHeader");
	t_contains('csrf_token', $prefilter, "the filter's body references the page's token variable");
	// Read when the request is made, not when the file loads: the header
	// renders every script file before the inline block that defines the token,
	// so at load time there is nothing to read.
	t_contains('typeof csrf_token', $prefilter, "the filter's body guards on typeof csrf_token rather than capturing it at load");
	$header_view = mcm_flat_source(MCM_REPO_ROOT . '/inc/views/header.php');
	t_ok(strpos($header_view, 'post_scripts') < strpos($header_view, '$script'), 'the page loads its script files before the block that defines the token');

	// The name the browser sends and the name the server reads are one name.
	// PHP renames a request header on the way into $_SERVER, so comparing the
	// two spellings by eye proves nothing; this builds the server's spelling
	// out of the browser's and looks for it in the guards.
	$sent = '';
	if (preg_match('/setRequestHeader\(\s*\'([^\']+)\'/', $prefilter, $match) === 1) {
		$sent = $match[1];
	}
	t_ok($sent !== '', 'the prefilter names the header it sets');
	t_contains("'HTTP_" . str_replace('-', '_', strtoupper($sent)) . "'", mcm_flat_source($guards), 'the guards read the header the browser sends');

	/* 3. And no further than this site ----------------------------------- */

	// The type-ahead searches TMDb through the same jQuery, so "put the token on
	// every request" would hand a credential to somebody else's server. What
	// stops it is jQuery's own answer: it resolves the URL - a protocol-relative
	// one included - and compares scheme, host and port against the page's,
	// before any prefilter runs. Reading that answer is the point, because a
	// second implementation of the same rule in this file is a second
	// implementation to get wrong.
	t_contains('crossDomain', $prefilter, "the filter's body references jQuery's crossDomain answer rather than testing the URL itself");
	t_ok(
		strpos($prefilter, 'crossDomain') < strpos($prefilter, 'setRequestHeader'),
		'crossDomain appears before setRequestHeader in the filter\'s body'
	);
	// No pattern of its own, which is also what keeps this file readable by the
	// scanner the browser-rendering checks are built on.
	t_same(array(), mcm_js_regex_literals($script), 'the prefilter picks no URL apart itself');

	// That answer is only as good as the addresses it is given. Every request
	// this page makes to this site is named relatively, so it resolves to this
	// origin whatever the site is deployed at; the one address that is not this
	// site names its scheme and host in full, which is what jQuery compares.
	foreach ($targets as $url) {
		t_same(0, substr_count($url, ':'), $url . ' names no scheme, so it resolves to this site');
		t_lacks('//', $url, $url . ' names no host of its own either');
	}

	// The outside address, read out of the file rather than written out again
	// here: it is the one call this page makes that must not carry a token.
	$remote = '';
	if (preg_match('/url:\s*\'((?:https?:|\/\/)[^\']+)\'/', $source, $match) === 1) {
		$remote = $match[1];
	}
	t_ok($remote !== '', 'js/mc.js names an address outside this site');
	t_contains('://', $remote, 'and names its scheme and host in full, so jQuery classifies it as leaving this site');
	t_lacks($remote, $prefilter, 'the prefilter does not special-case that one address');
});

/*
 * ---------------------------------------------------------------------------
 * 17. The list endpoints: who may write, and what a malformed request gets
 * ---------------------------------------------------------------------------
 *
 * Everything a list endpoint refuses, it refuses before it opens a connection,
 * which is what makes this group possible without a database: the fixture is
 * pointed at a port nothing listens on, so "did this request reach the
 * database" is a question the log answers. A refusal that logs no driver error
 * is a request that could not have changed a row.
 *
 * The owner and non-owner halves of the same question need real rows, and live
 * in the optional real-database group below.
 */

t_group('list endpoint guards', function () {
	// The endpoints that write a list, and a request each that would work if
	// the caller were allowed to make it.
	$mutations = array(
		'create_list.php' => array('list_name' => 'a new list', 'list_rank' => 0),
		'rename_list.php' => array('movie_list_id' => 1, 'list_name' => 'a new name'),
		'delete_list.php' => array('movie_list_id' => 1),
		'adjust_lists.php' => array('stop_state' => '[2,1]', 'start_pos' => 0, 'stop_pos' => 1),
		'share_lists.php' => array('changed_lists' => '[1]', 'share_vals' => '[1]'),
	);

	/* What the source says ------------------------------------------------- */

	// mcm_statement_owner_qualified() has to actually parse the statement's
	// shape rather than grep for the substring "user_id" - prove that against
	// deliberately broken statement strings before trusting it against the real
	// endpoints below. Each of these is built here, not read from the checkout.
	$broken_update_only_in_set = 'UPDATE movie_lists SET user_id = :user_id WHERE movie_list_id = :movie_list_id';
	$broken_delete_no_where    = 'DELETE FROM movie_lists';
	$broken_delete_wrong_where = 'DELETE FROM movie_lists WHERE movie_list_id = :movie_list_id';
	$broken_insert_missing     = 'INSERT INTO movie_lists (list_name, list_rank) VALUES (:list_name, :list_rank)';
	$correct_update            = 'UPDATE movie_lists SET list_name = :list_name WHERE movie_list_id = :movie_list_id AND user_id = :user_id';
	$correct_delete            = 'DELETE FROM movie_lists WHERE movie_list_id = :movie_list_id AND user_id = :user_id';
	$correct_insert            = 'INSERT INTO movie_lists (user_id, list_name, list_rank) VALUES (:user_id, :list_name, :list_rank)';

	t_same(false, mcm_statement_owner_qualified($broken_update_only_in_set), 'owner check rejects user_id that only appears in SET, not WHERE');
	t_same(false, mcm_statement_owner_qualified($broken_delete_no_where), 'owner check rejects a DELETE with no WHERE clause at all');
	t_same(false, mcm_statement_owner_qualified($broken_delete_wrong_where), 'owner check rejects a WHERE that names only the list, not the owner');
	t_same(false, mcm_statement_owner_qualified($broken_insert_missing), 'owner check rejects an INSERT that does not write user_id');
	t_same(true, mcm_statement_owner_qualified($correct_update), 'owner check accepts an UPDATE whose WHERE restricts by user_id');
	t_same(true, mcm_statement_owner_qualified($correct_delete), 'owner check accepts a DELETE whose WHERE restricts by user_id');
	t_same(true, mcm_statement_owner_qualified($correct_insert), 'owner check accepts an INSERT that writes user_id');

	foreach (array_keys($mutations) as $page) {
		$path = MCM_REPO_ROOT . '/' . $page;

		// The behavioral halves below (this group's HTTP section, and "real
		// database: list ownership") already prove a signed-out or wrong-owner
		// caller cannot write; this call count is a cheap regression anchor on
		// top of that, not the only evidence for it.
		t_same(1, mcm_count_calls($path, 'mcm_require_login'), $page . ' asks for a signed-in user exactly once');

		// This is the one claim in this block that behavioral tests cannot make:
		// the ownership guard in front of every one of these statements already
		// refuses a foreign request before the statement ever runs, so no HTTP
		// case can observe what the WHERE clause alone would have done. It is a
		// deliberate second layer - the guard refuses the request, and this
		// leaves the statement itself unable to reach another account's row even
		// if the guard above it were ever dropped - so it is asserted here as
		// its own claim.
		foreach (mcm_write_statements($path) as $sql) {
			if (stripos($sql, 'movie_lists') === false && stripos($sql, 'movies') === false) {
				continue;
			}
			t_ok(mcm_statement_owner_qualified($sql), $page . ': a statement that changes a row is qualified by owner - ' . $sql);
		}
	}

	// The four that are handed a list identifier check whose it is. create_list
	// is the one that is not: it makes a list rather than changing one. Which
	// actor may and may not write is proven behaviorally below and in "real
	// database: list ownership"; this is the regression anchor for how that is
	// wired.
	foreach (array('rename_list.php', 'delete_list.php') as $page) {
		t_same(1, mcm_count_calls(MCM_REPO_ROOT . '/' . $page, 'mcm_require_list_owner'), $page . ' checks the owner of the list it was given');
	}
	foreach (array('adjust_lists.php', 'share_lists.php') as $page) {
		t_ok(mcm_count_calls(MCM_REPO_ROOT . '/' . $page, 'mcm_require_list_owner') > 0, $page . ' checks the owner of every list it was given');
	}
	t_same(0, mcm_count_calls(MCM_REPO_ROOT . '/create_list.php', 'mcm_require_list_owner'), 'create_list.php has no existing list to own');

	// The new list's identity comes from the insert. The read-back this replaced
	// looked it up by owner and rank, which is not a unique pair. The behavioral
	// regression for this (a second list created at a rank another list already
	// holds) lives in "real database: list ownership"; this is the anchor on the
	// source shape that makes it true.
	$create = MCM_REPO_ROOT . '/create_list.php';
	t_contains('lastInsertId', mcm_flat_source($create), 'create_list.php takes the new identifier from the insert itself');
	t_lacks('SELECT movie_list_id FROM movie_lists', mcm_flat_source($create), 'create_list.php no longer reads the new list back by rank');

	/* What a request gets --------------------------------------------------- */

	// Nothing is listening on port 1, so any request that reaches the database
	// leaves a driver error in the log and any request that does not, does not.
	$fixture = mcm_fixture('list-guards', array('config' => array('DB_HOST' => '127.0.0.1;port=1')));
	$owner   = 'f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1';
	mcm_seed_signed_in($fixture, $owner, array('user_name' => 'list-owner', 'user_id' => 3, 'user_logged_in' => 1));

	$unauthorised = '{"error":"authentication_required","message":"You must be signed in to do that."}';
	$bad_request  = '{"error":"bad_request","message":"The request could not be processed."}';
	$forbidden    = '{"error":"forbidden","message":"You are not allowed to do that."}';
	$wrong_method = '{"error":"method_not_allowed","message":"That request method is not allowed here."}';

	$server = mcm_server_start($fixture);
	try {
		/* 1. Anonymous. Refused, and refused before the connection. */
		foreach ($mutations as $page => $fields) {
			$response = mcm_http_post($server, '/' . $page, $fields);

			t_same(401, $response['status'], $page . ' refuses an anonymous request');
			t_same($unauthorised, $response['body'], $page . ' answers an anonymous request with the fixed body');
			t_lacks('greatsuccess', $response['body'], $page . ' does not tell an anonymous caller it worked');
			t_lacks('movie_list_id:', $response['body'], $page . ' hands an anonymous caller no identifier');
			t_contains('no signed-in user', $response['log'], $page . ': the reason an anonymous request was refused is logged');
			// The whole of "without changing data", on a suite with no database:
			// the request never opened a connection, so there was nothing to
			// change. A connection attempt would be a driver error in the log.
			t_lacks('SQLSTATE', $response['log'], $page . ' never reaches the database for an anonymous request');
			t_contains('application/json', implode('', mcm_header_values($response, 'Content-Type')), $page . ': an anonymous refusal is JSON');
		}

		// A session that says it is signed out is not signed in, and neither is
		// one that lost its name.
		$broken = array(
			'a signed-out session' => array('user_name' => 'someone', 'user_id' => 3, 'user_logged_in' => 0),
			'a session with no user' => array('user_logged_in' => 1),
		);
		$index = 0;
		foreach ($broken as $description => $data) {
			$id = str_repeat(dechex(10 + $index++), 16);
			mcm_seed_session($fixture, $id, $data);
			$response = mcm_http_post($server, '/delete_list.php', array('movie_list_id' => 1), array('Cookie: PHPSESSID=' . $id));

			t_same(401, $response['status'], 'delete_list.php refuses ' . $description);
			t_lacks('SQLSTATE', $response['log'], 'delete_list.php never reaches the database for ' . $description);
		}

		/* 1b. Not a POST, and no token. Both are refused, and both are refused
		 * before the connection, so a fixture with no database still answers
		 * "and it changed nothing" for every one of them. The requests are
		 * otherwise complete and come from a session that owns nothing yet only
		 * because ownership needs a database; what stops them is earlier. */
		foreach ($mutations as $page => $fields) {
			// A GET carrying everything, token included, is still not a POST.
			$response = mcm_http($server, '/' . $page . '?' . http_build_query($fields), mcm_session_headers($owner));

			t_same(405, $response['status'], $page . ' refuses a GET');
			t_same($wrong_method, $response['body'], $page . ' answers a GET with the fixed body');
			t_contains('POST', implode('', mcm_header_values($response, 'Allow')), $page . ' says which method it does allow');
			t_lacks('greatsuccess', $response['body'], $page . ' does not tell a GET it worked');
			t_lacks('movie_list_id:', $response['body'], $page . ' hands a GET no identifier');
			t_contains('is not allowed here', $response['log'], $page . ': the refused method is logged');
			t_lacks('SQLSTATE', $response['log'], $page . ' never reaches the database for a GET');

			// A POST from the same session, without the token it was given.
			$tokenless = array(
				'no token at all'            => array(),
				'an empty token'             => array('csrf_token' => ''),
				"another session's token"    => array('csrf_token' => str_repeat('9', 64)),
				'a token that is almost right' => array('csrf_token' => substr(mcm_session_token($owner), 0, 63) . 'z'),
			);
			foreach ($tokenless as $description => $extra) {
				$response = mcm_http_post($server, '/' . $page, $fields + $extra, array('Cookie: PHPSESSID=' . $owner));

				t_same(403, $response['status'], $page . ' refuses a POST with ' . $description);
				t_same($forbidden, $response['body'], $page . ' answers ' . $description . ' with the fixed body');
				t_lacks('greatsuccess', $response['body'], $page . ' does not tell ' . $description . ' it worked');
				t_lacks('movie_list_id:', $response['body'], $page . ' hands ' . $description . ' no identifier');
				t_contains('no valid CSRF token', $response['log'], $page . ': the reason is logged for ' . $description);
				t_lacks('SQLSTATE', $response['log'], $page . ' never reaches the database for ' . $description);
			}

			// The token is a credential and never goes to the log, however it
			// arrived and however wrong it was.
			t_lacks(mcm_session_token($owner), $response['log'], $page . ": the session's own token is never logged");
		}

		// The token may also travel as a form field, which is what a page that
		// posts a form rather than an AJAX request would send.
		$response = mcm_http_post($server, '/create_list.php', array(
			'list_name'  => 'first',
			'list_rank'  => 0,
			'csrf_token' => mcm_session_token($owner),
		), array('Cookie: PHPSESSID=' . $owner));
		t_lacks($forbidden, $response['body'], 'a token in the form field is accepted too');
		t_contains('SQLSTATE', $response['log'], 'a token in the form field gets the request as far as the database');

		/* 2. Signed in, but the request is not the shape the page sends. */
		$cookie    = mcm_session_headers($owner);
		$malformed = array(
			'a rank that is not a number' => array('page' => 'create_list.php', 'fields' => array('list_name' => 'ok', 'list_rank' => 'abc'), 'logged' => 'not a position'),
			'a rank past the width of the column' => array('page' => 'create_list.php', 'fields' => array('list_name' => 'ok', 'list_rank' => 256), 'logged' => 'not a position'),
			'no rank at all' => array('page' => 'create_list.php', 'fields' => array('list_name' => 'ok'), 'logged' => 'not a position'),
			'an order that is not JSON' => array('page' => 'adjust_lists.php', 'fields' => array('stop_state' => 'not json', 'start_pos' => 0, 'stop_pos' => 1), 'logged' => 'not a non-empty positional array'),
			'an order that is a JSON object' => array('page' => 'adjust_lists.php', 'fields' => array('stop_state' => '{"1":"2"}', 'start_pos' => 0, 'stop_pos' => 0), 'logged' => 'not a non-empty positional array'),
			'an order with nothing in it' => array('page' => 'adjust_lists.php', 'fields' => array('stop_state' => '[]', 'start_pos' => 0, 'stop_pos' => 0), 'logged' => 'not a non-empty positional array'),
			'an order with no start position' => array('page' => 'adjust_lists.php', 'fields' => array('stop_state' => '[2,1]', 'stop_pos' => 1), 'logged' => 'start position that is not a position'),
			'an order that ends before it starts' => array('page' => 'adjust_lists.php', 'fields' => array('stop_state' => '[2,1]', 'start_pos' => 1, 'stop_pos' => 0), 'logged' => 'refused positions 1-0'),
			'an order that runs past its own array' => array('page' => 'adjust_lists.php', 'fields' => array('stop_state' => '[2,1]', 'start_pos' => 0, 'stop_pos' => 9), 'logged' => 'refused positions 0-9'),
			'a share request that is not JSON' => array('page' => 'share_lists.php', 'fields' => array('changed_lists' => 'not json', 'share_vals' => '[1]'), 'logged' => 'changed list array that is not a positional array'),
			'a share request whose arrays do not line up' => array('page' => 'share_lists.php', 'fields' => array('changed_lists' => '[1,2]', 'share_vals' => '[1]'), 'logged' => 'refused 2 lists against 1 share values'),
			'a share value that is not a setting' => array('page' => 'share_lists.php', 'fields' => array('changed_lists' => '[1]', 'share_vals' => '[7]'), 'logged' => 'share value that is not 0 or 1'),
		);
		foreach ($malformed as $description => $case) {
			$response = mcm_http_post($server, '/' . $case['page'], $case['fields'], $cookie);

			t_same(400, $response['status'], $case['page'] . ' refuses ' . $description);
			t_same($bad_request, $response['body'], $case['page'] . ' answers ' . $description . ' with the fixed body');
			t_lacks('greatsuccess', $response['body'], $case['page'] . ' does not claim ' . $description . ' worked');
			t_contains($case['logged'], $response['log'], $case['page'] . ': the reason is logged for ' . $description);
			// Malformed input is refused where it is read, which is before the
			// connection: nothing was part-written and then abandoned.
			t_lacks('SQLSTATE', $response['log'], $case['page'] . ' never reaches the database for ' . $description);
		}

		// The refusal says nothing about what was sent. The value that caused it
		// is in the log, where somebody diagnosing this can read it.
		$response = mcm_http_post($server, '/create_list.php', array('list_name' => 'ok', 'list_rank' => $fixture['seed']), $cookie);
		t_same($bad_request, $response['body'], 'a refused rank gets the same body as every other bad request');
		t_lacks($fixture['seed'], $response['body'], 'the refused value never reaches the client');
		t_lacks($fixture['seed'], mcm_header_text($response), 'the refused value never reaches the response headers');
		t_contains($fixture['seed'], $response['log'], 'the refused value itself reaches the log');

		/* 3. Signed in, and the request is the shape the page sends. It gets
		 * past every check and stops at the database this fixture has none of,
		 * which is the only thing that says the checks above let it through. */
		$accepted = array(
			'the first list a user creates, at rank 0' => array('page' => 'create_list.php', 'fields' => array('list_name' => 'first', 'list_rank' => 0)),
			'a list at the last rank the column holds' => array('page' => 'create_list.php', 'fields' => array('list_name' => 'last', 'list_rank' => 255)),
			'a reorder of two lists' => array('page' => 'adjust_lists.php', 'fields' => array('stop_state' => '[2,1]', 'start_pos' => 0, 'stop_pos' => 1)),
			'a save that changed no sharing at all' => array('page' => 'share_lists.php', 'fields' => array('changed_lists' => '[]', 'share_vals' => '[]')),
			'a share value sent as a string' => array('page' => 'share_lists.php', 'fields' => array('changed_lists' => '[1]', 'share_vals' => '["1"]')),
		);
		foreach ($accepted as $description => $case) {
			$response = mcm_http_post($server, '/' . $case['page'], $case['fields'], $cookie);

			t_lacks($bad_request, $response['body'], $case['page'] . ' does not refuse ' . $description);
			t_contains('SQLSTATE', $response['log'], $case['page'] . ' got as far as the database for ' . $description);
		}
	} catch (Exception $exception) {
		t_ok(false, 'the list endpoint guard cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);
});

/*
 * ---------------------------------------------------------------------------
 * 18. The optional real-server database group
 * ---------------------------------------------------------------------------
 *
 * Everything above needs a PHP CLI and nothing else, and still does. This group
 * is the one exception, and it is optional: with no database server binary on
 * the machine it skips loudly and names what the run therefore did not cover.
 *
 * It exists for three classes of regression that are structurally invisible to
 * the rest of the suite, listed in mcm_db_uncovered() and asserted here:
 *
 *   1. a call that is present in a method but never reached. The per-method
 *      static check counts the call; only running the transition shows whether
 *      the path that renews the session identifier is the path a login takes.
 *   2. a value stored in a column too narrow to hold it. The width belongs to
 *      the tracked schema, so only the tracked schema can enforce it, and the
 *      symptom - a remember-me cookie that stops working - is a request later.
 *   3. a query whose WHERE clause stops restricting. The SQL still parses and
 *      still runs; what changes is which rows come back or get written.
 *
 * The machinery is in tests/database.php. No application file knows this group
 * exists: the server's address, database and credentials travel in DB_HOST,
 * DB_NAME, DB_USER and DB_PASS like any other configuration.
 */

t_group('real database', function () {
	$server = mcm_db_server();
	if ($server === null) {
		$reason = mcm_db_skip_reason();
		t_skip('the real-database group', $reason);
		mcm_db_print_skip($reason);
		return;
	}

	echo '  note  ' . $server['version'] . ', private instance on port ' . $server['port'] . "\n";

	// A server that is up but could not be given the tracked schema is a
	// failure, not a skip: nothing about it depends on the developer's machine.
	if (!t_same('', $server['schema_error'], 'the tracked schema loaded into the server')) {
		return;
	}

	// Passwords and tokens are generated rather than written down, so no
	// committed file holds anything shaped like a credential.
	$password     = 'p' . bin2hex(random_bytes(6));
	$new_password = 'n' . bin2hex(random_bytes(6));
	$activation   = bin2hex(random_bytes(20));
	$reset        = bin2hex(random_bytes(20));
	$wrong_token  = bin2hex(random_bytes(20));
	// Spelled into the fixture rather than left to its default, because this
	// group builds a remember-me cookie by hand and has to hash it with the key
	// the fixture runs on.
	$secret = 'k' . bin2hex(random_bytes(8));

	$fixture = mcm_db_fixture('real-database', $server, array('COOKIE_SECRET_KEY' => $secret));
	$pdo     = mcm_db_reset($server);

	// The schema really is the tracked one, loaded as it stands.
	$engines = array();
	foreach ($pdo->query('SHOW TABLE STATUS') as $status) {
		$engines[$status['Name']] = $status['Engine'];
	}
	t_ok(isset($engines['users']), 'the tracked schema created the users table', implode(', ', array_keys($engines)));
	t_same('MyISAM', isset($engines['users']) ? $engines['users'] : '', 'the users table is on the engine the tracked schema names');

	$columns = array();
	foreach ($pdo->query('SHOW COLUMNS FROM users') as $column) {
		$columns[$column['Field']] = $column['Type'];
	}
	t_contains('64', isset($columns['user_rememberme_token']) ? $columns['user_rememberme_token'] : '', 'the remember-me column is the width the token is generated to');

	$signed_in = mcm_db_seed_user($pdo, 'loginuser', $password);
	$pending   = mcm_db_seed_user($pdo, 'pendinguser', $password, array(
		'user_active'          => 0,
		'user_activation_hash' => $activation,
	));
	mcm_db_seed_user($pdo, 'resetuser', $password, array(
		'user_password_reset_hash'      => $reset,
		'user_password_reset_timestamp' => time(),
	));

	$server_handle = mcm_server_start($fixture);
	try {
		/* 1. Activation: a query that has to keep restricting ---------------- */

		// The wrong code first. Nothing about this can be seen without a server:
		// the WHERE clause is what refuses it, and the refusal is a row that did
		// not change.
		$response = mcm_http($server_handle, '/register.php?id=' . $pending . '&verification_code=' . $wrong_token);
		$row      = mcm_db_user_row($pdo, 'pendinguser');

		t_same(200, $response['status'], 'a wrong verification code still renders the page');
		t_contains('no such id/verification code', $response['body'], 'a wrong verification code is refused');
		t_same('0', (string) $row['user_active'], 'a wrong verification code leaves the account inactive');
		t_same($activation, $row['user_activation_hash'], 'a wrong verification code leaves the stored code in place');

		// An id that exists with a code that belongs to nobody is the same
		// answer; so is the right code against the wrong id.
		$response = mcm_http($server_handle, '/register.php?id=' . $signed_in . '&verification_code=' . $activation);
		t_contains('no such id/verification code', $response['body'], "another account's verification code is refused");
		t_same('0', (string) mcm_db_user_row($pdo, 'pendinguser')['user_active'], 'the pending account is still inactive');

		$response = mcm_http($server_handle, '/register.php?id=' . $pending . '&verification_code=' . $activation);
		$row      = mcm_db_user_row($pdo, 'pendinguser');

		t_contains('Activation was successful', $response['body'], 'the right verification code activates the account');
		t_same('1', (string) $row['user_active'], 'the account is active afterwards');
		t_same(null, $row['user_activation_hash'], 'the code is cleared, so it cannot be used twice');
		t_same('', $response['log'], 'activating an account logs nothing');

		/* 2. Login: a transition that has to renew the session identifier ---- */

		$existing = 'aaaabbbbccccdddd1111222233334444';
		mcm_seed_session($fixture, $existing, array('probe' => 'signed out'));

		$form = mcm_form_body(array(
			'login'           => '1',
			'user_name'       => 'loginuser',
			'user_password'   => $password,
			'user_rememberme' => '1',
		));
		$response = mcm_http($server_handle, '/index.php', array('Cookie: PHPSESSID=' . $existing), 'POST', $form);
		$session  = mcm_cookie_value($response, 'PHPSESSID');
		$cookie   = mcm_cookie_value($response, 'rememberme');

		t_same(301, $response['status'], 'a login posts and is redirected, as it always was');
		t_same('', $response['log'], 'a successful login logs nothing');

		// Class 1. The static check counts the call inside loginWithPostData();
		// this is the only thing that says the login ran it.
		t_ok($session !== '', 'a login hands back a session cookie', 'headers: ' . implode(' | ', mcm_header_values($response, 'Set-Cookie')));
		t_ok($session !== $existing, 'a login does not leave the visitor on the identifier they arrived with');

		$files = mcm_session_files($fixture);
		t_ok(in_array($session, $files, true), 'the signed-in session is the one held on the server');
		t_ok(!in_array($existing, $files, true), 'the identifier the browser arrived with no longer names a session');
		t_contains('user_logged_in', file_get_contents($fixture['sessions'] . '/sess_' . $session), 'the renewed session is the signed-in one');

		// Class 2. The token in the browser and the token in the column have to
		// be the same string, and the column decides how long that can be.
		// setcookie() percent-encodes the value, so the cookie travels encoded
		// and is decoded again on the way in. The harness hands it back exactly
		// as it arrived, and only reads it apart here.
		$row   = mcm_db_user_row($pdo, 'loginuser');
		$parts = explode(':', urldecode($cookie));

		t_same(3, count($parts), 'the remember-me cookie has its three parts', $cookie);
		t_same(64, strlen($row['user_rememberme_token']), 'the stored remember-me token is the width of its column');
		t_same($row['user_rememberme_token'], isset($parts[1]) ? $parts[1] : '', 'the token in the browser is the token in the database');
		t_same((string) $signed_in, isset($parts[0]) ? $parts[0] : '', 'the cookie names the user it was issued to');

		// The stored hash was written at the cheapest cost bcrypt accepts, so a
		// login is the moment it gets replaced - and the replacement has to be
		// in the column, not just in a variable.
		t_ok(password_verify($password, $row['user_password_hash']), 'the stored hash still verifies the password after the login');
		$info = password_get_info($row['user_password_hash']);
		t_same(10, isset($info['options']['cost']) ? $info['options']['cost'] : 0, 'the stored hash was recalculated at the configured cost');

		/* 3. Remember-me: the cookie has to work on the next request --------- */

		$response = mcm_http($server_handle, '/probe_login_state.php', array('Cookie: rememberme=' . $cookie));
		$report   = mcm_report($response['body']);
		$rotated  = mcm_cookie_value($response, 'rememberme');

		t_same('yes', $report['logged_in'], 'the remember-me cookie signs the visitor back in', $response['body']);
		t_same('loginuser', $report['user_name'], 'it signs in the account it was issued to');
		t_same('', $report['errors'], 'a valid remember-me cookie reports no error');
		t_ok($report['session_id'] !== $report['session_id_before'], 'a cookie login renews the session identifier too');

		// The token is used once: the database holds a new one and the cookie
		// the browser arrived with stops working.
		$row = mcm_db_user_row($pdo, 'loginuser');
		t_ok($rotated !== '' && $rotated !== $cookie, 'a cookie login issues a fresh cookie');
		$rotated_parts = explode(':', urldecode($rotated));
		t_same($row['user_rememberme_token'], isset($rotated_parts[1]) ? $rotated_parts[1] : '', 'the fresh cookie matches the token now stored');
		t_ok($row['user_rememberme_token'] !== $parts[1], 'the token that was just used is no longer the stored one');

		$response = mcm_http($server_handle, '/probe_login_state.php', array('Cookie: rememberme=' . $cookie));
		$report   = mcm_report($response['body']);
		t_same('no', $report['logged_in'], 'the used remember-me cookie does not sign anyone in a second time');
		t_contains('Invalid cookie', $report['errors'], 'the used cookie is reported as invalid');

		// A cookie whose hash is right - it is built here with the same secret
		// the fixture runs on - but whose token the database never held. Only
		// the query can refuse this one; every check before it passes.
		$never_issued = bin2hex(random_bytes(32));
		$forged       = $signed_in . ':' . $never_issued . ':'
			. hash('sha256', $signed_in . ':' . $never_issued . $secret);

		$response = mcm_http($server_handle, '/probe_login_state.php', array('Cookie: rememberme=' . $forged));
		$report   = mcm_report($response['body']);
		t_same('no', $report['logged_in'], 'a correctly signed cookie holding a token the database never had signs nobody in');
		t_contains('Invalid cookie', $report['errors'], 'the unknown token is reported as an invalid cookie');

		/* 4. Signing in with the wrong things -------------------------------- */

		$attempts = array(
			'a wrong password'    => array('user_name' => 'loginuser', 'user_password' => $password . 'x', 'expect' => 'Wrong password'),
			'an unknown account'  => array('user_name' => 'nosuchuser', 'user_password' => $password, 'expect' => 'This user does not exist'),
		);
		foreach ($attempts as $description => $attempt) {
			$form = mcm_form_body(array(
				'login'           => '1',
				'user_name'       => $attempt['user_name'],
				'user_password'   => $attempt['user_password'],
				'user_rememberme' => '1',
			));
			$response = mcm_http($server_handle, '/probe_login_state.php', array(), 'POST', $form);
			$report   = mcm_report($response['body']);

			t_same('no', $report['logged_in'], $description . ' does not sign anyone in');
			t_contains($attempt['expect'], $report['errors'], $description . ' is reported as such');
			t_same('', mcm_cookie_value($response, 'rememberme'), $description . ' issues no remember-me cookie');
		}

		// An account that exists and whose password is right, but which was
		// never activated. Only the stored user_active column says so.
		mcm_db_seed_user($pdo, 'inactiveuser', $password, array('user_active' => 0));
		$form = mcm_form_body(array(
			'login'         => '1',
			'user_name'     => 'inactiveuser',
			'user_password' => $password,
		));
		$response = mcm_http($server_handle, '/probe_login_state.php', array(), 'POST', $form);
		$report   = mcm_report($response['body']);

		t_same('no', $report['logged_in'], 'an unactivated account does not sign in');
		t_contains('not activated yet', $report['errors'], 'an unactivated account is told why');

		/* 5. Password reset: the other query that has to keep restricting ---- */

		$form = mcm_form_body(array(
			'submit_new_password'      => '1',
			'user_name'                => 'resetuser',
			'user_password_reset_hash' => $wrong_token,
			'user_password_new'        => $new_password,
			'user_password_repeat'     => $new_password,
		));
		$response = mcm_http($server_handle, '/password_reset.php', array(), 'POST', $form);
		$row      = mcm_db_user_row($pdo, 'resetuser');

		t_contains('password changing failed', $response['body'], 'a reset with the wrong code is refused');
		t_ok(password_verify($password, $row['user_password_hash']), 'a reset with the wrong code leaves the password alone');
		t_same($reset, $row['user_password_reset_hash'], 'a reset with the wrong code leaves the stored code in place');

		$existing = 'bbbbaaaaddddcccc4444333322221111';
		mcm_seed_session($fixture, $existing, array('probe' => 'resetting'));

		$form = mcm_form_body(array(
			'submit_new_password'      => '1',
			'user_name'                => 'resetuser',
			'user_password_reset_hash' => $reset,
			'user_password_new'        => $new_password,
			'user_password_repeat'     => $new_password,
		));
		$response = mcm_http($server_handle, '/password_reset.php', array('Cookie: PHPSESSID=' . $existing), 'POST', $form);
		$row      = mcm_db_user_row($pdo, 'resetuser');
		$session  = mcm_cookie_value($response, 'PHPSESSID');

		t_contains('Password successfully changed', $response['body'], 'a reset with the right code goes through');
		t_ok(password_verify($new_password, $row['user_password_hash']), 'the new password is the one now stored');
		t_ok(!password_verify($password, $row['user_password_hash']), 'the old password no longer verifies');
		t_same(null, $row['user_password_reset_hash'], 'the reset code is cleared, so it cannot be used twice');
		t_ok($session !== '' && $session !== $existing, 'a password reset renews the session identifier');
		t_same('', $response['log'], 'a password reset logs nothing');

		// And the account can be signed in to with the new password, which is
		// the only thing that proves the stored hash is usable rather than just
		// different.
		$form = mcm_form_body(array(
			'login'         => '1',
			'user_name'     => 'resetuser',
			'user_password' => $new_password,
		));
		$response = mcm_http($server_handle, '/probe_login_state.php', array(), 'POST', $form);
		t_same('yes', mcm_report($response['body'])['logged_in'], 'the account signs in with the new password');
	} catch (Exception $exception) {
		t_ok(false, 'the real-database cases ran', $exception->getMessage());
	}
	mcm_server_stop($server_handle);
});

/*
 * ---------------------------------------------------------------------------
 * 19. The three actors a list mutation has to tell apart
 * ---------------------------------------------------------------------------
 *
 * The group above proves that an anonymous or malformed request never reaches a
 * query. What it cannot prove without rows is the middle case: a request from
 * somebody who is signed in, sending an identifier that exists, for a list that
 * is not theirs. That is regression class 3 in mcm_db_uncovered() - a WHERE
 * clause that stops restricting still parses and still runs - and it needs a
 * real server, so it lives here and skips with the rest of this file.
 *
 * Every case asks the same two questions: what did the client get back, and
 * what do the rows say afterwards. The second is the one that matters, because
 * a refusal that has already written is still a refusal from the outside.
 */

t_group('real database: list ownership', function () {
	$server = mcm_db_server();
	if ($server === null) {
		// The group above has already printed the reason at length.
		t_skip('the list ownership cases', mcm_db_skip_reason());
		return;
	}
	if ($server['schema_error'] !== '') {
		t_ok(false, 'the list ownership cases have the tracked schema', $server['schema_error']);
		return;
	}

	$password = 'p' . bin2hex(random_bytes(6));
	$fixture  = mcm_db_fixture('list-ownership', $server);
	$pdo      = mcm_db_reset($server);

	$owner_id = mcm_db_seed_user($pdo, 'listowner', $password);
	$other_id = mcm_db_seed_user($pdo, 'otherowner', $password);

	// Signed-in sessions, seeded rather than logged in: what is under test here
	// is what the endpoints do with a session, not how one is issued.
	$owner_session = '11112222333344445555666677778888';
	$other_session = '88887777666655554444333322221111';
	mcm_seed_signed_in($fixture, $owner_session, array('user_name' => 'listowner', 'user_id' => $owner_id, 'user_logged_in' => 1));
	mcm_seed_signed_in($fixture, $other_session, array('user_name' => 'otherowner', 'user_id' => $other_id, 'user_logged_in' => 1));

	// Cookie and token together: what a browser sends once the page it is on
	// has been served by this site.
	$owner_cookie = mcm_session_headers($owner_session);
	$other_cookie = mcm_session_headers($other_session);

	$forbidden    = '{"error":"forbidden","message":"You are not allowed to do that."}';
	$unauthorised = '{"error":"authentication_required","message":"You must be signed in to do that."}';
	$wrong_method = '{"error":"method_not_allowed","message":"That request method is not allowed here."}';

	// Two lists for the owner and one for everybody else, each with a movie in
	// it, so a deletion has something of its own to take with it.
	// The other account gets two lists as well, so that a request mixing one of
	// theirs with one of somebody else's has something to move: a reorder that
	// puts a list back where it already was would prove nothing about whether
	// the write happened.
	$first        = mcm_db_seed_list($pdo, $owner_id, 'first list', 0);
	$second       = mcm_db_seed_list($pdo, $owner_id, 'second list', 1);
	$theirs       = mcm_db_seed_list($pdo, $other_id, 'their list', 0);
	$theirs_later = mcm_db_seed_list($pdo, $other_id, 'their second list', 1);
	mcm_db_seed_movie($pdo, $first, 550);
	mcm_db_seed_movie($pdo, $second, 551);
	mcm_db_seed_movie($pdo, $theirs, 552);
	mcm_db_seed_movie($pdo, $theirs_later, 553);

	$owner_state = mcm_db_list_state($pdo, $owner_id);
	$other_state = mcm_db_list_state($pdo, $other_id);

	$server_handle = mcm_server_start($fixture);
	try {
		/* 1. Anonymous: refused, and the rows are exactly as they were --------- */

		$anonymous = array(
			'create_list.php' => array('list_name' => 'a list nobody asked for', 'list_rank' => 2),
			'rename_list.php' => array('movie_list_id' => $first, 'list_name' => 'renamed by nobody'),
			'delete_list.php' => array('movie_list_id' => $first),
			'adjust_lists.php' => array('stop_state' => json_encode(array($second, $first)), 'start_pos' => 0, 'stop_pos' => 1),
			'share_lists.php' => array('changed_lists' => json_encode(array($first)), 'share_vals' => '[1]'),
		);
		foreach ($anonymous as $page => $fields) {
			$response = mcm_http_post($server_handle, '/' . $page, $fields);

			t_same(401, $response['status'], $page . ' refuses an anonymous request');
			t_same($unauthorised, $response['body'], $page . ' answers an anonymous request with the fixed body');
			t_same($owner_state, mcm_db_list_state($pdo, $owner_id), $page . ": an anonymous request left the owner's lists alone");
			t_same($other_state, mcm_db_list_state($pdo, $other_id), $page . ": an anonymous request left everybody else's lists alone");
		}
		t_same(1, mcm_db_movie_count($pdo, $first), 'an anonymous request deleted no movies');

		/* 2. Signed in, and not the owner ------------------------------------- */

		$trespass = array(
			'renaming it' => array('page' => 'rename_list.php', 'fields' => array('movie_list_id' => $first, 'list_name' => 'mine now')),
			'deleting it' => array('page' => 'delete_list.php', 'fields' => array('movie_list_id' => $first)),
			'reordering it' => array('page' => 'adjust_lists.php', 'fields' => array('stop_state' => json_encode(array($second, $first)), 'start_pos' => 0, 'stop_pos' => 1)),
			'sharing it' => array('page' => 'share_lists.php', 'fields' => array('changed_lists' => json_encode(array($first)), 'share_vals' => '[1]')),
			'un-sharing it' => array('page' => 'share_lists.php', 'fields' => array('changed_lists' => json_encode(array($first)), 'share_vals' => '[0]')),
		);
		foreach ($trespass as $description => $case) {
			$response = mcm_http_post($server_handle, '/' . $case['page'], $case['fields'], $other_cookie);

			t_same(403, $response['status'], 'a signed-in stranger is refused ' . $description);
			t_same($forbidden, $response['body'], 'a signed-in stranger gets the fixed body for ' . $description);
			t_lacks('greatsuccess', $response['body'], 'a signed-in stranger is not told ' . $description . ' worked');
			// The refusal never says whether the list exists, so the log is where
			// the reason is.
			t_contains('not owned by user ' . $other_id, $response['log'], 'the reason is logged for ' . $description);
			t_same($owner_state, mcm_db_list_state($pdo, $owner_id), "the owner's lists are untouched by " . $description);
		}
		t_same(1, mcm_db_movie_count($pdo, $first), 'a signed-in stranger deleted no movies');

		// A list that does not exist and a list belonging to somebody else are
		// the same answer, so a stranger cannot map out which ids are real.
		$response = mcm_http_post($server_handle, '/rename_list.php', array('movie_list_id' => 99999, 'list_name' => 'x'), $other_cookie);
		t_same(403, $response['status'], 'a list that does not exist is refused too');
		t_same($forbidden, $response['body'], 'a list that does not exist answers exactly as somebody else\'s does');

		// A reorder is all or nothing: one list in the range belonging to
		// somebody else and none of them moves, rather than half a reorder. The
		// caller's own list is first in the request and is being moved from rank
		// 1 to rank 0, so a write that happened before the refusal would show.
		$mixed = json_encode(array($theirs_later, $first));
		$response = mcm_http_post($server_handle, '/adjust_lists.php', array('stop_state' => $mixed, 'start_pos' => 0, 'stop_pos' => 1), $other_cookie);
		t_same(403, $response['status'], 'a reorder that reaches somebody else\'s list is refused');
		t_same($owner_state, mcm_db_list_state($pdo, $owner_id), 'a refused reorder moved none of the owner\'s lists');
		t_same($other_state, mcm_db_list_state($pdo, $other_id), 'a refused reorder moved none of the caller\'s own lists either');

		// The same, for sharing: the caller's own list is first in the request
		// and is not shared afterwards, because the other one in the request is
		// not theirs.
		$response = mcm_http_post($server_handle, '/share_lists.php', array(
			'changed_lists' => json_encode(array($theirs, $first)),
			'share_vals'    => '[1,1]',
		), $other_cookie);
		t_same(403, $response['status'], 'a share that reaches somebody else\'s list is refused');
		t_same($other_state, mcm_db_list_state($pdo, $other_id), 'a refused share left the caller\'s own list unshared');
		t_same($owner_state, mcm_db_list_state($pdo, $owner_id), 'a refused share left the owner\'s list unshared');

		/* 2b. The owner, asking the wrong way --------------------------------- */

		// These are the cases the rest of the suite cannot make: the caller owns
		// every list named, so nothing but the method and the token stands
		// between the request and a real write. Each one is checked against the
		// rows on both sides, because a refusal that has already written is
		// still a refusal from the outside.
		$owner_token = mcm_session_token($owner_session);
		$wrong_ways  = array(
			'a GET' => array(
				'method'  => 'GET',
				'headers' => $owner_cookie,
				'status'  => 405,
				'body'    => $wrong_method,
				'logged'  => 'is not allowed here',
			),
			'a POST with no token' => array(
				'method'  => 'POST',
				'headers' => array('Cookie: PHPSESSID=' . $owner_session),
				'status'  => 403,
				'body'    => $forbidden,
				'logged'  => 'no valid CSRF token',
			),
			"a POST carrying another session's token" => array(
				'method'  => 'POST',
				'headers' => array('Cookie: PHPSESSID=' . $owner_session, 'X-CSRF-Token: ' . mcm_session_token($other_session)),
				'status'  => 403,
				'body'    => $forbidden,
				'logged'  => 'no valid CSRF token',
			),
			'a POST whose token is one character out' => array(
				'method'  => 'POST',
				'headers' => array('Cookie: PHPSESSID=' . $owner_session, 'X-CSRF-Token: ' . substr($owner_token, 0, 63) . ($owner_token[63] === 'f' ? 'e' : 'f')),
				'status'  => 403,
				'body'    => $forbidden,
				'logged'  => 'no valid CSRF token',
			),
		);
		// Every write this endpoint set has, aimed at lists the caller owns.
		$owned = array(
			'create_list.php' => array('list_name' => 'a list nobody asked for', 'list_rank' => 4),
			'rename_list.php' => array('movie_list_id' => $first, 'list_name' => 'renamed the wrong way'),
			'delete_list.php' => array('movie_list_id' => $first),
			'adjust_lists.php' => array('stop_state' => json_encode(array($second, $first)), 'start_pos' => 0, 'stop_pos' => 1),
			'share_lists.php' => array('changed_lists' => json_encode(array($first)), 'share_vals' => '[1]'),
		);
		foreach ($wrong_ways as $description => $way) {
			foreach ($owned as $page => $fields) {
				$before = mcm_db_list_state($pdo, $owner_id);
				if ($way['method'] === 'GET') {
					$response = mcm_http($server_handle, '/' . $page . '?' . http_build_query($fields), $way['headers']);
				} else {
					$response = mcm_http_post($server_handle, '/' . $page, $fields, $way['headers']);
				}

				t_same($way['status'], $response['status'], $page . ' refuses ' . $description . ' from the owner');
				t_same($way['body'], $response['body'], $page . ' answers ' . $description . ' with the fixed body');
				t_lacks('greatsuccess', $response['body'], $page . ' does not tell ' . $description . ' it worked');
				t_lacks('movie_list_id:', $response['body'], $page . ' hands ' . $description . ' no identifier');
				t_contains($way['logged'], $response['log'], $page . ': the reason is logged for ' . $description);
				t_same($before, mcm_db_list_state($pdo, $owner_id), $page . ': ' . $description . ' changed no row of the owner\'s');
				t_same($other_state, mcm_db_list_state($pdo, $other_id), $page . ': ' . $description . ' changed nobody else\'s rows either');
			}
			// A creation would not show in the state of the lists that already
			// exist, so it is counted separately.
			t_same(2, count(mcm_db_list_state($pdo, $owner_id)), 'no list was created by ' . $description);
			t_same(1, mcm_db_movie_count($pdo, $first), 'no movie was deleted by ' . $description);
		}
		// However wrong the token was, it never reaches the log.
		t_lacks($owner_token, $response['log'], "the owner's own token is never logged");

		/* 3. The owner, doing all of it --------------------------------------- */

		// Creating. The identifier that comes back is the row that was inserted,
		// and the rank it was given already belongs to another of this user's
		// lists: the read-back this replaced looked a new list up by owner and
		// rank, so it would have answered with the first list's id and the page
		// would have attached every later change to that list instead.
		$response = mcm_http_post($server_handle, '/create_list.php', array(
			'list_name'        => 'a third list',
			'list_description' => '',
			'list_rank'        => 0,
		), $owner_cookie);
		$created = 0;
		if (t_matches('/^movie_list_id:[0-9]+$/', $response['body'], 'the owner creates a list and is told its identifier')) {
			$created = (int) substr($response['body'], 14);
		}
		$row = mcm_db_list_row($pdo, $created);

		t_ok($created > $second, 'the identifier is the row that was just inserted, not an older one with the same rank');
		t_same('a third list', isset($row['list_name']) ? $row['list_name'] : '', 'the new list holds the name that was sent');
		t_same((string) $owner_id, isset($row['user_id']) ? (string) $row['user_id'] : '', 'the new list belongs to the session that created it');
		t_same('', $response['log'], 'creating a list logs nothing');

		// The owner is the session's, never the request's: a submitted user_id
		// changes nothing about whose list this is.
		$response = mcm_http_post($server_handle, '/create_list.php', array(
			'list_name' => 'not theirs',
			'list_rank' => 3,
			'user_id'   => $other_id,
		), $owner_cookie);
		$claimed = (int) substr($response['body'], 14);
		t_same((string) $owner_id, (string) mcm_db_list_row($pdo, $claimed)['user_id'], 'a submitted owner is ignored: the list belongs to the session');

		// Renaming. The bytes that were submitted are the bytes that are stored;
		// escaping is what makes a name with markup in it safe to render.
		$markup = '<b>Amelie\'s</b> "list"';
		$response = mcm_http_post($server_handle, '/rename_list.php', array('movie_list_id' => $first, 'list_name' => $markup), $owner_cookie);

		t_same('greatsuccess', $response['body'], 'the owner renames their own list');
		t_same($markup, mcm_db_list_row($pdo, $first)['list_name'], 'the stored name is exactly what was submitted');
		t_same('their list', mcm_db_list_row($pdo, $theirs)['list_name'], 'renaming one list renames no other');

		// Sharing, both ways.
		$response = mcm_http_post($server_handle, '/share_lists.php', array(
			'changed_lists' => json_encode(array($first, $second)),
			'share_vals'    => '[1,1]',
		), $owner_cookie);
		t_same('greatsuccess', $response['body'], 'the owner shares their own lists');
		t_same('1', (string) mcm_db_list_row($pdo, $first)['share'], 'the first list is shared afterwards');
		t_same('1', (string) mcm_db_list_row($pdo, $second)['share'], 'the second list is shared afterwards');
		t_same('0', (string) mcm_db_list_row($pdo, $theirs)['share'], 'nobody else\'s list was shared along with them');

		$response = mcm_http_post($server_handle, '/share_lists.php', array(
			'changed_lists' => json_encode(array($first)),
			'share_vals'    => '[0]',
		), $owner_cookie);
		t_same('greatsuccess', $response['body'], 'the owner un-shares a list again');
		t_same('0', (string) mcm_db_list_row($pdo, $first)['share'], 'the list is not shared afterwards');
		t_same('1', (string) mcm_db_list_row($pdo, $second)['share'], 'the list that was not named keeps its setting');

		// Reordering. The order that comes back is the order that was sent.
		$order    = array($second, $first, $created, $claimed);
		$response = mcm_http_post($server_handle, '/adjust_lists.php', array(
			'stop_state' => json_encode($order),
			'start_pos'  => 0,
			'stop_pos'   => 3,
		), $owner_cookie);

		t_same('greatsuccess', $response['body'], 'the owner reorders their own lists');
		foreach ($order as $rank => $list_id) {
			t_same((string) $rank, (string) mcm_db_list_row($pdo, $list_id)['list_rank'], 'list at position ' . $rank . ' has that rank');
		}
		t_same('0', (string) mcm_db_list_row($pdo, $theirs)['list_rank'], 'reordering one account\'s lists does not renumber another\'s');

		// Deleting: the list, and the movies that were in it, and nothing else.
		$response = mcm_http_post($server_handle, '/delete_list.php', array('movie_list_id' => $first), $owner_cookie);

		t_same('greatsuccess', $response['body'], 'the owner deletes their own list');
		t_same(array(), mcm_db_list_row($pdo, $first), 'the list is gone');
		t_same(0, mcm_db_movie_count($pdo, $first), 'the movies that were in it are gone with it');
		t_same(1, mcm_db_movie_count($pdo, $second), 'the movies in the list that stayed are still there');
		t_same(1, mcm_db_movie_count($pdo, $theirs), 'nobody else\'s movies were deleted');
		t_same(1, mcm_db_movie_count($pdo, $theirs_later), 'nor the movies in their other list');
		t_ok(mcm_db_list_row($pdo, $theirs) !== array(), 'nobody else\'s list was deleted');
		t_same('', $response['log'], 'deleting a list logs nothing');

		// The remaining lists are re-ranked from 0, and only this account's.
		$ranks = array();
		foreach (mcm_db_list_state($pdo, $owner_id) as $line) {
			$parts   = explode(':', $line);
			$ranks[] = end($parts) === '' ? '' : $parts[2];
		}
		t_same(array('0', '1', '2'), $ranks, 'the lists that are left are ranked from zero with no gap');
		t_same('0', (string) mcm_db_list_row($pdo, $theirs)['list_rank'], 'the re-rank did not reach another account');
	} catch (Exception $exception) {
		t_ok(false, 'the list ownership cases ran', $exception->getMessage());
	}
	mcm_server_stop($server_handle);
});

/*
 * ---------------------------------------------------------------------------
 * 20. Escaping what the server renders
 * ---------------------------------------------------------------------------
 */

t_group('output escaping', function () {
	// One string that is hostile in all four destinations at once: it closes a
	// script element, opens its own, ends an attribute, and carries a scheme
	// separator and a slash for the URL case.
	$payload = '</script><script id="pwned">alert("1&2")</script>"><img src=x onerror=alert(1)> javascript:/x';
	// A list id is rendered into an attribute and into the tab's href, so it
	// gets a payload of its own rather than riding on the name's.
	$listId  = '7" onmouseover="alert(1)';

	$fixture = mcm_fixture('escaping');
	$server  = mcm_server_start($fixture);

	try {
		$response = mcm_http($server, '/escape.php?payload=' . rawurlencode($payload) . '&list_id=' . rawurlencode($listId));
		$body     = $response['body'];

		t_same(200, $response['status'], 'the escaping probe renders');
		t_same('', $response['log'], 'rendering a hostile string logs nothing');

		$xpath = mcm_dom($body);
		if ($xpath === null) {
			t_skip('the rendered contexts are parsed as HTML', 'this PHP has no DOM extension');
		} else {
			// Text context: the payload comes back as the text of one element,
			// character for character, and built no markup on the way.
			$text = mcm_element($xpath, 'text');
			t_ok($text !== null, 'the text context element is in the document');
			t_same($payload, $text === null ? '' : $text->textContent, 'a hostile string renders as literal text');
			t_same(0, $text === null ? -1 : $text->getElementsByTagName('*')->length, 'the text context grew no child elements');

			// Attribute context: the payload is the whole attribute value, so it
			// neither ended the attribute nor started another one.
			$attr = mcm_element($xpath, 'attr');
			t_ok($attr !== null, 'the attribute context element is in the document');
			t_same($payload, $attr === null ? '' : $attr->getAttribute('alt'), 'a hostile string renders as one attribute value');
			t_same('', $attr === null ? 'missing' : $attr->getAttribute('onerror'), 'the attribute context grew no event handler');

			// URL context: the payload survives as one query value and nothing
			// else - not a second parameter, not a scheme of its own.
			$link  = mcm_element($xpath, 'url');
			$href  = $link === null ? '' : $link->getAttribute('href');
			$query = array();
			parse_str(parse_url($href, PHP_URL_QUERY), $query);
			t_same('https', parse_url($href, PHP_URL_SCHEME), 'the link keeps the scheme the page gave it');
			t_same($payload, isset($query['q']) ? $query['q'] : '', 'a hostile string survives as one query value');
			t_same('1', isset($query['t']) ? $query['t'] : '', 'the parameter after it is still its own parameter');

			// The tab strip both pages render, with a hostile name and a hostile
			// list id.
			$tabs  = mcm_element($xpath, 'tabs');
			$items = $tabs === null ? null : $tabs->getElementsByTagName('li');
			t_same(1, $items === null ? -1 : $items->length, 'the tab strip holds exactly one tab');
			if ($items !== null && $items->length > 0) {
				$item   = $items->item(0);
				$anchor = $item->getElementsByTagName('a')->item(0);
				t_same($listId, $item->getAttribute('data-listid'), 'the list id is one attribute value');
				t_same('', $item->getAttribute('onmouseover'), 'the list id grew no event handler');
				t_same($payload, $anchor === null ? '' : $anchor->textContent, 'the list name is the literal text of the tab');
				t_same('#' . rawurlencode($listId), $anchor === null ? '' : $anchor->getAttribute('href'), 'the tab links to the encoded list id');
			}

			$panes = mcm_element($xpath, 'panes');
			$pane  = $panes === null ? null : $panes->getElementsByTagName('div')->item(0);
			t_same($listId, $pane === null ? '' : $pane->getAttribute('id'), 'the pane id is one attribute value');

			// Script context: the payload never closed the script element, so the
			// document has the one script the page wrote and no other.
			$scripts = $xpath->query('//script');
			t_same(1, $scripts->length, 'the page has exactly one script element');
			t_ok(mcm_element($xpath, 'pwned') === null, 'the payload started no element of its own');
		}

		// Read off the raw response as well: whatever a parser makes of it, the
		// bytes that would end the script or the attribute are not there.
		t_same(1, substr_count($body, '</script>'), 'nothing closed the script element early');
		t_lacks('<img src=x', $body, 'no tag from the payload reaches the page');
		t_lacks('<script id=', $body, 'the payload opens no script element of its own');
		t_contains('\\u003C', $body, 'the script block carries the payload as escaped JSON');
		t_contains('\\u0026', $body, 'the ampersand in the payload is escaped in the script block too');

		// Non-ASCII text is escaped, not mangled: this changes rendering only.
		$accented = "Amélie's <b>π</b> list";
		$response = mcm_http($server, '/escape.php?payload=' . rawurlencode($accented) . '&list_id=3');
		$xpath    = mcm_dom($response['body']);
		if ($xpath === null) {
			t_skip('a non-ASCII name survives rendering', 'this PHP has no DOM extension');
		} else {
			$text = mcm_element($xpath, 'text');
			t_same($accented, $text === null ? '' : $text->textContent, 'a non-ASCII name renders unchanged as text');
		}
		t_lacks('<b>', $response['body'], 'markup inside a non-ASCII name is still escaped');
	} catch (Exception $exception) {
		t_ok(false, 'the escaping cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	// Both pages that render the tab strip render it through the shared helper,
	// so neither can drift back to building the markup by hand.
	foreach (array('inc/views/logged_in.php', 'inc/views/share.php') as $view) {
		$path = MCM_REPO_ROOT . '/' . $view;
		t_same(1, mcm_count_calls($path, 'mcm_list_tab_html'), $view . ' renders its tabs through the shared helper');
		t_same(1, mcm_count_calls($path, 'mcm_list_pane_html'), $view . ' renders its panes through the shared helper');
		t_lacks('data-listid', file_get_contents($path), $view . ' builds no tab markup of its own');
		t_same(0, mcm_count_calls($path, 'json_encode'), $view . ' embeds its data through mcm_js() rather than raw JSON');
	}

	// Every page that renders: no request value reaches the markup unescaped.
	$rendering = array_merge(mcm_entry_points(MCM_REPO_ROOT), glob(MCM_REPO_ROOT . '/inc/views/*.php'));
	foreach ($rendering as $file) {
		$name = substr($file, strlen(MCM_REPO_ROOT) + 1);
		t_same(array(), mcm_escaping_problems($file), $name . ' reads no request value outside a statement that names an escaping helper');
	}

	// The check has to have teeth, so it is pointed at deliberately broken
	// copies. Nothing here touches the checkout.
	$fixture = mcm_fixture('escaping-checks');
	$broken  = array(
		'a value echoed into markup' => array(
			'source'  => "<?php\n\necho '<p>' . \$_GET['q'] . '</p>';\n",
			'problem' => '$_GET is rendered without an escaping helper',
		),
		'a value printed through printf' => array(
			'source'  => "<?php\n\nprintf('<p>%s</p>', \$_SESSION['user_name']);\n",
			'problem' => '$_SESSION is rendered without an escaping helper',
		),
		'a value concatenated into markup first' => array(
			'source'  => "<?php\n\n\$html = '<p>' . \$_POST['q'] . '</p>';\necho \$html;\n",
			'problem' => '$_POST is rendered without an escaping helper',
		),
		'a value echoed straight from a template' => array(
			'source'  => "<p><?php echo \$_GET['q']; ?></p>\n",
			'problem' => '$_GET is rendered without an escaping helper',
		),
		// The escaper is on the wrong value: a check that only asked whether the
		// statement mentions an escaper anywhere would accept this.
		'a statement that escapes a different value' => array(
			'source'  => "<?php\n\necho mcm_html(\$title) . '<p>' . \$_GET['q'] . '</p>';\n",
			'problem' => '$_GET is rendered without an escaping helper',
		),
	);

	foreach ($broken as $description => $case) {
		$path = $fixture['public'] . '/broken_escaping.php';
		file_put_contents($path, $case['source']);
		t_contains($case['problem'], implode(' | ', mcm_escaping_problems($path)), 'the check rejects ' . $description);
	}

	// And it has to stay quiet about the things that are not rendering, or the
	// application could not keep reading its own request values.
	$clean = array(
		'an escaped value'             => "<?php\n\necho mcm_html(\$_GET['q']);\n",
		'a value escaped for a URL'    => "<?php\n\nprintf('<a href=\"?q=%s\">go</a>', mcm_url(\$_GET['q']));\n",
		'a value escaped for a script' => "<?php\n\necho '<script>var q = ' . mcm_js(\$_GET['q']) . '</script>';\n",
		'reading a request value'      => "<?php\n\n\$q = (isset(\$_GET['q'])) ? \$_GET['q'] : '';\n",
		'binding a request value'      => "<?php\n\n\$query->bindValue(':user_id', \$_SESSION['user_id'], PDO::PARAM_INT);\n",
	);

	foreach ($clean as $description => $source) {
		$path = $fixture['public'] . '/clean_escaping.php';
		file_put_contents($path, $source);
		t_same(array(), mcm_escaping_problems($path), 'the check accepts ' . $description);
	}
	unlink($fixture['public'] . '/broken_escaping.php');
	unlink($fixture['public'] . '/clean_escaping.php');
});

/*
 * ---------------------------------------------------------------------------
 * 21. Bounded validation of a submitted list name
 * ---------------------------------------------------------------------------
 */

t_group('list name validation', function () {
	$fixture = mcm_fixture('list-names');
	$report  = mcm_report(mcm_cli($fixture, 'list_name.php')['stdout']);

	// Accepted. Markup and quotes are names like any other: they are stored as
	// typed, and it is the rendering that makes them harmless.
	$accepted = array('plain', 'markup', 'quotes', 'accented', 'emoji', 'sixty_four', 'sixty_four_mb');
	foreach ($accepted as $name) {
		t_same('', isset($report[$name]) ? $report[$name] : '(absent)', 'a ' . str_replace('_', ' ', $name) . ' list name is accepted');
	}

	// Rejected, each for its own stated reason.
	$rejected = array(
		'empty'        => 'No list name given.',
		'spaces_only'  => 'No list name given.',
		'sixty_five'   => 'List name is longer than 64 characters.',
		'newline'      => 'List name contains a control character.',
		'null_byte'    => 'List name contains a control character.',
		'invalid_utf8' => 'List name is not valid UTF-8.',
	);
	foreach ($rejected as $name => $reason) {
		t_same($reason, isset($report[$name]) ? $report[$name] : '(absent)', 'a ' . str_replace('_', ' ', $name) . ' list name is refused');
	}

	// The two pages that write a list name run that validation before they touch
	// the database, so the refusal is observable without one. Both refuse a
	// request that is not a signed-in POST carrying this session's token before
	// that, so these are exactly that kind of request.
	$signed_in = 'e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1';
	mcm_seed_signed_in($fixture, $signed_in, array('user_name' => 'name-user', 'user_id' => 5, 'user_logged_in' => 1));

	$server = mcm_server_start($fixture);
	try {
		$long    = str_repeat('a', 65);
		$headers = mcm_session_headers($signed_in);
		$pages   = array(
			'create_list.php' => array('list_rank' => 0),
			'rename_list.php' => array('movie_list_id' => 1),
		);
		foreach ($pages as $page => $fields) {
			$response = mcm_http_post($server, '/' . $page, $fields + array('list_name' => $long), $headers);

			t_same(200, $response['status'], $page . ' answers a name that is too long');
			t_same('Error: List name is longer than 64 characters.', $response['body'], $page . ' refuses a name that is too long');
			t_same('', $response['log'], $page . ' never reached the database');

			// A name full of markup is not what validation is for: it goes
			// through, and gets as far as the database this fixture has none of.
			$response = mcm_http_post($server, '/' . $page, $fields + array('list_name' => '<script>alert(1)</script>'), $headers);
			t_lacks('Error: List name', $response['body'], $page . ' stores a name containing markup rather than refusing it');
			t_contains('mcm', $response['log'], $page . ' got past validation and on to the database');
		}
	} catch (Exception $exception) {
		t_ok(false, 'the list name cases ran over HTTP', $exception->getMessage());
	}
	mcm_server_stop($server);

	// Validation rejects; it never rewrites. Nothing on the write path may edit
	// the value on its way into the database, or an existing name would come
	// back changed the next time its list was renamed.
	$rewriters = array('htmlspecialchars', 'htmlentities', 'strip_tags', 'preg_replace', 'str_replace', 'filter_var');
	foreach (array('create_list.php', 'rename_list.php') as $page) {
		foreach ($rewriters as $rewriter) {
			t_same(0, mcm_count_calls(MCM_REPO_ROOT . '/' . $page, $rewriter), $page . ' does not ' . $rewriter . '() the submitted name');
		}
	}

	$validation = mcm_function_source(MCM_REPO_ROOT . '/inc/bootstrap.php', 'mcm_list_name_error');
	t_ok($validation !== '', 'the validation function was found in the bootstrap');
	foreach ($rewriters as $rewriter) {
		t_lacks($rewriter, $validation, 'the validation does not ' . $rewriter . '() the name it is given');
	}
	t_contains('preg_match', $validation, 'the validation matches the name rather than rewriting it');
});

/*
 * ---------------------------------------------------------------------------
 * 22. The baseline headers every response carries
 * ---------------------------------------------------------------------------
 */

t_group('security headers', function () {
	/* The default fixture, over the wire ---------------------------------------- */

	$fixture = mcm_fixture('headers');
	$server  = mcm_server_start($fixture);

	try {
		// A page nobody is signed in for.
		$response = mcm_http($server, '/probe.php');
		t_same(200, $response['status'], 'the probe page renders with the headers on');
		t_same(array('nosniff'), mcm_header_values($response, 'X-Content-Type-Options'), 'a public page says its type is not to be guessed');
		t_same(array('SAMEORIGIN'), mcm_header_values($response, 'X-Frame-Options'), 'a public page refuses to be framed elsewhere');
		t_same(array('strict-origin-when-cross-origin'), mcm_header_values($response, 'Referrer-Policy'), 'a public page tells another origin no more than this one');
		t_same(
			array('camera=(), geolocation=(), microphone=(), payment=()'),
			mcm_header_values($response, 'Permissions-Policy'),
			'a public page switches off the device features no page here uses'
		);

		// The whole point of this policy: it describes, it does not enforce.
		// Both halves matter, so both are asserted - the report-only header is
		// there, and the header that would refuse content is not.
		$policy = mcm_header_values($response, 'Content-Security-Policy-Report-Only');
		t_same(1, count($policy), 'exactly one report-only content policy is sent');
		t_same(array(), mcm_header_values($response, 'Content-Security-Policy'), 'no enforcing content policy is sent');

		$policy = isset($policy[0]) ? $policy[0] : '';
		t_contains("default-src 'self'", $policy, 'the policy starts from this origin only');
		t_contains("object-src 'self'", $policy, 'the policy allows no plugin content from anywhere else');
		t_contains("base-uri 'self'", $policy, 'the policy pins the document base to this origin');
		t_contains("form-action 'self'", $policy, 'the policy submits forms to this origin');
		t_contains("frame-ancestors 'self'", $policy, 'the policy names who may frame these pages');
		t_lacks('report-uri', $policy, 'no report address is sent when none is configured');

		// What the pages actually load. A policy that left any of these out
		// would report on every page view and say nothing worth reading.
		t_contains('ajax.googleapis.com', $policy, 'the policy names the script host the markup loads jQuery from');
		t_contains('netdna.bootstrapcdn.com', $policy, 'the policy names the host the markup loads Bootstrap from');
		t_contains('api.themoviedb.org', $policy, 'the policy names the search endpoint the type-ahead calls');
		t_contains('www.youtube.com', $policy, 'the policy names the video host the trailer dialog frames');
		t_contains("'unsafe-inline'", $policy, 'the policy admits the inline blocks the views are built out of');
		// Both of these describe something the site does today rather than
		// something it should do. A policy that reported on every search and
		// every signed-in page view would be read once and then ignored.
		t_contains("'unsafe-eval'", $policy, 'the policy admits the template compilation the search box does');

		// Deferred on purpose, and the assertion has teeth only next to one
		// that proves the header-sending code ran on this same response.
		t_same(array(), mcm_header_values($response, 'Strict-Transport-Security'), 'a page carries no strict transport security header');

		// A page a signed-in visitor sees. The session is seeded rather than
		// logged in to, so the case needs no database.
		$existing = 'aaaabbbbccccddddeeeeffff00001111';
		mcm_seed_session($fixture, $existing, array('user_name' => 'signed-in', 'user_id' => 3));

		$response = mcm_http($server, '/probe.php', array('Cookie: PHPSESSID=' . $existing));
		$report   = mcm_report($response['body']);
		t_same($existing, $report['session_id'], 'the signed-in session is the one being served');
		t_same(array('nosniff'), mcm_header_values($response, 'X-Content-Type-Options'), 'a signed-in page says its type is not to be guessed');
		t_same(array('SAMEORIGIN'), mcm_header_values($response, 'X-Frame-Options'), 'a signed-in page refuses to be framed elsewhere');
		t_same(1, count(mcm_header_values($response, 'Content-Security-Policy-Report-Only')), 'a signed-in page carries the report-only policy');
		t_same(array(), mcm_header_values($response, 'Content-Security-Policy'), 'a signed-in page sends no enforcing content policy');

		// The captcha is the one include-tree file the browser requests
		// directly, and it sets headers of its own.
		$response = mcm_http($server, '/inc/showCaptcha.php');
		t_same(200, $response['status'], 'the captcha endpoint renders with the headers on');
		t_same("\x89PNG", substr($response['body'], 0, 4), 'the captcha body is still a real PNG');
		t_same(array('nosniff'), mcm_header_values($response, 'X-Content-Type-Options'), 'the captcha carries the baseline headers too');
		t_contains('image/png', implode('', mcm_header_values($response, 'Content-Type')), 'the captcha keeps its own content type');
		t_contains('no-cache', implode('', mcm_header_values($response, 'Pragma')), 'the captcha keeps its own caching headers');
		t_same('', $response['log'], 'the captcha still logs nothing');
	} catch (Exception $exception) {
		t_ok(false, 'the security header cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	/* A redirect is a response too ---------------------------------------------- */

	$fixture = mcm_fixture('headers-redirect', array('config' => array(
		'MCM_CANONICAL_HOST' => 'movies.example',
	)));
	$server = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe.php');
		t_same(302, $response['status'], 'the plain-HTTP request is still redirected');
		t_same(array('nosniff'), mcm_header_values($response, 'X-Content-Type-Options'), 'the redirect carries the baseline headers');
		t_same(1, count(mcm_header_values($response, 'Content-Security-Policy-Report-Only')), 'the redirect carries the report-only policy');
		t_same(array(), mcm_header_values($response, 'Strict-Transport-Security'), 'the redirect still carries no strict transport security header');
	} catch (Exception $exception) {
		t_ok(false, 'the redirect header cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	/* What a site can change from its own configuration -------------------------- */

	$fixture = mcm_fixture('headers-off', array('config' => array('MCM_SECURITY_HEADERS' => false)));
	$server  = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe.php');
		t_same(200, $response['status'], 'a page still renders with the headers switched off');
		t_same(array(), mcm_header_values($response, 'X-Content-Type-Options'), 'the configuration can take the baseline headers back off');
		t_same(array(), mcm_header_values($response, 'X-Frame-Options'), 'the frame refusal goes with them');
		t_same(array(), mcm_header_values($response, 'Content-Security-Policy-Report-Only'), 'the content policy goes with them');
	} catch (Exception $exception) {
		t_ok(false, 'the headers-off cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	$fixture = mcm_fixture('headers-no-policy', array('config' => array('MCM_CONTENT_SECURITY_POLICY' => '')));
	$server  = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe.php');
		t_same(array(), mcm_header_values($response, 'Content-Security-Policy-Report-Only'), 'an empty policy leaves the header off');
		t_same(array('nosniff'), mcm_header_values($response, 'X-Content-Type-Options'), 'dropping the policy keeps the other headers');
	} catch (Exception $exception) {
		t_ok(false, 'the empty-policy cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	$fixture = mcm_fixture('headers-own-policy', array('config' => array(
		'MCM_CONTENT_SECURITY_POLICY' => "default-src 'none'; img-src 'self'",
		'MCM_CSP_REPORT_URI'          => 'https://reports.example/csp',
	)));
	$server = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe.php');
		$policy   = mcm_header_values($response, 'Content-Security-Policy-Report-Only');
		t_same(
			array("default-src 'none'; img-src 'self'; report-uri https://reports.example/csp"),
			$policy,
			'a configured policy and report address reach the response header'
		);
		t_same(array(), mcm_header_values($response, 'Content-Security-Policy'), 'a configured policy is still only ever report-only');
		t_same('', $response['log'], 'a usable report address is not complained about');
	} catch (Exception $exception) {
		t_ok(false, 'the configured-policy cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	// A report address carrying a directive separator would silently become a
	// directive of its own, so it is refused and the policy still goes out.
	$fixture = mcm_fixture('headers-bad-report', array('config' => array(
		'MCM_CSP_REPORT_URI' => "https://reports.example/csp; script-src *",
	)));
	$server = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe.php');
		$policy   = mcm_header_values($response, 'Content-Security-Policy-Report-Only');
		t_same(1, count($policy), 'an unusable report address does not cost the policy');
		t_lacks('report-uri', implode('', $policy), 'an unusable report address is not used');
		t_lacks('script-src *', implode('', $policy), 'an unusable report address adds no directive of its own');
		t_contains('MCM_CSP_REPORT_URI is not a usable address', $response['log'], 'an unusable report address is logged');
	} catch (Exception $exception) {
		t_ok(false, 'the unusable-report-address cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	/* The one fact a response cannot show ---------------------------------------- */

	// Nothing in a passing response proves the enforcing header could never be
	// sent; only the code that names the headers can say that. Read from the
	// one function that builds them, so no other part of the file can satisfy it.
	$source = mcm_function_source(MCM_REPO_ROOT . '/inc/bootstrap.php', 'mcm_security_headers');
	t_ok($source !== '', 'the header list was found in the bootstrap', 'mcm_security_headers() is not declared there');
	t_contains('Content-Security-Policy-Report-Only', $source, 'the header list names the report-only policy header');
	t_not_matches(
		'/Content-Security-Policy(?!-Report-Only)/',
		$source,
		'the header list never names the enforcing policy header'
	);
});

/*
 * ---------------------------------------------------------------------------
 * 23. The attributes on a cookie that is not the session cookie
 * ---------------------------------------------------------------------------
 */

t_group('cookie hardening', function () {
	$fixture = mcm_fixture('remember-cookie');
	$server  = mcm_server_start($fixture);

	try {
		// Read from this cookie's own Set-Cookie line: the session cookie in the
		// same response carries HttpOnly and SameSite of its own, and over HTTPS
		// Secure too, so a pattern run across both proves nothing about this one.
		$response = mcm_http($server, '/probe_set_cookie.php');
		$cookie   = mcm_cookie_header($response, 'rememberme');

		t_same(200, $response['status'], 'the cookie probe renders');
		t_ok($cookie !== '', 'a remember-me cookie is issued', mcm_header_text($response));
		t_matches('/;\s*HttpOnly(;|$)/i', $cookie, 'the remember-me cookie is HttpOnly, so no script can read it');
		t_matches('/;\s*SameSite=Lax(;|$)/i', $cookie, 'the remember-me cookie is SameSite=Lax');
		t_matches('/;\s*path=\/(;|$)/i', $cookie, 'the remember-me cookie is still scoped to the whole site');
		t_matches('/;\s*domain=\.?example\.test(;|$)/i', $cookie, 'the remember-me cookie keeps the configured domain');
		t_matches('/;\s*expires=/i', $cookie, 'the remember-me cookie still outlives the browser session');
		t_not_matches('/;\s*secure(;|$)/i', $cookie, 'the remember-me cookie is not secure over plain HTTP');

		// The same cookie on a request the web server terminated TLS for.
		$response = mcm_http($server, '/probe_set_cookie_https.php');
		$cookie   = mcm_cookie_header($response, 'rememberme');
		t_matches('/;\s*secure(;|$)/i', $cookie, 'the remember-me cookie is secure when the request arrived over HTTPS');
		t_matches('/;\s*HttpOnly(;|$)/i', $cookie, 'the secure cookie is HttpOnly as well');
	} catch (Exception $exception) {
		t_ok(false, 'the remember-me cookie cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	// One switch decides this for every cookie the application sets, so a site
	// that has finished moving to HTTPS pins them all at once.
	$fixture = mcm_fixture('remember-cookie-secure', array('config' => array(
		'MCM_SESSION_COOKIE_SECURE'   => true,
		'MCM_SESSION_COOKIE_SAMESITE' => 'Strict',
	)));
	$server = mcm_server_start($fixture);
	try {
		$response = mcm_http($server, '/probe_set_cookie.php');
		$cookie   = mcm_cookie_header($response, 'rememberme');
		t_matches('/;\s*secure(;|$)/i', $cookie, 'a configured secure flag reaches the remember-me cookie too');
		t_matches('/;\s*SameSite=Strict(;|$)/i', $cookie, 'a configured SameSite reaches the remember-me cookie too');
	} catch (Exception $exception) {
		t_ok(false, 'the configured remember-me cookie cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);

	/* Which code path the attributes are on -------------------------------------- */

	// A response can show that the helper adds the attributes; only the login
	// code can show that the remember-me cookie goes through the helper. Both
	// methods are read on their own, so neither can be satisfied by the other.
	$login = MCM_REPO_ROOT . '/inc/classes/Login.php';

	t_same(1, mcm_method_calls($login, 'newRememberMeCookie', 'mcm_set_cookie'), 'issuing a remember-me cookie goes through the helper');
	t_same(0, mcm_method_calls($login, 'newRememberMeCookie', 'setcookie'), 'issuing a remember-me cookie sets no bare cookie of its own');
	t_same(1, mcm_method_calls($login, 'deleteRememberMeCookie', 'mcm_set_cookie'), 'clearing a remember-me cookie goes through the helper');
	t_same(0, mcm_method_calls($login, 'deleteRememberMeCookie', 'setcookie'), 'clearing a remember-me cookie sets no bare cookie of its own');

	// The helper decides the three protective attributes itself rather than
	// taking them from the caller, which is what makes the two calls above a
	// complete account of the cookie a visitor receives.
	$helper = mcm_function_source(MCM_REPO_ROOT . '/inc/bootstrap.php', 'mcm_set_cookie');
	t_ok($helper !== '', 'the cookie helper was found in the bootstrap', 'mcm_set_cookie() is not declared there');
	t_contains('httponly', $helper, 'the helper sets HttpOnly itself');
	t_contains('MCM_SESSION_COOKIE_SAMESITE', $helper, 'the helper takes SameSite from the configuration');
	t_contains('mcm_cookie_secure', $helper, 'the helper takes the secure flag from the one place that decides it');
});

/*
 * ---------------------------------------------------------------------------
 * 24. Who may add, delete, move and import movies
 * ---------------------------------------------------------------------------
 *
 * The four movie mutation endpoints are the first to adopt the shared guards,
 * and what they now refuse is asserted in three places for three reasons.
 *
 * This group needs no database, and that is the point of it: the fixture is
 * pointed at an address nothing is listening on, so a request that was refused
 * before it ever tried to connect leaves an empty log, and a request that got
 * as far as the database says so in the log. "Nobody signed in changed
 * nothing" is therefore observable without a database at all - the request
 * stopped before the connection, so there was nothing it could have changed.
 *
 * The group after it puts the same endpoints in front of a real server with
 * real rows, which is the only way to ask who owns which list; and the one
 * after that reads the endpoints themselves, because the order of two guards
 * is a fact about the source that a passing request cannot demonstrate.
 */

t_group('movie endpoints refuse before the database', function () {
	// Port 1 is privileged: nothing here can be listening on it, so any request
	// that reaches the connection fails there and says so.
	$fixture = mcm_fixture('movie-guards', array('config' => array('DB_HOST' => '127.0.0.1;port=1')));

	$signed_in  = 'e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1';
	$signed_out = 'e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2';
	mcm_seed_signed_in($fixture, $signed_in, array('user_name' => 'signed-in-user', 'user_id' => 7, 'user_logged_in' => 1));
	mcm_seed_session($fixture, $signed_out, array('user_name' => 'signed-out-user', 'user_logged_in' => 0));

	$as_signed_in = mcm_session_headers($signed_in);
	$unauthorised = '{"error":"authentication_required","message":"You must be signed in to do that."}';
	$forbidden    = '{"error":"forbidden","message":"You are not allowed to do that."}';
	$wrong_method = '{"error":"method_not_allowed","message":"That request method is not allowed here."}';

	// A complete, well-formed request for each endpoint, so that nothing is
	// refused for a missing parameter and the refusal under test is the only
	// reason anything stopped.
	$requests = array(
		'add_movie.php' => array(
			'movie_list_id'       => 11,
			'tmdb_movie_id'       => 550,
			'tmdb_title'          => 'A Film',
			'tmdb_original_title' => 'A Film',
			'tmdb_poster_path'    => '/poster.jpg',
			'tmdb_release_date'   => '1999-10-15',
		),
		'delete_movie.php' => array('movie_list_id' => 11, 'tmdb_movie_id' => 550),
		'move.php'         => array('from_list' => 11, 'to_list' => 12, 'movie_id' => 550),
		'import_list.php'  => array('movie_list_id' => 11, 'tmdb_list_id' => '5212934a760ee36af148407c'),
	);

	$server = mcm_server_start($fixture);
	try {
		foreach ($requests as $page => $fields) {
			$visitors = array(
				'a visitor with no session' => array(),
				'a signed-out session'      => array('Cookie: PHPSESSID=' . $signed_out),
			);
			foreach ($visitors as $description => $headers) {
				$response = mcm_http_post($server, '/' . $page, $fields, $headers);

				t_same(401, $response['status'], $page . ' refuses ' . $description);
				t_same($unauthorised, $response['body'], $page . ' answers ' . $description . ' with the shared fixed body');
				t_lacks('greatsuccess', $response['body'], $page . ' does not tell ' . $description . ' that it worked');
				t_contains('no signed-in user', $response['log'], $page . ' logs why it refused ' . $description);
				// The absent connection failure is the whole assertion: this
				// fixture has no database to reach, so a request that got as far
				// as connecting would have logged the refusal of that
				// connection. This one did not, so there was nothing it could
				// have written.
				t_lacks('Database error', $response['log'], $page . ' stops ' . $description . ' before it reaches the database');
			}

			// A GET is not a way around any of this. It is refused on the
			// method alone, before the session is even consulted, so the
			// answer is the same whether or not anybody is signed in behind it
			// and whether or not it carries a token.
			foreach (array('an unauthenticated' => array(), "the owner's" => $as_signed_in) as $whose => $headers) {
				$response = mcm_http($server, '/' . $page . '?' . http_build_query($fields), $headers);

				t_same(405, $response['status'], $page . ' refuses ' . $whose . ' GET');
				t_same($wrong_method, $response['body'], $page . ' answers ' . $whose . ' GET with the shared fixed body');
				t_contains('POST', implode('', mcm_header_values($response, 'Allow')), $page . ' says which method it does allow');
				t_lacks('Database error', $response['log'], $page . ' stops ' . $whose . ' GET before it reaches the database');
			}

			// A signed-in POST with no token gets no further. The connection is
			// opened after the token is checked, so this too leaves no driver
			// error behind - which is what says nothing could have been written.
			$response = mcm_http_post($server, '/' . $page, $fields, array('Cookie: PHPSESSID=' . $signed_in));

			t_same(403, $response['status'], $page . ' refuses a signed-in request with no token');
			t_same($forbidden, $response['body'], $page . ' answers a request with no token with the shared fixed body');
			t_contains('no valid CSRF token', $response['log'], $page . ' logs why it refused a request with no token');
			t_lacks('Database error', $response['log'], $page . ' stops a request with no token before it reaches the database');

			// A signed-in visitor whose request carries the token gets past all
			// three guards and on to the database, which is where the question
			// of whose list this is has to be settled. Here that connection is
			// the one that fails.
			$response = mcm_http_post($server, '/' . $page, $fields, $as_signed_in);

			t_same(500, $response['status'], $page . ' lets a signed-in visitor through to the database');
			t_same(mcm_generic_message(), $response['body'], $page . ' gives the generic message when the database is down');
			t_lacks('greatsuccess', $response['body'], $page . ' does not claim it worked when the database is down');
			t_contains('connection failed', $response['log'], $page . ' logs the connection it could not open');
			t_contains(substr($page, 0, strpos($page, '.')), $response['log'], $page . ' names itself in the log');
		}

		// An import is the one endpoint that would otherwise send a request of
		// its own, so it is worth saying separately: neither an anonymous
		// request nor an authenticated one reaches TMDb before the list it
		// would write into has been settled.
		$tmdb = array('movie_list_id' => 11, 'tmdb_list_id' => '5212934a760ee36af148407c');
		foreach (array('an anonymous' => array(), 'a signed-in' => $as_signed_in) as $description => $headers) {
			$response = mcm_http_post($server, '/import_list.php', $tmdb, $headers);
			t_lacks('Method failed', $response['log'], $description . ' import contacts nothing outside this site');
			t_lacks('themoviedb', $response['log'], $description . ' import names no external service in the log');
		}
	} catch (Exception $exception) {
		t_ok(false, 'the movie endpoint refusal cases ran', $exception->getMessage());
	}
	mcm_server_stop($server);
});

t_group('import_list.php source checks of last resort', function () {
	// Guard order and scope for every endpoint, including import_list.php's own
	// ownership guard, are proven behaviourally by 'movie ownership over a real
	// database': a missing or misplaced guard, a move missing either end, an
	// unscoped duplicate check, a GET that still mutates and a POST that mutates
	// without a CSRF token all show up there, because a refused request is
	// snapshotted before and after and a permitted one is checked against what
	// it actually wrote. What is left here is only what that group cannot
	// reach: import_list.php's own call out to TMDb, which no case in this
	// suite makes, so nothing behavioural can show where its ownership check
	// sits relative to that call, or how its duplicate query scopes its WHERE
	// clause. These read the source because the path cannot be executed here,
	// and reading the source proves what the text says, not what a request does.
	$import_source = mcm_flat_source(MCM_REPO_ROOT . '/import_list.php');
	$ownership     = strpos($import_source, 'mcm_require_list_owner');
	$tmdb_call     = strpos($import_source, 'getList');

	t_ok($ownership !== false && $tmdb_call !== false, 'import_list.php has both an ownership check and a call to TMDb');
	t_ok($ownership < $tmdb_call, 'the ownership check appears before the call to TMDb in import_list.php\'s source');

	t_contains(
		'JOIN movie_lists b ON a.movie_list_id = b.movie_list_id WHERE tmdb_movie_id = :tmdb_movie_id AND user_id = :user_id',
		$import_source,
		'import_list.php\'s duplicate lookup query text is scoped to one account'
	);
});

/*
 * ---------------------------------------------------------------------------
 * 25. The movie authorization matrix, over real rows
 * ---------------------------------------------------------------------------
 *
 * Who owns which list is a question only a database can answer, so this is the
 * group that answers it: two accounts, three lists and real movies, and one
 * request per square of the matrix. Every refusal is checked twice - once for
 * what the client was told, and once for the state of every row afterwards,
 * because a refusal that answered 403 and still wrote is the failure this
 * change exists to prevent.
 *
 * Like the group it shares its machinery with, it skips loudly where there is
 * no server binary to run. What it cannot cover either way is a successful
 * import: that one calls TMDb over the network, which no case here does. The
 * refusals happen before that call, so they are covered; what an authorized
 * import then writes is not.
 */

t_group('movie ownership over a real database', function () {
	$server = mcm_db_server();
	if ($server === null) {
		t_skip('the movie ownership cases', mcm_db_skip_reason());
		return;
	}
	if (!t_same('', $server['schema_error'], 'the tracked schema loaded for the movie cases')) {
		return;
	}

	$fixture = mcm_db_fixture('movie-ownership', $server);
	$pdo     = mcm_db_reset_collection($server);

	// Two accounts with lists of their own, and one film each to start with.
	$alice = mcm_db_seed_user($pdo, 'alice', 'p' . bin2hex(random_bytes(6)));
	$bob   = mcm_db_seed_user($pdo, 'bob', 'p' . bin2hex(random_bytes(6)));

	$alice_one = mcm_db_seed_list($pdo, $alice, 'alice one', 0);
	$alice_two = mcm_db_seed_list($pdo, $alice, 'alice two', 1);
	$bob_one   = mcm_db_seed_list($pdo, $bob, 'bob one', 0);

	mcm_db_seed_master_movie($pdo, 101, 'Movie One', '2001-01-01');
	mcm_db_seed_master_movie($pdo, 102, 'Movie Two', '2002-02-02');

	$alice_row = mcm_db_seed_movie($pdo, $alice_one, 101);
	$bob_row   = mcm_db_seed_movie($pdo, $bob_one, 102);

	$alice_session = 'f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1';
	$bob_session   = 'f2f2f2f2f2f2f2f2f2f2f2f2f2f2f2f2';
	mcm_seed_signed_in($fixture, $alice_session, array('user_name' => 'alice', 'user_id' => $alice, 'user_logged_in' => 1));
	mcm_seed_signed_in($fixture, $bob_session, array('user_name' => 'bob', 'user_id' => $bob, 'user_logged_in' => 1));

	// Cookie and token together, as a browser on a page this site served sends
	// them.
	$as_alice = mcm_session_headers($alice_session);
	$as_bob   = mcm_session_headers($bob_session);

	$forbidden    = '{"error":"forbidden","message":"You are not allowed to do that."}';
	$unauthorised = '{"error":"authentication_required","message":"You must be signed in to do that."}';
	$wrong_method = '{"error":"method_not_allowed","message":"That request method is not allowed here."}';

	// Everything a refused request must leave alone: which film is in which
	// list and under which row id, the shared master list an add writes to, and
	// the lists themselves.
	$state = function () use ($pdo) {
		return array(
			'movies' => mcm_db_movies_snapshot($pdo),
			'master' => mcm_db_master_snapshot($pdo),
			'lists'  => mcm_db_lists_snapshot($pdo),
		);
	};

	$server_handle = mcm_server_start($fixture);
	try {
		/* Adding ------------------------------------------------------------ */

		// A film nobody has yet, so an add that got through would be visible in
		// the shared master list as well as in the movies table.
		$new_film = array(
			'tmdb_movie_id'       => 103,
			'tmdb_title'          => 'Movie Three',
			'tmdb_original_title' => 'Movie Three',
			'tmdb_poster_path'    => '/three.jpg',
			'tmdb_release_date'   => '2003-03-03',
		);

		$refusals = array(
			'an anonymous add' => array(
				'headers' => array(),
				'fields'  => array('movie_list_id' => $alice_one) + $new_film,
				'status'  => 401,
				'body'    => $unauthorised,
			),
			"an add to somebody else's list" => array(
				'headers' => $as_bob,
				'fields'  => array('movie_list_id' => $alice_one) + $new_film,
				'status'  => 403,
				'body'    => $forbidden,
			),
			'an add to a list that does not exist' => array(
				'headers' => $as_alice,
				'fields'  => array('movie_list_id' => 4242) + $new_film,
				'status'  => 403,
				'body'    => $forbidden,
			),
			'an add with an identifier that is not one' => array(
				'headers' => $as_alice,
				'fields'  => array('movie_list_id' => $alice_one . ' OR 1=1') + $new_film,
				'status'  => 403,
				'body'    => $forbidden,
			),
		);
		foreach ($refusals as $description => $case) {
			$before   = call_user_func($state);
			$response = mcm_http_post($server_handle, '/add_movie.php', $case['fields'], $case['headers']);

			t_same($case['status'], $response['status'], $description . ' is refused');
			t_same($case['body'], $response['body'], $description . ' gets the shared fixed body');
			// The master movie list is shared between every account and is the
			// first thing add_movie.php used to write. A refusal that arrived
			// after that write would show up here.
			t_same($before, call_user_func($state), $description . ' changes nothing at all');
		}

		// The owner's own list still works, and answers what it always did, now
		// that the request carries the session's token as well as its cookie.
		$before   = call_user_func($state);
		$response = mcm_http_post($server_handle, '/add_movie.php', array('movie_list_id' => $alice_two) + $new_film, $as_alice);

		t_same(200, $response['status'], 'the owner may add to her own list');
		t_same('1', $response['body'], 'a film that was not in her collection reports as inserted');
		t_same(count($before['movies']) + 1, count(call_user_func($state)['movies']), 'the film lands in exactly one row');
		t_same(count($before['master']) + 1, count(call_user_func($state)['master']), 'a film nobody had reaches the shared master list');
		t_contains($alice_two . '|103', implode("\n", mcm_db_movies_snapshot($pdo)), 'the film lands in the list she named');

		// The same film again is a duplicate, and is reported as one.
		$before   = call_user_func($state);
		$response = mcm_http_post($server_handle, '/add_movie.php', array('movie_list_id' => $alice_one) + $new_film, $as_alice);

		t_same('2', $response['body'], 'a film already in her collection reports as a duplicate');
		t_same($before, call_user_func($state), 'a duplicate writes nothing');

		// Duplicate detection is scoped to the account asking. Alice having a
		// film is not a reason for Bob to be told he already has it.
		$response = mcm_http_post($server_handle, '/add_movie.php', array('movie_list_id' => $bob_one) + $new_film, $as_bob);

		t_same('1', $response['body'], "another account's copy of the same film is not this account's duplicate");
		t_contains($bob_one . '|103', implode("\n", mcm_db_movies_snapshot($pdo)), 'the film lands in the other account\'s list too');

		// The owner reaches the page's own checks, which are unchanged.
		$response = mcm_http_post($server_handle, '/add_movie.php', array('movie_list_id' => $alice_one), $as_alice);
		t_same('Error: No movie id given.', $response['body'], 'the owner still gets the page\'s own message for a missing film');

		/* Deleting ---------------------------------------------------------- */

		$deletions = array(
			'an anonymous delete' => array('headers' => array(), 'status' => 401, 'body' => $unauthorised),
			"a delete from somebody else's list" => array('headers' => $as_bob, 'status' => 403, 'body' => $forbidden),
		);
		foreach ($deletions as $description => $case) {
			$before   = call_user_func($state);
			$response = mcm_http_post($server_handle, '/delete_movie.php', array('movie_list_id' => $alice_one, 'tmdb_movie_id' => 101), $case['headers']);

			t_same($case['status'], $response['status'], $description . ' is refused');
			t_same($case['body'], $response['body'], $description . ' gets the shared fixed body');
			t_lacks('greatsuccess', $response['body'], $description . ' is not told it worked');
			t_same($before, call_user_func($state), $description . ' leaves every row where it was');
		}

		$before   = call_user_func($state);
		$response = mcm_http_post($server_handle, '/delete_movie.php', array('movie_list_id' => $alice_one, 'tmdb_movie_id' => 101), $as_alice);
		$after    = call_user_func($state);

		t_same(200, $response['status'], 'the owner may delete from her own list');
		t_contains('greatsuccess', $response['body'], 'the owner is told the delete worked');
		t_same(count($before['movies']) - 1, count($after['movies']), 'exactly one row goes');
		t_lacks($alice_row . '|' . $alice_one . '|101', implode("\n", $after['movies']), 'the row she deleted is the one that went');
		t_contains($bob_row . '|' . $bob_one . '|102', implode("\n", $after['movies']), "the other account's rows are untouched");

		// The same delete as a GET, by the account that owns the list and with
		// its token in hand, is refused on the method alone - and the row it
		// named is still there afterwards.
		$before   = call_user_func($state);
		$response = mcm_http($server_handle, '/delete_movie.php?' . http_build_query(array('movie_list_id' => $bob_one, 'tmdb_movie_id' => 102)), $as_bob);
		$after    = call_user_func($state);

		t_same(405, $response['status'], "the owner's GET is refused");
		t_same($wrong_method, $response['body'], "the owner's GET gets the shared fixed body");
		t_lacks('greatsuccess', $response['body'], "the owner's GET is not told the delete worked");
		t_same($before, $after, "the owner's GET deleted nothing");
		t_contains($bob_row . '|' . $bob_one . '|102', implode("\n", $after['movies']), 'the row named in the GET is still there');

		// And the same delete as a POST with no token: refused, and the row
		// stays where it is. This is the pair the acceptance criterion asks for
		// - what the client was told, and what the table holds afterwards - on
		// a request that would otherwise have been allowed in full.
		$before   = call_user_func($state);
		$response = mcm_http_post($server_handle, '/delete_movie.php', array('movie_list_id' => $bob_one, 'tmdb_movie_id' => 102), array('Cookie: PHPSESSID=' . $bob_session));
		$after    = call_user_func($state);

		t_same(403, $response['status'], "the owner's POST with no token is refused");
		t_same($forbidden, $response['body'], "the owner's tokenless POST gets the shared fixed body");
		t_same($before, $after, "the owner's tokenless POST deleted nothing");
		t_contains('no valid CSRF token', $response['log'], 'the reason the tokenless POST was refused is logged');
		t_lacks(mcm_session_token($bob_session), $response['log'], 'the token itself is never logged');

		// With the token, the same request does what it always did.
		$before   = call_user_func($state);
		$response = mcm_http_post($server_handle, '/delete_movie.php', array('movie_list_id' => $bob_one, 'tmdb_movie_id' => 102), $as_bob);
		$after    = call_user_func($state);

		t_contains('greatsuccess', $response['body'], "the owner's POST with the token performs the delete");
		t_same(count($before['movies']) - 1, count($after['movies']), 'the delete removes exactly one row');
		t_lacks($bob_row . '|' . $bob_one . '|102', implode("\n", $after['movies']), 'the row named in the request is the one that went');

		/* Moving ------------------------------------------------------------ */

		// Every way of moving a film across the boundary between two accounts,
		// in both directions.
		$moves = array(
			'an anonymous move' => array(
				'headers' => array(),
				'fields'  => array('from_list' => $alice_two, 'to_list' => $alice_one, 'movie_id' => 103),
				'status'  => 401,
				'body'    => $unauthorised,
			),
			"a move out of somebody else's list" => array(
				'headers' => $as_bob,
				'fields'  => array('from_list' => $alice_two, 'to_list' => $bob_one, 'movie_id' => 103),
				'status'  => 403,
				'body'    => $forbidden,
			),
			"a move into somebody else's list" => array(
				'headers' => $as_alice,
				'fields'  => array('from_list' => $alice_two, 'to_list' => $bob_one, 'movie_id' => 103),
				'status'  => 403,
				'body'    => $forbidden,
			),
			'a move between two lists that are neither of theirs' => array(
				'headers' => $as_bob,
				'fields'  => array('from_list' => $alice_one, 'to_list' => $alice_two, 'movie_id' => 103),
				'status'  => 403,
				'body'    => $forbidden,
			),
		);
		foreach ($moves as $description => $case) {
			$before   = call_user_func($state);
			$response = mcm_http_post($server_handle, '/move.php', $case['fields'], $case['headers']);

			t_same($case['status'], $response['status'], $description . ' is refused');
			t_same($case['body'], $response['body'], $description . ' gets the shared fixed body');
			t_same($before, call_user_func($state), $description . ' leaves the film where it was');
		}

		// A move between two lists she owns still works, and the row keeps its
		// identifier: a move moves a row, it does not delete and re-add one.
		$moved_row = '';
		foreach (mcm_db_movies_snapshot($pdo) as $row) {
			if (strpos($row, '|' . $alice_two . '|103') !== false) {
				$moved_row = substr($row, 0, strpos($row, '|'));
			}
		}
		t_ok($moved_row !== '', 'the film to move was found in the list it started in');

		$before   = call_user_func($state);
		$response = mcm_http_post($server_handle, '/move.php', array('from_list' => $alice_two, 'to_list' => $alice_one, 'movie_id' => 103), $as_alice);
		$after    = call_user_func($state);

		t_same(200, $response['status'], 'the owner may move a film between her own lists');
		t_same(count($before['movies']), count($after['movies']), 'a move adds and removes nothing');
		t_contains($moved_row . '|' . $alice_one . '|103', implode("\n", $after['movies']), 'the film is now in the list she moved it to');
		t_lacks($moved_row . '|' . $alice_two . '|103', implode("\n", $after['movies']), 'and no longer in the one it came from');
		t_same($before['master'], $after['master'], 'a move leaves the shared master list alone');
		t_same($before['lists'], $after['lists'], 'a move leaves the lists themselves alone');

		/* Importing --------------------------------------------------------- */

		// The refusals happen before the import contacts TMDb, so they are the
		// part of this endpoint a test can drive. What an authorized import
		// then writes is not covered here: that call goes out to the network.
		$imports = array(
			'an anonymous import' => array('headers' => array(), 'list' => $alice_one, 'status' => 401, 'body' => $unauthorised),
			"an import into somebody else's list" => array('headers' => $as_bob, 'list' => $alice_one, 'status' => 403, 'body' => $forbidden),
			'an import into a list that does not exist' => array('headers' => $as_alice, 'list' => 4242, 'status' => 403, 'body' => $forbidden),
		);
		foreach ($imports as $description => $case) {
			$before   = call_user_func($state);
			$response = mcm_http_post($server_handle, '/import_list.php', array('movie_list_id' => $case['list'], 'tmdb_list_id' => '5212934a760ee36af148407c'), $case['headers']);

			t_same($case['status'], $response['status'], $description . ' is refused');
			t_same($case['body'], $response['body'], $description . ' gets the shared fixed body');
			t_same($before, call_user_func($state), $description . ' changes nothing at all');
			t_lacks('Method failed', $response['log'], $description . ' never reaches TMDb');
		}

		// The owner is not refused: she gets past both guards and stops on the
		// page's own check, which is as far as a case can follow her without
		// letting the suite talk to TMDb.
		$before   = call_user_func($state);
		$response = mcm_http_post($server_handle, '/import_list.php', array('movie_list_id' => $alice_one, 'tmdb_list_id' => ''), $as_alice);

		t_same(200, $response['status'], 'the owner is not refused an import into her own list');
		t_same('Error: No import list id given.', $response['body'], "the owner reaches the page's own check");
		t_same($before, call_user_func($state), 'a request that stops on that check writes nothing');
	} catch (Exception $exception) {
		t_ok(false, 'the movie ownership cases ran', $exception->getMessage());
	}
	mcm_server_stop($server_handle);
});

/*
 * ---------------------------------------------------------------------------
 * 26. What the browser scripts build out of a value
 * ---------------------------------------------------------------------------
 */

t_group('browser rendering', function () {
	// The scripts this project writes. The vendored libraries are excluded on
	// purpose, and naming the set here is what makes a new script a decision
	// rather than an omission.
	$scripts = array();
	foreach (mcm_browser_sources(MCM_REPO_ROOT) as $file) {
		$scripts[] = substr($file, strlen(MCM_REPO_ROOT) + 1);
	}
	t_same(array('js/dom.js', 'js/mc.js', 'js/nav.js', 'js/share.js'), $scripts, "the checks cover this project's own browser scripts");

	foreach (mcm_browser_sources(MCM_REPO_ROOT) as $file) {
		$name = substr($file, strlen(MCM_REPO_ROOT) + 1);
		t_same(array(), mcm_js_markup_problems($file), $name . ' joins no value to markup');
		t_same(array(), mcm_js_html_assignments($file), $name . ' hands .html() nothing but markup of its own');
		t_same(array(), mcm_js_regex_literals($file), $name . ' has no regular expression literal, the one thing the scanner cannot read');
		t_lacks('document.write', file_get_contents($file), $name . ' writes nothing into the document as it is parsed');
	}

	// Both page scripts reach for the same three builders, so a change to any of
	// it has one place to happen in - the arrangement inc/bootstrap.php already
	// has for the server side. What a file contains is all these four say: that
	// the call is written, or that the name is absent. Whether the call is
	// reached, and what it renders when it is, is what tests/browser/xss.html
	// answers.
	foreach (array('js/mc.js', 'js/share.js') as $script) {
		$source = file_get_contents(MCM_REPO_ROOT . '/' . $script);
		t_contains('mcmPosterImage(', $source, $script . ' calls the shared poster builder');
		t_contains('mcmSuggestionMarkup(', $source, $script . ' calls the shared typeahead suggestion builder');
		t_contains('mcmListHeader(', $source, $script . ' calls the shared suggestion heading builder');
		t_lacks('Handlebars', $source, $script . ' names no string-template engine');
	}

	$builders = file_get_contents(MCM_REPO_ROOT . '/js/dom.js');
	foreach (array('mcmText', 'mcmAppend', 'mcmAbbr', 'mcmPosterImage', 'mcmListTab', 'mcmListPane', 'mcmListHeader', 'mcmSuggestionMarkup', 'mcmMarkup') as $builder) {
		t_contains('function ' . $builder . ' (', $builders, 'js/dom.js defines ' . $builder . '()');
	}

	// A page whose script called a builder that had not loaded yet would fail
	// at the first list it rendered, so the order is asserted rather than left
	// to the eye.
	foreach (array('inc/views/logged_in.php' => 'mc', 'inc/views/share.php' => 'share') as $view => $page_script) {
		$declared = '';
		if (preg_match('/\$post_scripts\s*=\s*array\(([^)]*)\)/', file_get_contents(MCM_REPO_ROOT . '/' . $view), $match) === 1) {
			$declared = $match[1];
		}
		$builders_at = strpos($declared, "'dom'");
		$page_at     = strpos($declared, "'" . $page_script . "'");
		t_ok($builders_at !== false && $page_at !== false && $builders_at < $page_at, $view . ' loads js/dom.js ahead of js/' . $page_script . '.js', $declared);
	}

	// What a browser then builds out of a hostile value is a question only a
	// browser answers. tests/browser/xss.html renders one through these very
	// scripts and reports what the document ended up holding; it is opened by
	// hand, and the suite only insists that it is still here to open.
	t_ok(file_exists(MCM_REPO_ROOT . '/tests/browser/xss.html'), 'the browser page that renders hostile values is in the repository');

	// The checks have to have teeth, so they are pointed at deliberately broken
	// scripts. Nothing here touches the checkout.
	$fixture = mcm_fixture('browser-checks');
	$path    = $fixture['public'] . '/broken.js';
	$broken  = array(
		'a title pasted into an image tag' => array(
			'source'  => "var html = '<img class=\"lazy\" alt=\"' + movie.title + '\">'\n",
			'check'   => 'markup',
			'problem' => 'a value is concatenated onto markup',
		),
		'a list name pasted into a heading' => array(
			'source'  => "var header = '<h4>' + list.list_name + '</h4>'\n",
			'check'   => 'markup',
			'problem' => 'a value is concatenated onto markup',
		),
		'markup appended to a value' => array(
			'source'  => "var row = open + list.list_name + '</a></li>'\n",
			'check'   => 'markup',
			'problem' => 'markup is concatenated onto a value',
		),
		'a value interpolated into a template literal' => array(
			'source'  => "var row = `<li>\${list.list_name}</li>`\n",
			'check'   => 'markup',
			'problem' => 'a value is interpolated into markup',
		),
		'a name assigned as HTML' => array(
			'source'  => "$('#list-tabs li a').html(list_name)\n",
			'check'   => 'html',
			'problem' => 'list_name',
		),
		'markup built elsewhere assigned as HTML' => array(
			'source'  => "var markup = buildRow(list_name)\n$('#list-tabs').html(markup)\n",
			'check'   => 'html',
			'problem' => 'markup',
		),
		'a regular expression literal the scanner cannot read' => array(
			'source'  => "var clean = list_name.replace(/['\"]/g, '')\n",
			'check'   => 'regex',
			'problem' => '/',
		),
	);

	foreach ($broken as $description => $case) {
		file_put_contents($path, $case['source']);
		if ($case['check'] === 'markup') {
			$found = mcm_js_markup_problems($path);
		} elseif ($case['check'] === 'html') {
			$found = mcm_js_html_assignments($path);
		} else {
			$found = mcm_js_regex_literals($path);
		}
		t_contains($case['problem'], implode(' | ', $found), 'the check rejects ' . $description);
	}

	// And they have to stay quiet about everything that is not markup built out
	// of a value, or the scripts could not address an element again.
	$clean = array(
		'a name assigned as text'              => "$('<a>').text(list_name)\n",
		'a name assigned as an attribute'      => "$('<img>').attr('alt', movie.title).attr('data-original', base_url + size + movie.poster_path)\n",
		'an identifier inside a selector'      => "$('#list-tabs li:nth-child(' + (pos + 2) + ') a').text(list_name)\n",
		'a fragment link built from an id'     => "$('<a>').attr('href', '#' + list_id)\n",
		'markup joined to markup'              => "var html = '<div class=\"posters\">' + '</div>'\n",
		'markup this file wrote, assigned'     => "$('#alerts').html('<div class=\"alert alert-danger\">Something went wrong!</div>')\n",
		'markup this file wrote, appended to'  => "var html = '<div class=\"posters\">'\nhtml += '<div class=\"alert\"></div>'\n",
		'reading with .html()'                 => "var movie_id = $('#dialog #movie-id').html()\n",
		'an element handed to a sink'          => "$('#list-tabs').append(mcmListTab(list_id, list_name))\n",
		'a URL and a comment holding slashes'  => "// see http://example.com/a/b\nvar url = 'http://example.com/' + list_id\n",
		'an apostrophe inside a comment'       => "// the list's name is text, not markup: '<b>' stays '<b>'\nvar name = list.list_name\n",
	);

	foreach ($clean as $description => $source) {
		file_put_contents($path, $source);
		t_same(array(), mcm_js_markup_problems($path), 'the markup check accepts ' . $description);
		t_same(array(), mcm_js_html_assignments($path), 'the .html() check accepts ' . $description);
		t_same(array(), mcm_js_regex_literals($path), 'the scanner reads ' . $description . ' without seeing a regular expression');
	}
	unlink($path);
});

/*
 * ---------------------------------------------------------------------------
 * The backend-only TMDb client
 * ---------------------------------------------------------------------------
 *
 * inc/tmdb.php is the one place this site talks to TMDb from, and the cases
 * below are what hold it to that. They run against tests/pages/tmdb_stub.php on
 * the loopback interface, with a token that is not a real one: nothing here
 * contacts TMDb, and nothing here needs a network.
 *
 * The endpoint being a setting is what makes that possible - MCM_TMDB_BASE_URL
 * reaches the client verbatim, the same seam DB_HOST gives the database cases -
 * and the client's own policy is what keeps the setting from being a way to
 * send the credential somewhere: a plain-HTTP endpoint is refused unless it is
 * a loopback address, which never leaves the machine.
 */

/**
 * Point a fixture's configuration at a TMDb endpoint, with limits small enough
 * that a case can reach all three of them in under a couple of seconds.
 *
 * @param array $fixture
 * @param array $defines overrides, as mcm_config_php() takes them
 */
function mcm_tmdb_configure(array $fixture, array $defines = array())
{
	$defines += array(
		'MCM_TMDB_CONNECT_TIMEOUT_MS' => 500,
		'MCM_TMDB_TIMEOUT_MS'         => 500,
		'MCM_TMDB_MAX_BYTES'          => 4096,
	);
	file_put_contents($fixture['public'] . '/inc/config/config.php', mcm_config_php($defines));
}

/** Run the driver page in one of its modes and return its report. */
function mcm_tmdb_drive(array $fixture, $mode)
{
	$result           = mcm_cli($fixture, 'tmdb_client.php', array('MCM_TMDB_MODE' => $mode));
	$result['report'] = mcm_report($result['stdout']);

	return $result;
}

/**
 * The lines this client wrote to the log, and only those.
 *
 * The stub is served by the same fixture and logs to the same file, so a case
 * about what the client records has to read the client's own lines rather than
 * whatever else the run happened to produce.
 */
function mcm_tmdb_log_lines($log)
{
	$found = array();
	foreach (explode("\n", $log) as $line) {
		if (strpos($line, 'TMDb request failed') !== false) {
			$found[] = $line;
		}
	}
	return implode("\n", $found);
}

/** The fake token every fixture configuration carries. */
function mcm_tmdb_test_token()
{
	return 'test-tmdb-read-token';
}

t_group('tmdb client transport', function () {
	$fixture = mcm_fixture('tmdb');
	$server  = mcm_server_start($fixture);
	$token   = mcm_tmdb_test_token();

	try {
		mcm_tmdb_configure($fixture, array(
			'MCM_TMDB_BASE_URL' => 'http://127.0.0.1:' . $server['port'] . '/tmdb_stub.php',
		));

		$run    = mcm_tmdb_drive($fixture, 'live');
		$report = $run['report'];

		/* What a successful request carries ------------------------------------ */

		t_same('yes', $report['echo_ok'], 'a request the stub answers succeeds');
		t_same('200', $report['echo_status'], 'the success carries the status the stub sent');
		t_same('ok,status,data', $report['echo_keys'], 'a success is the shape a caller can rely on');
		// The credential is a header and only a header. Both halves are
		// asserted off the same request: the stub saw the bearer token, and the
		// URI the stub saw does not contain it anywhere.
		t_same('Bearer ' . $token, $report['echo_authorization'], 'the request carries the token as a bearer header');
		t_same('no', $report['echo_uri_has_token'], 'the URL the endpoint received holds no credential');
		t_lacks($token, $report['echo_uri'], 'the request line the endpoint received holds no credential');
		t_contains('application/json', $report['echo_accept'], 'the request asks for JSON');
		t_same('GET', $report['echo_method'], 'the client only ever makes a GET');
		t_contains('/tmdb_stub.php/echo', $report['echo_uri'], 'the path the caller asked for is the path that was requested');
		t_contains('language=en-GB', $report['echo_uri'], 'the query the caller passed is encoded into the URL');
		t_same('en-GB', $report['echo_query_language'], 'the endpoint reads the query parameter back out');
		t_same('stub-payload-marker', $report['echo_marker'], 'the decoded body is what the endpoint sent');

		/* The three limits ------------------------------------------------------ */

		// The endpoint sleeps for three times the configured total timeout, so
		// a client that waited for it would report success a second and a half
		// later. Both facts are asserted: the category, and that the call came
		// back long before the endpoint did.
		t_same('no', $report['slow_ok'], 'a request the endpoint sits on does not succeed');
		t_same('timeout', $report['slow_category'], 'a request that outlives the total timeout is a timeout');
		t_ok((int) $report['slow_elapsed_ms'] < 1200, 'the timeout returns well before the endpoint answers', 'elapsed: ' . $report['slow_elapsed_ms'] . 'ms, endpoint answers after 1500ms');

		// The endpoint sends sixteen times the cap, in kilobyte chunks.
		t_same('no', $report['large_ok'], 'an oversized response does not succeed');
		t_same('too_large', $report['large_category'], 'a response past the size cap is abandoned');

		/* Redirects and statuses ------------------------------------------------ */

		// The stub's redirect points at /echo, which answers 200 with a marker
		// in it. A client that followed it would report that success instead.
		t_same('no', $report['redirect_ok'], 'a redirect is not followed into a success');
		t_same('upstream', $report['redirect_category'], 'a redirect is reported as a response, not acted on');
		t_same('302', $report['redirect_status'], 'the redirect status reaches the caller');
		t_lacks('stub-payload-marker', $report['redirect_message'], 'nothing from behind the redirect reaches the caller');

		t_same('upstream', $report['upstream_category'], 'a server error is an upstream failure');
		t_same('500', $report['upstream_status'], 'the upstream status reaches the caller');
		t_same('404', $report['notfound_status'], 'a not-found status reaches the caller as itself');
		t_same('malformed', $report['notjson_category'], 'a body that is not JSON is refused');

		/* What a failure is allowed to say -------------------------------------- */

		$catalogue = array(
			'slow'      => 'timeout',
			'large'     => 'too_large',
			'redirect'  => 'upstream',
			'upstream'  => 'upstream',
			'notjson'   => 'malformed',
			'bad_path'  => 'configuration',
			'bad_query' => 'configuration',
		);
		foreach ($catalogue as $case => $category) {
			t_same('ok,category,message,status', $report[$case . '_keys'], 'the ' . $category . ' failure is the shape a caller can rely on');
			t_same($category, $report[$case . '_category'], 'the ' . $case . ' case is categorised as ' . $category);
			t_lacks($token, $report[$case . '_message'], 'the ' . $case . ' failure message holds no credential');
			t_lacks('127.0.0.1', $report[$case . '_message'], 'the ' . $case . ' failure message names no endpoint');
			t_lacks('tmdb_stub', $report[$case . '_message'], 'the ' . $case . ' failure message names no request URL');
			t_lacks('url', strtolower($report[$case . '_message']), 'the ' . $case . ' failure message quotes no URL');
		}
		// The upstream body said something specific. None of it is repeated.
		t_lacks('upstream-body-marker', $report['upstream_message'], 'no upstream body reaches the caller');
		t_lacks('status_code', $report['upstream_message'], 'no upstream error payload reaches the caller');
		t_same('The movie database did not answer this request.', $report['upstream_message'], 'two upstream statuses share one sentence');
		t_same($report['upstream_message'], $report['notfound_message'], 'a 404 and a 500 are told apart by status alone');

		// A path or a query the client refuses is refused before anything is
		// sent, so these two never reached the stub at all.
		t_same('no', $report['bad_path_ok'], 'a path that is not an absolute path is refused');
		t_same('no', $report['bad_query_ok'], 'a query parameter that would carry a credential is refused');

		/* What the log is allowed to say ---------------------------------------- */

		$lines = mcm_tmdb_log_lines($run['log']);
		t_contains('TMDb request failed', $lines, 'every failure is recorded where only the server sees it');
		t_contains('timeout:', $lines, 'the log says which category the failure was');
		t_lacks($token, $lines, 'the log holds no credential');
		t_lacks('127.0.0.1', $lines, 'the log names no endpoint');
		t_lacks('upstream-body-marker', $lines, 'the log holds no upstream body');
	} catch (Exception $error) {
		mcm_server_stop($server);
		throw $error;
	}
	mcm_server_stop($server);
});

t_group('tmdb client endpoint policy', function () {
	$fixture = mcm_fixture('tmdb-policy');
	$server  = mcm_server_start($fixture);

	try {
		mcm_tmdb_configure($fixture, array(
			'MCM_TMDB_BASE_URL' => 'http://127.0.0.1:' . $server['port'] . '/tmdb_stub.php',
		));

		$report = mcm_tmdb_drive($fixture, 'policy')['report'];

		/* Which endpoints the credential may be sent to -------------------------- */

		t_same('ok', $report['endpoint_configured'], "the suite's own loopback endpoint is one the client will use");
		t_same('ok', $report['endpoint_https'], 'an https endpoint is accepted');
		t_same('refused', $report['endpoint_http_remote'], 'a plain-HTTP endpoint on a real host is refused');
		// The carve-out, and its edges. A loopback endpoint never leaves the
		// machine, which is the whole reason plain HTTP is tolerated there.
		t_same('ok', $report['endpoint_http_loopback'], 'a plain-HTTP loopback endpoint is accepted');
		t_same('ok', $report['endpoint_http_localhost'], 'plain HTTP to localhost is accepted');
		t_same('ok', $report['endpoint_http_ipv6_loopback'], 'plain HTTP to the IPv6 loopback address is accepted');
		t_same('refused', $report['endpoint_http_lookalike'], 'a host that merely starts with the loopback name is a different host');
		t_same('refused', $report['endpoint_http_ip_lookalike'], 'a host that merely starts with the loopback IP literal as a string is a different host');
		t_same('refused', $report['endpoint_ftp'], 'an ftp endpoint is refused');
		t_same('refused', $report['endpoint_file'], 'a file endpoint is refused');
		t_same('refused', $report['endpoint_relative'], 'an endpoint that is not absolute is refused');
		t_same('refused', $report['endpoint_empty'], 'an unset endpoint is refused');
		t_same('refused', $report['endpoint_userinfo'], 'an endpoint carrying credentials of its own is refused');
		t_same('refused', $report['endpoint_query'], 'an endpoint carrying a query string is refused');

		/* Which paths and queries may be requested ------------------------------- */

		t_same('ok', $report['path_plain'], 'a plain API path is accepted');
		t_same('ok', $report['path_dotted'], 'a nested API path is accepted');
		t_same('refused', $report['path_empty'], 'an empty path is refused');
		t_same('refused', $report['path_relative'], 'a path that does not begin with a slash is refused');
		t_same('refused', $report['path_traversal'], 'a path holding a relative segment is refused');
		t_same('refused', $report['path_absolute_url'], 'a whole URL passed as a path is refused');
		t_same('refused', $report['path_with_query'], 'a path carrying its own query string is refused');
		t_same('refused', $report['path_double_slash'], 'a path that would change host is refused');
		t_same('refused', $report['path_newline'], 'a path holding a newline is refused');

		t_same('ok', $report['query_plain'], 'ordinary query parameters are accepted');
		t_same('refused', $report['query_api_key'], 'a query parameter named api_key is refused');
		t_same('refused', $report['query_api_key_cased'], 'the api_key refusal does not depend on case');
		t_same('refused', $report['query_session_id'], 'a query parameter naming a session credential is refused');
		t_same('refused', $report['query_bad_name'], 'a query parameter with a name this client will not send is refused');
		t_same('refused', $report['query_array_value'], 'a query parameter that is not a scalar is refused');

		/* Which tokens may be sent ------------------------------------------------ */

		t_same('ok', $report['token_configured'], "the fixture's own token is usable");
		t_same('refused', $report['token_empty'], 'an unset token is refused rather than sent as an empty bearer');
		// Header injection: a token holding a newline would end the
		// Authorization header and send what followed as headers of its own.
		t_same('refused', $report['token_crlf'], 'a token holding a newline is refused');
		t_same('refused', $report['token_space'], 'a token holding a space is refused');
		t_same('refused', $report['token_tab'], 'a token holding a tab is refused');

		/* The limits, and the URL that gets built --------------------------------- */

		t_same('500', $report['limit_connect_ms'], 'the configured connect timeout is the one in force');
		t_same('500', $report['limit_total_ms'], 'the configured total timeout is the one in force');
		t_same('4096', $report['limit_max_bytes'], 'the configured size cap is the one in force');
		t_same('https://api.themoviedb.org/3/movie/550?language=en%20gb', $report['url_built'], 'the query is encoded for a URL rather than pasted into one');
		t_same('https://api.themoviedb.org/3/movie/550', $report['url_no_query'], 'a request with no query gets no question mark');
	} catch (Exception $error) {
		mcm_server_stop($server);
		throw $error;
	}
	mcm_server_stop($server);
});

t_group('tmdb client transport options', function () {
	// Nothing here makes a request. The options a request would be made with
	// are read out of the array itself, so "does not follow a redirect" and
	// "verifies the peer" are asserted rather than inferred - which matters,
	// because the suite has no HTTPS endpoint to observe them against.
	$fixture = mcm_fixture('tmdb-options');
	mcm_tmdb_configure($fixture, array('MCM_TMDB_BASE_URL' => 'https://api.themoviedb.org/3'));

	$report = mcm_tmdb_drive($fixture, 'options')['report'];
	$token  = mcm_tmdb_test_token();

	t_same('https://api.themoviedb.org/3/movie/550', $report['opt_url'], 'the handle is given the URL that was asked for');
	t_same('Authorization: Bearer ' . $token . ' | Accept: application/json', $report['opt_headers'], 'the credential travels as a bearer header');
	t_same('no', $report['opt_url_has_token'], 'the URL the handle is given holds no credential');
	t_same('no', $report['opt_elsewhere_has_token'], 'no option other than the header holds the credential');

	t_same('false', $report['opt_followlocation'], 'the handle does not follow a redirect');
	t_same('0', $report['opt_maxredirs'], 'the handle is allowed no redirects at all');
	t_same('true', $report['opt_verifypeer'], 'the handle verifies the peer certificate');
	t_same('2', $report['opt_verifyhost'], 'the handle verifies the certificate names the host');
	t_same('true', $report['opt_httpget'], 'the handle makes a GET');
	t_same('500', $report['opt_connect_ms'], 'the handle carries the configured connect timeout');
	t_same('500', $report['opt_total_ms'], 'the handle carries the configured total timeout');
	t_same('yes', $report['opt_protocols_https_only'], 'the handle may speak https and nothing else');
	t_same('yes', $report['opt_redir_https_only'], 'a redirected request could reach https and nothing else');
	t_same('no', $report['opt_has_http'], 'the handle for a real endpoint may not speak plain HTTP');

	// The loopback handle is the one exception, and it is an exception about
	// the protocol alone: everything else it is given is the same.
	t_same('yes', $report['loopback_has_http'], 'the loopback handle may speak plain HTTP');
	t_same('false', $report['loopback_followlocation'], 'the loopback handle still follows no redirect');
	t_same('true', $report['loopback_verifypeer'], 'the loopback handle still verifies a peer it is given one for');
});

t_group('tmdb client configuration failures', function () {
	// Each of these is a request that is never made. The endpoints named here
	// are unreachable or refused outright, so no case in this group sends
	// anything anywhere.
	$fixture = mcm_fixture('tmdb-config');

	$cases = array(
		'a plain-HTTP endpoint on a real host' => array(
			'defines'  => array('MCM_TMDB_BASE_URL' => 'http://api.themoviedb.org/3'),
			'category' => 'configuration',
		),
		'an endpoint that is not a URL' => array(
			'defines'  => array('MCM_TMDB_BASE_URL' => 'themoviedb.org'),
			'category' => 'configuration',
		),
		'an endpoint the client will not speak to' => array(
			'defines'  => array('MCM_TMDB_BASE_URL' => 'file:///etc/passwd'),
			'category' => 'configuration',
		),
		'no configured token' => array(
			'defines'  => array('MCM_TMDB_BASE_URL' => 'https://api.themoviedb.org/3', 'TMDB_READ_ACCESS_TOKEN' => null),
			'category' => 'configuration',
		),
		'an empty token' => array(
			'defines'  => array('MCM_TMDB_BASE_URL' => 'https://api.themoviedb.org/3', 'TMDB_READ_ACCESS_TOKEN' => ''),
			'category' => 'configuration',
		),
		// Nothing listens on port 1, so this one does leave the process and
		// comes straight back refused. It is the difference between "this site
		// is not configured" and "the endpoint did not answer".
		'a loopback endpoint nothing is listening on' => array(
			'defines'  => array('MCM_TMDB_BASE_URL' => 'http://127.0.0.1:1/3'),
			'category' => 'unavailable',
		),
	);

	foreach ($cases as $description => $case) {
		mcm_tmdb_configure($fixture, $case['defines']);
		$run    = mcm_tmdb_drive($fixture, 'live');
		$report = $run['report'];

		t_same('no', $report['echo_ok'], $description . ' does not produce a successful request');
		t_same($case['category'], $report['echo_category'], $description . ' is reported as ' . $case['category']);
		t_lacks(mcm_tmdb_test_token(), $report['echo_message'], $description . ' says nothing about the credential');
		t_lacks(mcm_tmdb_test_token(), mcm_tmdb_log_lines($run['log']), $description . ' logs nothing about the credential');
	}
});

t_group('tmdb credentials never reach the session store', function () {
	// The regression this exists for: the site used to keep a credential-bearing
	// TMDb object in $_SESSION, so the key was written to the session store on
	// disk for every visitor. Caching the answer is fine and still happens; it
	// is the client that must not be kept.
	$fixture = mcm_fixture('tmdb-session');
	$server  = mcm_server_start($fixture);
	$token   = mcm_tmdb_test_token();

	try {
		mcm_tmdb_configure($fixture, array(
			'MCM_TMDB_BASE_URL' => 'http://127.0.0.1:' . $server['port'] . '/tmdb_stub.php',
		));

		$report = mcm_tmdb_drive($fixture, 'session')['report'];
		t_same('yes', $report['session_request_ok'], 'the page really did fetch something to cache');
		t_same('no', $report['session_serialized_has_token'], 'the session this page holds carries no credential');

		// Not the page's own word for it: what is actually on disk.
		$files = mcm_session_files($fixture);
		t_same(1, count($files), 'the page wrote exactly one session file');
		$stored = file_get_contents($fixture['sessions'] . '/sess_' . $files[0]);
		t_contains('stub-payload-marker', $stored, "the endpoint's answer really was cached in the session");
		t_lacks($token, $stored, 'the session file on disk holds no credential');
		t_lacks('tmdb_obj', $stored, 'no TMDb client object is stored in the session');
		t_lacks('TMDb', $stored, 'no TMDb object of any kind is serialized into the session');
	} catch (Exception $error) {
		mcm_server_stop($server);
		throw $error;
	}
	mcm_server_stop($server);
});

t_group('tmdb credential hygiene in the source', function () {
	$sources = mcm_php_sources(MCM_REPO_ROOT);
	t_ok(count($sources) > 0, 'there are project sources to read');

	// The two greps the issue asks for, run over the project's own files.
	// Comments count: a commented-out reference to a removed constant is still
	// a reference somebody will follow.
	foreach ($sources as $file) {
		$name   = substr($file, strlen(MCM_REPO_ROOT) + 1);
		$source = file_get_contents($file);
		t_ok(strpos($source, 'SESSION_ID') === false, $name . ' holds no reference to the removed auth-session constant');
		t_ok(strpos($source, 'tmdb_obj') === false, $name . ' keeps no TMDb client in the session');
	}

	// Only the client reads the token, and no browser-served script names it.
	foreach ($sources as $file) {
		$name = substr($file, strlen(MCM_REPO_ROOT) + 1);
		$reads = mcm_count_constant_reads($file, 'TMDB_READ_ACCESS_TOKEN');
		if ($name === 'inc/tmdb.php') {
			t_same(1, $reads, 'inc/tmdb.php is the one place that reads the token');
		} else {
			t_same(0, $reads, $name . ' does not read the TMDb token');
		}
	}
	foreach (mcm_browser_sources(MCM_REPO_ROOT) as $file) {
		$name = substr($file, strlen(MCM_REPO_ROOT) + 1);
		t_ok(strpos(file_get_contents($file), 'TMDB_READ_ACCESS_TOKEN') === false, $name . ' does not name the TMDb token');
	}

	// The tracked example configuration is documentation, so it holds
	// placeholders and only placeholders.
	$example = file_get_contents(MCM_REPO_ROOT . '/inc/config/example_config.php');
	t_contains('TMDB_READ_ACCESS_TOKEN', $example, 'the example configuration documents the read access token');
	t_ok(strpos($example, 'TMDB_SESSION_ID') === false, 'the example configuration no longer defines the auth-session constant');
	t_matches('#define\("TMDB_READ_ACCESS_TOKEN", "x+"\)#', $example, 'the example read access token is a placeholder');
	t_matches('#define\("TMDB_API_KEY", "x+"\)#', $example, 'the example API key is still a placeholder');

	// The client is loaded by nothing yet, and that is deliberate: the entry
	// point that uses it is separate work. What must be true today is that
	// requiring it is all it takes, and that it declares functions and runs
	// nothing on its own.
	$client = MCM_REPO_ROOT . '/inc/tmdb.php';
	t_ok(is_readable($client), 'the client file is there to be included');
	t_same(0, mcm_count_debug_output($client), 'the client dumps nothing into the response');
	t_same(0, mcm_count_new($client, 'TMDb'), 'the client does not build the old vendored wrapper');
	t_same(1, mcm_count_calls($client, 'curl_init'), 'the client opens exactly one handle, in one place');
	t_same(1, mcm_count_calls($client, 'curl_close'), 'the handle holding the credential is closed again');
});

/*
 * ---------------------------------------------------------------------------
 * The TMDb proxy
 * ---------------------------------------------------------------------------
 *
 * tmdb.php exposes five read-only operations and nothing else. The cases below
 * drive it as real requests, over two built-in servers on the loopback
 * interface: one serving the application and one serving
 * tests/pages/tmdb_stub.php as the far end. Two servers rather than one because
 * PHP's built-in server answers a single request at a time, so a proxy request
 * that had to reach a stub on its own server would wait for itself.
 *
 * The stub appends every request it receives to tmdb_stub_requests.log, which
 * is what makes three of this issue's claims checkable at all: "refused before
 * any outbound request" is that file staying empty, "one cache miss and then a
 * hit" is that file holding exactly one line after two fresh sessions, and
 * "ownership is settled before TMDb is asked" is the same file staying empty
 * when a request names somebody else's list.
 *
 * Read-only is not the same as public, so who may ask for what is driven as
 * requests too: the configuration, a movie and a movie's videos from an
 * anonymous session, a search from a signed-in one, and a list from the account
 * that owns the local list it names. The last of those needs real rows, so it
 * lives in the group that runs only when there is a database server; the list
 * projection itself is covered without one in 'tmdb proxy projections'.
 */

/**
 * A fixture with the application on one server and the stub on another, already
 * pointed at each other.
 *
 * @return array fixture, app, stub - the last two as mcm_server_start() returns
 */
function mcm_tmdb_proxy_fixture($name)
{
	$fixture = mcm_fixture($name);
	$app     = mcm_server_start($fixture);
	$stub    = mcm_server_start($fixture);

	mcm_tmdb_configure($fixture, array(
		'MCM_TMDB_BASE_URL'  => 'http://127.0.0.1:' . $stub['port'] . '/tmdb_stub.php',
		// The cache is this fixture's own, so a run never reads or writes an
		// answer another fixture - or another developer's checkout - left in the
		// system temporary directory.
		'MCM_TMDB_CACHE_DIR' => $fixture['root'] . '/cache',
	));

	return array('fixture' => $fixture, 'app' => $app, 'stub' => $stub);
}

/**
 * Seed a signed-in session in a proxy fixture and hand back the headers a
 * browser of that session sends.
 *
 * Three of the five operations are open to any session and two are not, so most
 * proxy cases need both a signed-in caller and an anonymous one.
 *
 * @return array request header lines
 */
function mcm_tmdb_proxy_sign_in(array $fixture, $user_id = 7)
{
	$session = 'c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7';
	mcm_seed_signed_in($fixture, $session, array('user_name' => 'caller', 'user_id' => $user_id, 'user_logged_in' => 1));

	return mcm_session_headers($session);
}

/** Stop both servers of a proxy fixture. */
function mcm_tmdb_proxy_stop(array $bundle)
{
	mcm_server_stop($bundle['app']);
	mcm_server_stop($bundle['stub']);
}

/** Forget every request the stub has received so far. */
function mcm_tmdb_stub_reset(array $fixture)
{
	@unlink($fixture['public'] . '/tmdb_stub_requests.log');
}

/** The requests the stub has received, one "METHOD uri" line each. */
function mcm_tmdb_stub_requests(array $fixture)
{
	$path = $fixture['public'] . '/tmdb_stub_requests.log';
	if (!is_file($path)) {
		return array();
	}

	$lines = array();
	foreach (explode("\n", file_get_contents($path)) as $line) {
		if (trim($line) !== '') {
			$lines[] = trim($line);
		}
	}
	return $lines;
}

/**
 * One value out of a decoded body by dotted path, or a default.
 *
 * Deep reads are guarded rather than written out, and the reason is a failure
 * mode rather than tidiness: a regression that turns an answer into a refusal
 * would otherwise reach count() with a null, end the whole run on a TypeError,
 * and leave the built-in servers of that group orphaned - holding their ports,
 * and the run's own output pipe, so the suite hangs instead of failing. A
 * missing field has to be a failing assertion.
 *
 * @param mixed  $body    a decoded body, or anything else
 * @param string $path    e.g. "data.results.0.key"
 * @param mixed  $default what to answer when the path is not there
 * @return mixed
 */
function mcm_at($body, $path, $default = null)
{
	$value = $body;
	foreach (explode('.', $path) as $step) {
		if (!is_array($value) || !array_key_exists($step, $value)) {
			return $default;
		}
		$value = $value[$step];
	}

	return $value;
}

/** A response body decoded as JSON, or an empty array when it is not JSON. */
function mcm_json_body(array $response)
{
	$decoded = json_decode($response['body'], true);

	return is_array($decoded) ? $decoded : array();
}

/**
 * Every key path in a decoded body, sorted: "data.images.base_url",
 * "data.results[].title" and so on.
 *
 * A snapshot of these is what says no upstream field leaked: it names what the
 * body actually holds, all the way down, rather than looking for the handful of
 * fields somebody thought to check for.
 */
function mcm_key_paths($value, $prefix = '')
{
	$paths = array();
	if (!is_array($value)) {
		return $paths;
	}

	foreach ($value as $key => $item) {
		$name = is_int($key) ? $prefix . '[]' : (($prefix === '') ? (string) $key : $prefix . '.' . $key);
		if (is_array($item) && count($item) > 0) {
			$paths = array_merge($paths, mcm_key_paths($item, $name));
		} else {
			$paths[] = $name;
		}
	}

	$paths = array_values(array_unique($paths));
	sort($paths);

	return $paths;
}

/** The lines the proxy itself wrote to the log, and only those. */
function mcm_tmdb_proxy_log_lines($log)
{
	$found = array();
	foreach (explode("\n", $log) as $line) {
		if (strpos($line, 'TMDb proxy') !== false || strpos($line, 'Refused request') !== false) {
			$found[] = $line;
		}
	}
	return implode("\n", $found);
}

t_group('tmdb proxy allowlist and validation', function () {
	$bundle  = mcm_tmdb_proxy_fixture('tmdb-proxy-allowlist');
	$fixture = $bundle['fixture'];
	// Signed in, because two of the five operations refuse an anonymous caller
	// before they look at a value at all. What that refusal is is asserted on
	// its own further down; here it would only hide the value checks.
	$caller  = mcm_tmdb_proxy_sign_in($fixture);

	try {
		// Every one of these must be refused, and refused before anything goes
		// out: the assertion after the loop is the one that says so.
		$refused = array(
			'no operation at all'                => array(),
			'an unknown operation'               => array('operation' => 'account'),
			'an operation that only looks known' => array('operation' => 'configuration '),
			'an operation differing in case'     => array('operation' => 'Configuration'),
			'a write operation'                  => array('operation' => 'rate', 'movie_id' => '550'),
			'an authentication operation'        => array('operation' => 'authentication_token'),
			'an operation given as an array'     => array('operation' => array('configuration')),
			'a movie with no identifier'         => array('operation' => 'movie'),
			'a movie identifier of zero'         => array('operation' => 'movie', 'movie_id' => '0'),
			'a negative movie identifier'        => array('operation' => 'movie', 'movie_id' => '-5'),
			'a movie identifier that is a word'  => array('operation' => 'movie', 'movie_id' => 'latest'),
			'a movie identifier with a comment'  => array('operation' => 'movie', 'movie_id' => '550 OR 1=1'),
			'a movie identifier with a path'     => array('operation' => 'movie', 'movie_id' => '550/../../configuration'),
			'a movie identifier past the bound'  => array('operation' => 'movie', 'movie_id' => '99999999999999999999'),
			'a videos request with no movie'     => array('operation' => 'videos'),
			'a videos identifier that is a path' => array('operation' => 'videos', 'movie_id' => '../../account'),
			'a search with no query'             => array('operation' => 'search'),
			'a search with an empty query'       => array('operation' => 'search', 'query' => ''),
			'a search that is only spaces'       => array('operation' => 'search', 'query' => '   '),
			'a search query past the cap'        => array('operation' => 'search', 'query' => str_repeat('a', 201)),
			'a search query holding a newline'   => array('operation' => 'search', 'query' => "alien\r\nX-Injected: 1"),
			'a search query that is not UTF-8'   => array('operation' => 'search', 'query' => "alien\xff\xfe"),
			'a search page of zero'              => array('operation' => 'search', 'query' => 'alien', 'page' => '0'),
			'a search page past the last one'    => array('operation' => 'search', 'query' => 'alien', 'page' => '501'),
			'a search page that is a word'       => array('operation' => 'search', 'query' => 'alien', 'page' => 'last'),
			'a list with no identifier'          => array('operation' => 'list', 'movie_list_id' => '11'),
			'a list with no destination'         => array('operation' => 'list', 'list_id' => '5212934a760ee36af148407c'),
			'a list identifier that is a path'   => array('operation' => 'list', 'list_id' => '../../etc/passwd', 'movie_list_id' => '11'),
			'a list identifier that is a URL'    => array('operation' => 'list', 'list_id' => 'https://evil.example.com/x', 'movie_list_id' => '11'),
			'a list identifier of the wrong size' => array('operation' => 'list', 'list_id' => str_repeat('a', 100), 'movie_list_id' => '11'),
			'a list identifier holding a slash'  => array('operation' => 'list', 'list_id' => '5212934a760ee36af148/407c', 'movie_list_id' => '11'),
			'a destination that is not a number' => array('operation' => 'list', 'list_id' => '5212934a760ee36af148407c', 'movie_list_id' => 'mine'),
			'a destination of zero'              => array('operation' => 'list', 'list_id' => '5212934a760ee36af148407c', 'movie_list_id' => '0'),
			// The fields that would have made this a general-purpose fetcher.
			'a caller-supplied URL'              => array('operation' => 'configuration', 'url' => 'https://evil.example.com/'),
			'a caller-supplied host'             => array('operation' => 'configuration', 'host' => 'evil.example.com'),
			'a caller-supplied path'             => array('operation' => 'configuration', 'path' => '/account'),
			'a caller-supplied method'           => array('operation' => 'configuration', 'method' => 'POST'),
			'a caller-supplied api key'          => array('operation' => 'configuration', 'api_key' => 'abc'),
			'a caller-supplied bearer token'     => array('operation' => 'configuration', 'authorization' => 'Bearer abc'),
			'a caller-supplied session id'       => array('operation' => 'configuration', 'session_id' => 'abc'),
			'a caller-supplied timeout'          => array('operation' => 'configuration', 'timeout' => '600000'),
			'a caller-supplied language'         => array('operation' => 'search', 'query' => 'alien', 'language' => 'en'),
			'a field belonging to another one'   => array('operation' => 'movie', 'movie_id' => '550', 'query' => 'alien'),
		);

		mcm_tmdb_stub_reset($fixture);
		foreach ($refused as $description => $fields) {
			$response = mcm_http($bundle['app'], '/tmdb.php?' . http_build_query($fields), $caller);
			$body     = mcm_json_body($response);
			t_same(400, $response['status'], 'the proxy refuses ' . $description);
			t_same('bad_request', isset($body['error']) ? $body['error'] : '', 'the refusal of ' . $description . ' carries the shared bounded body');
			t_same(array('error', 'message'), array_keys($body), 'the refusal of ' . $description . ' carries nothing else');
		}
		t_same(array(), mcm_tmdb_stub_requests($fixture), 'not one refused request reached the endpoint');

		// A refusal says which value it refused to the log, and to nowhere else.
		$response = mcm_http($bundle['app'], '/tmdb.php?' . http_build_query(array('operation' => 'movie', 'movie_id' => 'not-a-number')), $caller);
		t_contains('not-a-number', $response['log'], 'the refused value reaches the log');
		t_lacks('not-a-number', $response['body'], 'the refused value does not come back in the response');
		t_lacks('not-a-number', mcm_header_text($response), 'the refused value reaches no response header');

		// A POST is served exactly as a GET is, and reads its fields from the
		// body; anything else is refused by method.
		mcm_tmdb_stub_reset($fixture);
		$response = mcm_http_post($bundle['app'], '/tmdb.php', array('operation' => 'movie', 'movie_id' => '550'), $caller);
		$body     = mcm_json_body($response);
		t_same(200, $response['status'], 'a POST is served');
		t_same(550, mcm_at($body, 'data.id', 0), 'the POST body is where a POST reads its fields');

		// A GET's query string is not read on a POST, so a value cannot arrive
		// by a route the request did not use.
		$response = mcm_http($bundle['app'], '/tmdb.php?operation=movie&movie_id=550', $caller, 'POST', '');
		t_same(400, $response['status'], 'a POST carrying its fields only in the query string is refused');

		mcm_tmdb_stub_reset($fixture);
		foreach (array('PUT', 'DELETE', 'PATCH') as $method) {
			$response = mcm_http($bundle['app'], '/tmdb.php?operation=configuration', $caller, $method, '');
			$body     = mcm_json_body($response);
			t_same(405, $response['status'], 'a ' . $method . ' is refused');
			t_same('method_not_allowed', isset($body['error']) ? $body['error'] : '', 'the ' . $method . ' refusal carries the shared bounded body');
			t_contains('GET, POST', implode(' ', mcm_header_values($response, 'Allow')), 'the ' . $method . ' refusal says which methods there are');
		}
		t_same(array(), mcm_tmdb_stub_requests($fixture), 'no refused method reached the endpoint either');
	} catch (Throwable $error) {
		// Throwable rather than Exception: a TypeError from a read of an answer
		// that came back as a refusal would otherwise escape with both servers
		// still open, and an orphaned server holds its port and the run's own
		// output pipe.
		mcm_tmdb_proxy_stop($bundle);
		throw $error;
	}
	mcm_tmdb_proxy_stop($bundle);
});

t_group('tmdb proxy caller policy', function () {
	$bundle  = mcm_tmdb_proxy_fixture('tmdb-proxy-callers');
	$fixture = $bundle['fixture'];
	$caller  = mcm_tmdb_proxy_sign_in($fixture);

	$unauthorised = '{"error":"authentication_required","message":"You must be signed in to do that."}';

	try {
		// Read-only is not the same as public. Three operations are open,
		// because the pages that need them are: the public sharing page builds
		// poster URLs out of the configuration and is not signed in, and the
		// movie dialog opens from that page.
		$open = array(
			'the configuration' => array('operation' => 'configuration'),
			'a movie'           => array('operation' => 'movie', 'movie_id' => '550'),
			"a movie's videos"  => array('operation' => 'videos', 'movie_id' => '550'),
		);
		foreach ($open as $description => $fields) {
			$response = mcm_http($bundle['app'], '/tmdb.php?' . http_build_query($fields));
			t_same(200, $response['status'], 'an anonymous session may ask for ' . $description);
		}

		// The other two are this site's credential and this site's request
		// budget being spent on somebody's behalf, so there has to be a
		// somebody. Both are refused before a value is looked at, so an
		// anonymous caller cannot map the validator either.
		$closed = array(
			'a search'                    => array('operation' => 'search', 'query' => 'alien'),
			'a search with a bad page'    => array('operation' => 'search', 'query' => 'alien', 'page' => 'last'),
			'a list'                      => array('operation' => 'list', 'list_id' => '5212934a760ee36af148407c', 'movie_list_id' => '11'),
			'a list with a bad identifier' => array('operation' => 'list', 'list_id' => 'nonsense', 'movie_list_id' => '11'),
		);
		mcm_tmdb_stub_reset($fixture);
		foreach ($closed as $description => $fields) {
			$response = mcm_http($bundle['app'], '/tmdb.php?' . http_build_query($fields));
			t_same(401, $response['status'], 'an anonymous session may not ask for ' . $description);
			t_same($unauthorised, $response['body'], 'the refusal of ' . $description . ' is the shared bounded body');
		}
		t_same(array(), mcm_tmdb_stub_requests($fixture), 'not one anonymous refusal reached the endpoint');

		// A signed-in caller may search.
		$response = mcm_http($bundle['app'], '/tmdb.php?' . http_build_query(array('operation' => 'search', 'query' => 'fight club')), $caller);
		$body     = mcm_json_body($response);
		t_same(200, $response['status'], 'a signed-in caller may search');
		t_same(2, count(mcm_at($body, 'data.results', array())), 'and is served the results');

		// A signed-in caller asking for a list is asked whose list it is, and
		// that question needs a database this fixture has not got. What matters
		// here is that the request stops there: the answer is the site's generic
		// failure, and nothing was asked of TMDb.
		mcm_tmdb_stub_reset($fixture);
		$response = mcm_http($bundle['app'], '/tmdb.php?' . http_build_query(array(
			'operation'     => 'list',
			'list_id'       => '5212934a760ee36af148407c',
			'movie_list_id' => '11',
		)), $caller);
		t_same(500, $response['status'], 'a list request settles ownership, and cannot without a database');
		t_same(mcm_generic_message(), $response['body'], 'and says only the generic thing about it');
		t_same(array(), mcm_tmdb_stub_requests($fixture), 'a list request that never settled ownership asked TMDb nothing');

		// The order that makes that true, read off the one function that sets
		// it: the session first, then the values, then the connection, and only
		// then TMDb.
		$serve = mcm_method_tokens(MCM_REPO_ROOT . '/inc/tmdb_proxy.php', 'mcm_tmdb_serve');
		t_ok(count($serve) > 0, 'mcm_tmdb_serve() is declared');
		$order = array();
		foreach ($serve as $position => $token) {
			if ($token['id'] === T_STRING && !isset($order[$token['text']])) {
				$order[$token['text']] = $position;
			}
		}
		$found = true;
		foreach (array('mcm_tmdb_require_session', 'mcm_tmdb_plan', 'mcm_tmdb_require_owner', 'mcm_tmdb_run') as $step) {
			$found = t_ok(isset($order[$step]), 'mcm_tmdb_serve() calls ' . $step . '()') && $found;
		}
		if ($found) {
			t_ok($order['mcm_tmdb_require_session'] < $order['mcm_tmdb_plan'], 'the session is settled before the request values are');
			t_ok($order['mcm_tmdb_plan'] < $order['mcm_tmdb_require_owner'], 'the values are settled before the list is looked up');
			t_ok($order['mcm_tmdb_require_owner'] < $order['mcm_tmdb_run'], 'ownership is settled before TMDb is asked anything');
		}

		// And the ownership question is the only one that opens a connection.
		$owner = mcm_method_tokens(MCM_REPO_ROOT . '/inc/tmdb_proxy.php', 'mcm_tmdb_require_owner');
		t_same(1, mcm_count_calls_in($owner, 'mcm_db_or_fail'), 'the ownership question is what opens the connection');
		t_same(1, mcm_count_calls_in($owner, 'mcm_require_list_owner'), 'and it asks the shared ownership guard');
		$session = mcm_method_tokens(MCM_REPO_ROOT . '/inc/tmdb_proxy.php', 'mcm_tmdb_require_session');
		t_same(0, mcm_count_calls_in($session, 'mcm_db_or_fail'), 'being signed in is settled without a connection');
		t_same(1, mcm_count_calls_in($session, 'mcm_require_login'), 'and it asks the shared login guard');
		t_same(1, mcm_count_calls(MCM_REPO_ROOT . '/inc/tmdb_proxy.php', 'mcm_db_or_fail'), 'the proxy opens a connection in exactly one place');
	} catch (Throwable $error) {
		// Throwable rather than Exception: a TypeError from a read of an answer
		// that came back as a refusal would otherwise escape with both servers
		// still open, and an orphaned server holds its port and the run's own
		// output pipe.
		mcm_tmdb_proxy_stop($bundle);
		throw $error;
	}
	mcm_tmdb_proxy_stop($bundle);
});

t_group('tmdb proxy operations and projection', function () {
	$bundle  = mcm_tmdb_proxy_fixture('tmdb-proxy-operations');
	$fixture = $bundle['fixture'];
	$token   = mcm_tmdb_test_token();
	$caller  = mcm_tmdb_proxy_sign_in($fixture);

	// What each operation may answer with, to the field. A key that is not on
	// this list is a field this site never agreed to repeat.
	//
	// Four of the five are here. The list operation is asked whose list it is
	// before it asks TMDb anything, which needs a database: it is driven end to
	// end against real rows in 'tmdb proxy list ownership over a real database'
	// below, and its projection is asserted without one in 'tmdb proxy
	// projections'.
	$expected = array(
		'configuration' => array(
			'request' => array('operation' => 'configuration'),
			'headers' => array(),
			'paths'   => array('data.images.base_url', 'data.images.poster_sizes[]', 'data.images.secure_base_url', 'ok', 'operation'),
		),
		'search' => array(
			'request' => array('operation' => 'search', 'query' => 'fight club', 'page' => '2'),
			'headers' => $caller,
			'paths'   => array(
				'data.page', 'data.results[].id', 'data.results[].original_title', 'data.results[].poster_path',
				'data.results[].release_date', 'data.results[].title', 'data.total_pages', 'data.total_results',
				'ok', 'operation',
			),
		),
		'movie' => array(
			'request' => array('operation' => 'movie', 'movie_id' => '550'),
			'headers' => array(),
			'paths'   => array(
				'data.genres[].id', 'data.genres[].name', 'data.id', 'data.imdb_id', 'data.original_title',
				'data.overview', 'data.poster_path', 'data.release_date', 'data.runtime', 'data.title',
				'ok', 'operation',
			),
		),
		'videos' => array(
			'request' => array('operation' => 'videos', 'movie_id' => '550'),
			'headers' => array(),
			'paths'   => array(
				'data.id', 'data.results[].id', 'data.results[].key', 'data.results[].name',
				'data.results[].official', 'data.results[].site', 'data.results[].size', 'data.results[].type',
				'ok', 'operation',
			),
		),
	);

	try {
		foreach ($expected as $operation => $case) {
			mcm_tmdb_stub_reset($fixture);
			$response = mcm_http($bundle['app'], '/tmdb.php?' . http_build_query($case['request']), $case['headers']);
			$body     = mcm_json_body($response);

			t_same(200, $response['status'], 'the ' . $operation . ' operation is served');
			t_same($operation, isset($body['operation']) ? $body['operation'] : '', 'the answer says which operation it is');
			t_same(true, isset($body['ok']) ? $body['ok'] : false, 'the ' . $operation . ' answer says it succeeded');
			t_contains('application/json', implode(' ', mcm_header_values($response, 'Content-Type')), 'the ' . $operation . ' answer is JSON');

			// The snapshot: every field the body holds, and no other.
			t_same($case['paths'], mcm_key_paths($body), 'the ' . $operation . ' answer holds exactly the projected fields');

			// And what the projection dropped really was there to drop.
			t_lacks('upstream-only-marker', $response['body'], 'no unprojected upstream field survives the ' . $operation . ' projection');
			t_lacks('stub-payload-marker', $response['body'], 'no unprojected upstream field survives the ' . $operation . ' projection, top level included');
			t_lacks($token, $response['body'], 'the ' . $operation . ' answer carries no credential');
			t_lacks($token, mcm_header_text($response), 'the ' . $operation . ' headers carry no credential');
			t_lacks('127.0.0.1', $response['body'], 'the ' . $operation . ' answer names no upstream URL');

			// Exactly one outbound request, and it went where this site decided.
			$requests = mcm_tmdb_stub_requests($fixture);
			t_same(1, count($requests), 'the ' . $operation . ' operation made exactly one outbound request');
			t_contains('GET /tmdb_stub.php', isset($requests[0]) ? $requests[0] : '', 'the outbound request is a GET');
		}

		// Values that came back, not only field names.
		$body = mcm_json_body(mcm_http($bundle['app'], '/tmdb.php?operation=movie&movie_id=550'));
		t_same(550, mcm_at($body, 'data.id'), 'the movie identifier comes back as a number');
		t_same('Fight Club', mcm_at($body, 'data.title'), 'the title comes back');
		t_same(139, mcm_at($body, 'data.runtime'), 'the runtime comes back as a number');
		t_same('tt0137523', mcm_at($body, 'data.imdb_id'), 'the IMDb identifier comes back');
		t_same(array(array('id' => 18, 'name' => 'Drama')), mcm_at($body, 'data.genres'), 'a genre keeps its two fields and loses the rest');

		$body = mcm_json_body(mcm_http($bundle['app'], '/tmdb.php?operation=videos&movie_id=550'));
		t_same('BdJKm16Co6M', mcm_at($body, 'data.results.0.key'), "the video's key comes back");
		t_same(1080, mcm_at($body, 'data.results.0.size'), 'the size comes back as the number the current response uses');
		t_same(true, mcm_at($body, 'data.results.0.official'), 'the official flag comes back as a flag');

		$body = mcm_json_body(mcm_http($bundle['app'], '/tmdb.php?operation=configuration'));
		t_same('http://image.tmdb.org/t/p/', mcm_at($body, 'data.images.base_url'), 'the image base URL comes back');
		t_same(array('w92', 'w154', 'w185'), mcm_at($body, 'data.images.poster_sizes'), 'the poster sizes come back');

		// The values the request carried are normalised on the way out, and the
		// query the endpoint saw is this site's, not the caller's.
		mcm_tmdb_stub_reset($fixture);
		mcm_http($bundle['app'], '/tmdb.php?' . http_build_query(array('operation' => 'search', 'query' => '  fight club  ', 'page' => '2')), $caller);
		$requests = mcm_tmdb_stub_requests($fixture);
		$sent     = isset($requests[0]) ? $requests[0] : '';
		t_contains('/search/movie', $sent, 'a search asks for the search path');
		t_contains('query=fight%20club', $sent, 'the search phrase is trimmed and encoded');
		t_contains('page=2', $sent, 'the page the caller asked for is the page that was asked for');
		t_contains('include_adult=false', $sent, "the site's own fixed parameter goes out with it");
		t_lacks('api_key', $sent, 'no credential is in the outbound URL');

		// A search with no page is the first page, decided here rather than by
		// whatever the endpoint would default to.
		mcm_tmdb_stub_reset($fixture);
		mcm_http($bundle['app'], '/tmdb.php?' . http_build_query(array('operation' => 'search', 'query' => 'alien')), $caller);
		$requests = mcm_tmdb_stub_requests($fixture);
		t_contains('page=1', isset($requests[0]) ? $requests[0] : '', 'a search with no page asks for the first one');
	} catch (Throwable $error) {
		// Throwable rather than Exception: a TypeError from a read of an answer
		// that came back as a refusal would otherwise escape with both servers
		// still open, and an orphaned server holds its port and the run's own
		// output pipe.
		mcm_tmdb_proxy_stop($bundle);
		throw $error;
	}
	mcm_tmdb_proxy_stop($bundle);
});

t_group('tmdb proxy projections', function () {
	// No server and no database: the projectors are pure, so this is where a
	// payload TMDb would never send can be handed to them directly. It is also
	// where the list operation's projection is covered, because that operation
	// cannot be driven end to end without a database.
	$fixture = mcm_fixture('tmdb-projection');
	mcm_tmdb_configure($fixture, array('MCM_TMDB_CACHE_DIR' => $fixture['root'] . '/cache'));

	$run    = mcm_cli($fixture, 'tmdb_projection.php');
	$report = mcm_report($run['stdout']);
	// The one thing projecting these payloads has to say: a collection longer
	// than this site repeats was cut short. Nothing upstream is quoted with it.
	t_contains('capped at 1000 rows', $run['log'], 'cutting a collection short is recorded');
	t_lacks('upstream-only-marker', $run['log'], 'and nothing upstream is quoted into the log');

	$of = function ($name) use ($report) {
		return isset($report[$name]) ? json_decode($report[$name], true) : null;
	};

	// Every field each projection holds, and no other - including the ones the
	// stub cannot show, because it answers the way TMDb does.
	$shapes = array(
		'configuration' => array('images.base_url', 'images.poster_sizes[]', 'images.secure_base_url'),
		'search'        => array('page', 'results[].id', 'results[].original_title', 'results[].poster_path', 'results[].release_date', 'results[].title', 'total_pages', 'total_results'),
		'movie'         => array('genres[].id', 'genres[].name', 'id', 'imdb_id', 'original_title', 'overview', 'poster_path', 'release_date', 'runtime', 'title'),
		'videos'        => array('id', 'results[].id', 'results[].key', 'results[].name', 'results[].official', 'results[].site', 'results[].size', 'results[].type'),
		'list'          => array('description', 'id', 'item_count', 'items[].id', 'items[].original_title', 'items[].poster_path', 'items[].release_date', 'items[].title', 'name'),
	);
	foreach ($shapes as $name => $paths) {
		t_same($paths, mcm_key_paths($of($name)), 'the ' . $name . ' projection holds exactly the fields it names');
	}

	// Nothing anywhere in the report came from a field nobody named, at any
	// depth - which is the whole of what a projection is for.
	t_lacks('upstream-only-marker', $run['stdout'], 'no unnamed upstream field survives any projection');
	t_lacks('backdrop', $run['stdout'], 'a field of the right shape but the wrong name does not survive either');
	t_lacks('X-Injected', $run['stdout'], 'a header injection attempt in a poster size does not survive');

	// Types, not only names.
	$movie = $of('movie');
	t_same(139, mcm_at($movie, 'runtime'), 'a numeric string comes back as a number');
	t_same(array(array('id' => 18, 'name' => 'Drama')), mcm_at($movie, 'genres'), 'a genre keeps two fields, and a genre that is not a row is dropped');

	$search = $of('search');
	t_same(2, mcm_at($search, 'page'), 'the page comes back as a number');
	t_same(2, count(mcm_at($search, 'results', array())), 'a result that is not a row is dropped');
	t_same(null, mcm_at($search, 'results.1.poster_path'), 'a missing poster stays null, which is how the browser tells');
	t_same(null, mcm_at($of('search_not_a_collection'), 'page'), 'a page that is a word comes back as nothing');
	t_same(array(), mcm_at($of('search_not_a_collection'), 'results'), 'results that are not a collection come back as none');
	// The two caps are written out here rather than read from the application,
	// for the reason the session key in tests/run.php is: a suite that asks the
	// code what its own limit is agrees with whatever the limit becomes.
	t_same(1000, $of('search_row_count'), 'a collection longer than the cap is cut to 1000 rows');

	$videos = $of('videos');
	t_same(1080, mcm_at($videos, 'results.0.size'), 'the size comes back as the number the current response uses');
	t_same(true, mcm_at($videos, 'results.0.official'), 'the official flag comes back as a flag');
	t_same('', mcm_at($videos, 'results.1.key'), 'a row in the removed response shape yields no video key');
	t_same(null, mcm_at($videos, 'results.1.size'), 'and its word-ranked size is not repeated as a number');
	t_same(false, mcm_at($videos, 'results.1.official'), 'and "no" is not a flag that is set');
	t_same(array(), mcm_at($of('videos_empty'), 'results'), 'a movie with no videos projects to no videos rather than to an error');

	$list = $of('list');
	t_same('5212934a760ee36af148407c', mcm_at($list, 'id'), 'a hexadecimal list identifier stays text');
	t_same(2, count(mcm_at($list, 'items', array())), 'both items come back');
	t_same(101, mcm_at($list, 'items.0.id'), 'an item keeps the identifier an import writes');
	t_same(null, mcm_at($list, 'items.1.poster_path'), 'and an item with no poster keeps its null');

	// An answer with nothing in it is still every field: a page that reads one
	// finds it whatever TMDb sent, with the collections empty rather than gone.
	$empty = array(
		'configuration_empty' => array('images.base_url', 'images.poster_sizes', 'images.secure_base_url'),
		'movie_empty'         => array('genres', 'id', 'imdb_id', 'original_title', 'overview', 'poster_path', 'release_date', 'runtime', 'title'),
		'list_empty'          => array('description', 'id', 'item_count', 'items', 'name'),
	);
	foreach ($empty as $name => $paths) {
		t_same($paths, mcm_key_paths($of($name)), 'an empty ' . str_replace('_empty', '', $name) . ' answer still holds every field');
	}

	// A string longer than this site repeats is cut to the cap.
	t_same(4096, $of('movie_overview_length'), 'a very long string is cut to 4096 bytes');

	// And escaping is not this file's job: a stored value keeps the bytes that
	// were sent, and is escaped where it lands.
	t_same('<script>alert(1)</script>', mcm_at($of('list_markup_name'), 'name'), 'a list name that is markup keeps its bytes');
});

t_group('tmdb proxy configuration cache', function () {
	$bundle  = mcm_tmdb_proxy_fixture('tmdb-proxy-cache');
	$fixture = $bundle['fixture'];
	$token   = mcm_tmdb_test_token();
	$cache   = $fixture['root'] . '/cache';

	try {
		mcm_tmdb_stub_reset($fixture);

		// Two fresh anonymous browsers. Neither carries a cookie, so each gets a
		// session of its own - which is exactly the case a per-session cache
		// could not help with, and the reason this one is shared.
		$first  = mcm_http($bundle['app'], '/tmdb.php?operation=configuration');
		$second = mcm_http($bundle['app'], '/tmdb.php?operation=configuration');

		t_same(200, $first['status'], 'the first session is served the configuration');
		t_same(200, $second['status'], 'the second session is served the configuration');
		t_same($first['body'], $second['body'], 'both sessions are served the same answer');
		t_same(2, count(mcm_session_files($fixture)), 'the two requests really were two fresh sessions');

		// The whole claim, in one assertion: two sessions, one outbound request.
		t_same(1, count(mcm_tmdb_stub_requests($fixture)), 'two fresh sessions cost exactly one request to the endpoint');
		t_contains('cache miss', $first['log'], 'the first session missed the cache and said so in the log');
		t_lacks('cache miss', $second['log'], 'the second session hit the cache');

		// A third, still inside the day.
		$third = mcm_http($bundle['app'], '/tmdb.php?operation=configuration');
		t_same($first['body'], $third['body'], 'a third session is served the same answer again');
		t_same(1, count(mcm_tmdb_stub_requests($fixture)), 'and still only one request has gone out');

		// What is on disk: one small file, private to this account, holding the
		// projection and neither the credential nor an upstream field.
		$files = glob($cache . '/*.json');
		if (!t_same(1, count($files), 'the cache is one file')) {
			mcm_tmdb_proxy_stop($bundle);
			return;
		}
		$stored = file_get_contents($files[0]);
		t_ok(strlen($stored) < 65536, 'the cache file is bounded', strlen($stored) . ' bytes');
		t_same('0600', substr(sprintf('%o', fileperms($files[0])), -4), 'the cache file is readable only by this account');
		t_same('0700', substr(sprintf('%o', fileperms($cache)), -4), 'the cache directory is private');
		t_contains('image.tmdb.org', $stored, 'the cache really holds the answer');
		t_lacks($token, $stored, 'the cache holds no credential');
		t_lacks('stub-payload-marker', $stored, 'the cache holds the projection rather than the upstream body');
		t_lacks('upstream-only-marker', $stored, 'the cache holds no unprojected upstream field');

		// Nothing about where it is kept reaches a client.
		t_lacks($cache, $first['body'], 'the answer does not name the cache directory');
		t_lacks($cache, mcm_header_text($first), 'no response header names the cache directory');
		t_lacks('/tmp', $first['body'], 'the answer names no local path at all');

		// Past the day, the answer is fetched again. The stored moment is moved
		// back rather than the suite waiting a day for it.
		$held = json_decode($stored, true);
		if (!t_ok(isset($held['stored'], $held['data']), 'the cache file holds a stored moment and the answer')) {
			mcm_tmdb_proxy_stop($bundle);
			return;
		}
		$held['stored'] = time() - (86400 + 60);
		file_put_contents($files[0], json_encode($held));

		$fourth = mcm_http($bundle['app'], '/tmdb.php?operation=configuration');
		t_same(200, $fourth['status'], 'a request after the day is still served');
		t_same(2, count(mcm_tmdb_stub_requests($fixture)), 'an answer older than a day is fetched again');
		t_contains('cache miss', $fourth['log'], 'and the miss is logged');

		// A cache file that is not what this site wrote is ignored rather than
		// trusted: no answer at all is better than somebody else's.
		file_put_contents($files[0], 'not json at all');
		mcm_http($bundle['app'], '/tmdb.php?operation=configuration');
		t_same(3, count(mcm_tmdb_stub_requests($fixture)), 'an unreadable cache file costs a request rather than an answer');

		// Only the configuration is cached; the other four are not.
		mcm_tmdb_stub_reset($fixture);
		mcm_http($bundle['app'], '/tmdb.php?operation=movie&movie_id=550');
		mcm_http($bundle['app'], '/tmdb.php?operation=movie&movie_id=550');
		t_same(2, count(mcm_tmdb_stub_requests($fixture)), 'a movie is fetched every time it is asked for');
		t_same(1, count(glob($cache . '/*.json')), 'and nothing but the configuration is kept');
	} catch (Throwable $error) {
		// Throwable rather than Exception: a TypeError from a read of an answer
		// that came back as a refusal would otherwise escape with both servers
		// still open, and an orphaned server holds its port and the run's own
		// output pipe.
		mcm_tmdb_proxy_stop($bundle);
		throw $error;
	}
	mcm_tmdb_proxy_stop($bundle);
});

t_group('tmdb proxy upstream failures', function () {
	$bundle  = mcm_tmdb_proxy_fixture('tmdb-proxy-failures');
	$fixture = $bundle['fixture'];
	$token   = mcm_tmdb_test_token();

	try {
		// The stub answers these identifiers with a status rather than a film,
		// and puts a marker in the body it sends with it.
		$cases = array(
			'404' => array('movie_id' => '404', 'status' => 502),
			'429' => array('movie_id' => '429', 'status' => 502),
			'500' => array('movie_id' => '500', 'status' => 502),
		);

		foreach ($cases as $upstream => $case) {
			$response = mcm_http($bundle['app'], '/tmdb.php?operation=movie&movie_id=' . $case['movie_id']);
			$body     = mcm_json_body($response);

			t_same($case['status'], $response['status'], 'an upstream ' . $upstream . ' is answered with a bounded status');
			t_same(array('error', 'message'), array_keys($body), 'the upstream ' . $upstream . ' failure carries a category and a message and nothing else');
			t_same('upstream', $body['error'], 'the upstream ' . $upstream . ' failure names the category');
			t_lacks('upstream-body-marker', $response['body'], 'the upstream ' . $upstream . ' body does not reach the client');
			t_lacks($upstream, $body['message'], 'the upstream ' . $upstream . ' status is not quoted in the message');
			t_lacks('127.0.0.1', $response['body'], 'the upstream ' . $upstream . ' failure names no URL');
			t_lacks($token, $response['body'], 'the upstream ' . $upstream . ' failure names no credential');
			t_lacks($token, $response['log'], 'and the log of it names no credential either');
			t_contains('TMDb request failed', $response['log'], 'the reason for the upstream ' . $upstream . ' failure reaches the log');
		}

		// A site with no token configured sends nothing and says so as a
		// configuration failure rather than as an upstream one.
		mcm_tmdb_configure($fixture, array(
			'MCM_TMDB_BASE_URL'      => 'http://127.0.0.1:' . $bundle['stub']['port'] . '/tmdb_stub.php',
			'MCM_TMDB_CACHE_DIR'     => $fixture['root'] . '/cache',
			'TMDB_READ_ACCESS_TOKEN' => null,
		));
		mcm_tmdb_stub_reset($fixture);
		$response = mcm_http($bundle['app'], '/tmdb.php?operation=movie&movie_id=550');
		$body     = mcm_json_body($response);
		t_same(503, $response['status'], 'a site with no token answers that it is not configured');
		t_same('configuration', isset($body['error']) ? $body['error'] : '', 'the failure names the configuration category');
		t_same(array(), mcm_tmdb_stub_requests($fixture), 'and nothing was sent to find that out');

		mcm_tmdb_configure($fixture, array(
			'MCM_TMDB_BASE_URL'  => 'http://127.0.0.1:' . $bundle['stub']['port'] . '/tmdb_stub.php',
			'MCM_TMDB_CACHE_DIR' => $fixture['root'] . '/cache',
		));

		// Last in the group on purpose: the stub goes on sleeping after the
		// client has given up, so anything asked of it after this would be
		// answered late through no fault of the proxy's.
		$response = mcm_http($bundle['app'], '/tmdb.php?operation=movie&movie_id=599');
		$body     = mcm_json_body($response);
		t_same(504, $response['status'], 'an endpoint that takes too long is answered as a timeout');
		t_same('timeout', isset($body['error']) ? $body['error'] : '', 'the timeout names its own category');
		t_lacks('127.0.0.1', $response['body'], 'the timeout names no URL');
	} catch (Throwable $error) {
		// Throwable rather than Exception: a TypeError from a read of an answer
		// that came back as a refusal would otherwise escape with both servers
		// still open, and an orphaned server holds its port and the run's own
		// output pipe.
		mcm_tmdb_proxy_stop($bundle);
		throw $error;
	}
	mcm_tmdb_proxy_stop($bundle);
});

t_group('tmdb proxy list ownership over a real database', function () {
	$server = mcm_db_server();
	if ($server === null) {
		t_skip('the TMDb proxy list ownership cases', mcm_db_skip_reason());
		return;
	}
	if (!t_same('', $server['schema_error'], 'the tracked schema loaded for the proxy list cases')) {
		return;
	}

	$pdo = mcm_db_reset_collection($server);

	// Two accounts, a list each. Whose list a request names is the whole
	// question here, so the rows have to be real ones.
	$alice = mcm_db_seed_user($pdo, 'alice', 'p' . bin2hex(random_bytes(6)));
	$bob   = mcm_db_seed_user($pdo, 'bob', 'p' . bin2hex(random_bytes(6)));
	$alice_list = mcm_db_seed_list($pdo, $alice, 'alice one', 0);
	$bob_list   = mcm_db_seed_list($pdo, $bob, 'bob one', 0);

	// A fixture on that database, and a stub for TMDb on a server of its own.
	$fixture = mcm_db_fixture('tmdb-proxy-list', $server);
	$app     = mcm_server_start($fixture);
	$stub    = mcm_server_start($fixture);
	mcm_tmdb_configure($fixture, array(
		'DB_HOST'            => mcm_db_host_setting($server),
		'DB_NAME'            => $server['database'],
		'DB_USER'            => $server['user'],
		'DB_PASS'            => $server['password'],
		'MCM_TMDB_BASE_URL'  => 'http://127.0.0.1:' . $stub['port'] . '/tmdb_stub.php',
		'MCM_TMDB_CACHE_DIR' => $fixture['root'] . '/cache',
	));

	$alice_session = 'a9a9a9a9a9a9a9a9a9a9a9a9a9a9a9a9';
	$bob_session   = 'b9b9b9b9b9b9b9b9b9b9b9b9b9b9b9b9';
	mcm_seed_signed_in($fixture, $alice_session, array('user_name' => 'alice', 'user_id' => $alice, 'user_logged_in' => 1));
	mcm_seed_signed_in($fixture, $bob_session, array('user_name' => 'bob', 'user_id' => $bob, 'user_logged_in' => 1));
	$as_alice = mcm_session_headers($alice_session);
	$as_bob   = mcm_session_headers($bob_session);

	$forbidden    = '{"error":"forbidden","message":"You are not allowed to do that."}';
	$unauthorised = '{"error":"authentication_required","message":"You must be signed in to do that."}';
	$tmdb_list    = '5212934a760ee36af148407c';

	// The proxy writes nothing at all, so every table is expected to be exactly
	// as it was afterwards - on a refusal and on a success alike.
	$state = function () use ($pdo) {
		return array(
			'movies' => mcm_db_movies_snapshot($pdo),
			'master' => mcm_db_master_snapshot($pdo),
			'lists'  => mcm_db_lists_snapshot($pdo),
		);
	};
	$before = $state();

	try {
		$refusals = array(
			'an anonymous list request' => array(
				'headers' => array(),
				'fields'  => array('operation' => 'list', 'list_id' => $tmdb_list, 'movie_list_id' => $alice_list),
				'status'  => 401,
				'body'    => $unauthorised,
			),
			'a session with a cookie but no token' => array(
				// The proxy asks for no token, so this one is served on its
				// merits: it is here to prove the refusals below are about the
				// list and not about a missing token.
				'headers' => array('Cookie: PHPSESSID=' . $bob_session),
				'fields'  => array('operation' => 'list', 'list_id' => $tmdb_list, 'movie_list_id' => $alice_list),
				'status'  => 403,
				'body'    => $forbidden,
			),
			"a list request naming somebody else's list" => array(
				'headers' => $as_bob,
				'fields'  => array('operation' => 'list', 'list_id' => $tmdb_list, 'movie_list_id' => $alice_list),
				'status'  => 403,
				'body'    => $forbidden,
			),
			'a list request naming a list nobody has' => array(
				'headers' => $as_alice,
				'fields'  => array('operation' => 'list', 'list_id' => $tmdb_list, 'movie_list_id' => 4242),
				'status'  => 403,
				'body'    => $forbidden,
			),
		);

		foreach ($refusals as $description => $case) {
			mcm_tmdb_stub_reset($fixture);
			$response = mcm_http_post($app, '/tmdb.php', $case['fields'], $case['headers']);

			t_same($case['status'], $response['status'], 'the proxy refuses ' . $description);
			t_same($case['body'], $response['body'], 'the refusal of ' . $description . ' says only what every refusal says');
			// The point of settling ownership before the call: a refusal costs
			// this site nothing.
			t_same(array(), mcm_tmdb_stub_requests($fixture), $description . ' made no request to TMDb');
			t_same($before, $state(), $description . ' left every table as it was');
		}

		// A list that does not exist and a list belonging to somebody else are
		// answered with the same bytes, so the response says nothing about
		// whose it is or whether it is anybody's.
		t_same(
			$refusals["a list request naming somebody else's list"]['body'],
			$refusals['a list request naming a list nobody has']['body'],
			"a list somebody else owns and a list nobody owns are refused identically"
		);

		// And the owner is served.
		mcm_tmdb_stub_reset($fixture);
		$response = mcm_http_post($app, '/tmdb.php', array(
			'operation'     => 'list',
			'list_id'       => $tmdb_list,
			'movie_list_id' => $alice_list,
		), $as_alice);
		$body = mcm_json_body($response);

		t_same(200, $response['status'], 'the owner of the list is served');
		t_same('list', isset($body['operation']) ? $body['operation'] : '', 'the answer says which operation it is');
		t_same(
			array(
				'data.description', 'data.id', 'data.item_count', 'data.items[].id',
				'data.items[].original_title', 'data.items[].poster_path', 'data.items[].release_date',
				'data.items[].title', 'data.name', 'ok', 'operation',
			),
			mcm_key_paths($body),
			'the list answer holds exactly the projected fields'
		);
		t_same(2, count(mcm_at($body, 'data.items', array())), 'both items of the list come back');
		t_lacks('upstream-only-marker', $response['body'], 'no unprojected upstream field survives the list projection');
		t_lacks('stub-payload-marker', $response['body'], 'and none survives at the top level either');
		t_lacks(mcm_tmdb_test_token(), $response['body'], 'the list answer carries no credential');
		$requests = mcm_tmdb_stub_requests($fixture);
		t_same(1, count($requests), 'the served request made exactly one request to TMDb');
		t_contains('/list/' . $tmdb_list, isset($requests[0]) ? $requests[0] : '', 'and asked for the TMDb list it was given');

		// Reading a list changes nothing here, which is the other half of "read
		// only": issue #38 is what will write rows from it.
		t_same($before, $state(), 'a served list request wrote nothing to any table');

		// Bob's own list is his to read, so the refusals above were about
		// ownership rather than about the operation being unusable.
		mcm_tmdb_stub_reset($fixture);
		$response = mcm_http_post($app, '/tmdb.php', array(
			'operation'     => 'list',
			'list_id'       => $tmdb_list,
			'movie_list_id' => $bob_list,
		), $as_bob);
		t_same(200, $response['status'], 'the other account is served for the list it does own');
	} catch (Throwable $error) {
		mcm_server_stop($app);
		mcm_server_stop($stub);
		throw $error;
	}
	mcm_server_stop($app);
	mcm_server_stop($stub);
});

t_group('tmdb proxy source checks of last resort', function () {
	$proxy = MCM_REPO_ROOT . '/inc/tmdb_proxy.php';
	$entry = MCM_REPO_ROOT . '/tmdb.php';

	t_ok(is_readable($proxy), 'the proxy policy lives in inc/tmdb_proxy.php');
	t_ok(is_readable($entry), 'the entry point lives in tmdb.php');

	// One outbound call, in one place, after everything that decides what it may
	// be. A second one somewhere else in the file would be a path this file's
	// allowlist never saw.
	t_same(1, mcm_count_calls($proxy, 'mcm_tmdb_get'), 'the proxy asks TMDb from exactly one place');
	t_same(0, mcm_count_calls($entry, 'mcm_tmdb_get'), 'the entry point asks TMDb from nowhere');
	t_same(0, mcm_count_calls($proxy, 'curl_init'), 'the proxy opens no handle of its own');
	t_same(0, mcm_count_new($proxy, 'TMDb'), 'the proxy does not build the old vendored wrapper');
	t_same(0, mcm_count_debug_output($proxy), 'the proxy dumps nothing into the response');
	t_same(0, mcm_count_debug_output($entry), 'the entry point dumps nothing into the response');

	// The five operations, named in the file that declares them. The list is
	// written out here rather than read from the source, so adding a sixth has
	// to be a decision somebody made twice.
	$body = mcm_method_tokens($proxy, 'mcm_tmdb_operations');
	t_ok(count($body) > 0, 'mcm_tmdb_operations() is declared');
	$declared = array();
	foreach ($body as $token) {
		if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
			$literal = substr($token['text'], 1, -1);
			if (in_array($literal, array('configuration', 'search', 'movie', 'videos', 'list'), true)) {
				$declared[$literal] = true;
			}
		}
	}
	$declared = array_keys($declared);
	sort($declared);
	t_same(array('configuration', 'list', 'movie', 'search', 'videos'), $declared, 'the allowlist declares the five operations');

	// The one place a caller's request becomes a path is the plan, and the plan
	// reads validated values rather than the request.
	$plan = mcm_method_tokens($proxy, 'mcm_tmdb_plan');
	t_ok(count($plan) > 0, 'mcm_tmdb_plan() is declared');
	t_same(0, mcm_count_calls_in($plan, 'mcm_tmdb_get'), 'planning a request does not make one');

	// The entry point is the door and nothing else: it reads the request, hands
	// it over, and holds no policy of its own.
	$source = mcm_flat_source($entry);
	t_lacks('curl', $source, 'the entry point speaks no HTTP of its own');
	t_lacks('TMDB_READ_ACCESS_TOKEN', $source, 'the entry point names no credential');
	t_same(1, mcm_count_calls($entry, 'mcm_tmdb_serve'), 'the entry point serves the request in one call');
});
