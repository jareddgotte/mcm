<?php

/**
 * The cases.
 *
 * Seven groups, matching what the shared bootstrap is responsible for:
 * configuration, a single session startup, the session cookie's attributes,
 * compatibility with sessions and remember-me cookies that already exist, error
 * handling, where a redirect is allowed to send a visitor, and every entry
 * point loading the bootstrap.
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
