<?php

/**
 * The cases.
 *
 * The first six groups match the six things the shared bootstrap is responsible
 * for: configuration, a single session startup, the session cookie's
 * attributes, compatibility with sessions and remember-me cookies that already
 * exist, error handling, and every entry point loading the bootstrap.
 *
 * The last three cover the shared security primitives in inc/security.php and
 * the way inc/classes/Login.php and inc/classes/Registration.php use them:
 * token generation, password compatibility, and renewing the session identifier
 * whenever a visitor's authentication state changes.
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
 * 6. Entry-point inclusion
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
 * 7. Tokens: how they are generated, compared, and carried in a cookie
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
 * 8. Passwords stored before this change, and rehashing them quietly
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
 * 9. Renewing the session identifier when the visitor's state changes
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
