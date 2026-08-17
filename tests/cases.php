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
 * Three more cover the shared security primitives in inc/security.php and
 * the way inc/classes/Login.php and inc/classes/Registration.php use them:
 * token generation, password compatibility, and renewing the session identifier
 * whenever a visitor's authentication state changes.
 *
 * The last two cover what the bootstrap's escaping helpers do with a hostile
 * string, and the bounded validation a submitted list name has to pass.
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
	// deployed by hand.
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
 * 12. Escaping what the server renders
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
 * 13. Bounded validation of a submitted list name
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
	// the database, so the refusal is observable without one.
	$server = mcm_server_start($fixture);
	try {
		$long = str_repeat('a', 65);
		foreach (array('create_list.php?list_rank=0&list_name=', 'rename_list.php?movie_list_id=1&list_name=') as $path) {
			$page     = substr($path, 0, strpos($path, '?'));
			$response = mcm_http($server, '/' . $path . rawurlencode($long));

			t_same(200, $response['status'], $page . ' answers a name that is too long');
			t_same('Error: List name is longer than 64 characters.', $response['body'], $page . ' refuses a name that is too long');
			t_same('', $response['log'], $page . ' never reached the database');

			// A name full of markup is not what validation is for: it goes
			// through, and gets as far as the database this fixture has none of.
			$response = mcm_http($server, '/' . $path . rawurlencode('<script>alert(1)</script>'));
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
