<?php

/**
 * Dependency-free test harness for the shared bootstrap.
 *
 * Run it with nothing but a PHP CLI:
 *
 *     php tests/run.php                 run every group
 *     php tests/run.php --filter=cookie run the groups whose name matches
 *     php tests/run.php --keep          leave the fixtures on disk afterwards
 *
 * The suite never touches the checkout it is run from. Every case builds a
 * throw-away copy of the application under the system temp directory, points a
 * private error log at it, and exercises that copy either as a child process or
 * through PHP's own built-in web server. Nothing here needs a database, a
 * package manager or a test framework, and no production file changes to make
 * any of it pass.
 *
 * This file provides the machinery:
 *   1. assertions and the group registry;
 *   2. the fixture builder;
 *   3. the child-process runner;
 *   4. a driver around PHP's built-in server, with a small raw HTTP client.
 *
 * The cases live in tests/cases.php and the static checks in
 * tests/entrypoints.php.
 */

if (PHP_SAPI !== 'cli') {
	die("This is a command line test suite.\n");
}

define('MCM_TESTS_DIR', dirname(__FILE__));
define('MCM_REPO_ROOT', dirname(MCM_TESTS_DIR));

/*
 * ---------------------------------------------------------------------------
 * 1. Assertions and the group registry
 * ---------------------------------------------------------------------------
 */

$GLOBALS['mcm_state'] = array(
	'groups'     => array(),
	'group'      => '',
	'assertions' => 0,
	'failures'   => array(),
	'skips'      => array(),
	'started'    => microtime(true),
	'seed'       => '',
);

/**
 * Register a group of cases. Groups run in registration order.
 *
 * @param string   $name     shown in the output and matched by --filter
 * @param callable $callback receives no arguments
 */
function t_group($name, $callback)
{
	$GLOBALS['mcm_state']['groups'][] = array('name' => $name, 'callback' => $callback);
}

/**
 * Record one assertion.
 *
 * @param bool   $ok
 * @param string $label  what was being asserted
 * @param string $detail shown only on failure
 */
function t_ok($ok, $label, $detail = '')
{
	$GLOBALS['mcm_state']['assertions']++;

	if ($ok) {
		echo '  ok    ' . $label . "\n";
		return true;
	}

	$GLOBALS['mcm_state']['failures'][] = array(
		'group'  => $GLOBALS['mcm_state']['group'],
		'label'  => $label,
		'detail' => $detail,
	);
	echo '  FAIL  ' . $label . "\n";
	if ($detail !== '') {
		foreach (explode("\n", rtrim($detail)) as $line) {
			echo '          ' . $line . "\n";
		}
	}

	return false;
}

/**
 * Assert strict equality.
 */
function t_same($expected, $actual, $label)
{
	return t_ok(
		$expected === $actual,
		$label,
		'expected: ' . t_show($expected) . "\nactual:   " . t_show($actual)
	);
}

/**
 * Assert that a string contains a substring.
 */
function t_contains($needle, $haystack, $label)
{
	return t_ok(
		strpos($haystack, $needle) !== false,
		$label,
		'looked for: ' . t_show($needle) . "\nin:         " . t_show($haystack)
	);
}

/**
 * Assert that a string does NOT contain a substring.
 */
function t_lacks($needle, $haystack, $label)
{
	return t_ok(
		strpos($haystack, $needle) === false,
		$label,
		'must not contain: ' . t_show($needle) . "\nin:               " . t_show($haystack)
	);
}

/**
 * Assert that a subject matches a regular expression.
 */
function t_matches($pattern, $subject, $label)
{
	return t_ok(
		preg_match($pattern, $subject) === 1,
		$label,
		'pattern: ' . $pattern . "\nsubject: " . t_show($subject)
	);
}

/**
 * Assert that a subject does not match a regular expression.
 */
function t_not_matches($pattern, $subject, $label)
{
	return t_ok(
		preg_match($pattern, $subject) === 0,
		$label,
		'pattern must not match: ' . $pattern . "\nsubject: " . t_show($subject)
	);
}

/**
 * Record a case that could not run in this environment. Skips never fail the
 * suite, but they are listed in the summary so they cannot pass unnoticed.
 */
function t_skip($label, $why)
{
	$GLOBALS['mcm_state']['skips'][] = $GLOBALS['mcm_state']['group'] . ': ' . $label . ' (' . $why . ')';
	echo '  skip  ' . $label . ' - ' . $why . "\n";
}

/**
 * Render a value for a failure message, kept short enough to read.
 */
function t_show($value)
{
	if (is_string($value)) {
		$value = strtr($value, array("\r" => '\r', "\n" => '\n', "\t" => '\t'));
		if (strlen($value) > 400) {
			$value = substr($value, 0, 400) . '...';
		}
		return '"' . $value . '"';
	}

	return var_export($value, true);
}

/**
 * The per-run secret. It is seeded into failure messages and file names so the
 * suite can prove that detail reaches the log and never reaches the client.
 *
 * @return string
 */
function t_seed()
{
	if ($GLOBALS['mcm_state']['seed'] === '') {
		$GLOBALS['mcm_state']['seed'] = 's' . bin2hex(mcm_random_bytes(8));
	}

	return $GLOBALS['mcm_state']['seed'];
}

/**
 * Random bytes, without assuming a particular PHP version's API.
 *
 * @param int $length
 * @return string
 */
function mcm_random_bytes($length)
{
	if (function_exists('random_bytes')) {
		return random_bytes($length);
	}

	$bytes = '';
	for ($i = 0; $i < $length; $i++) {
		$bytes .= chr(mt_rand(0, 255));
	}

	return $bytes;
}

/*
 * ---------------------------------------------------------------------------
 * 2. Fixtures
 * ---------------------------------------------------------------------------
 *
 * A fixture is a disposable copy of the application:
 *
 *   <work>/<name>/public/         document root, and the working directory of
 *                                 the server or child process
 *   <work>/<name>/public/fonts/   copy of fonts/, because inc/showCaptcha.php
 *                                 reads its font through a path relative to the
 *                                 script ("../fonts/..."). Without this copy the
 *                                 captcha logs a font warning and a case fails
 *                                 for a harness reason rather than a real one.
 *   <work>/<name>/sessions/       session save path, private to the fixture
 *   <work>/<name>/error.log       the private log every case reads
 */

/**
 * Root directory for this run's fixtures.
 *
 * @return string
 */
function mcm_work_dir()
{
	static $dir = null;

	if ($dir === null) {
		$dir = sys_get_temp_dir() . '/mcm-tests-' . getmypid() . '-' . bin2hex(mcm_random_bytes(4));
		mcm_mkdir($dir);
	}

	return $dir;
}

/**
 * Create a directory, including parents.
 */
function mcm_mkdir($path)
{
	if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
		throw new RuntimeException('could not create ' . $path);
	}
}

/**
 * Remove a directory tree.
 */
function mcm_rmtree($path)
{
	if (!file_exists($path)) {
		return;
	}
	if (!is_dir($path) || is_link($path)) {
		@chmod($path, 0666);
		@unlink($path);
		return;
	}

	foreach (scandir($path) as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}
		mcm_rmtree($path . '/' . $entry);
	}
	@chmod($path, 0777);
	@rmdir($path);
}

/**
 * Copy a directory tree, skipping anything the tests do not need.
 */
function mcm_copy_tree($source, $target)
{
	mcm_mkdir($target);

	foreach (scandir($source) as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}
		$from = $source . '/' . $entry;
		$to   = $target . '/' . $entry;

		if (is_dir($from)) {
			mcm_copy_tree($from, $to);
		} else {
			copy($from, $to);
		}
	}
}

/**
 * Build a configuration file body.
 *
 * @param array $defines name => value. A value of null omits the define, so a
 *                       case can drop a required setting. Wrap a value in
 *                       array('raw' => '...') to emit an expression verbatim.
 * @return string
 */
function mcm_config_php(array $defines = array())
{
	$base = array(
		'TMDB_API_KEY'      => 'test-api-key',
		'TMDB_SESSION_ID'   => 'test-session-id',
		'DB_HOST'           => 'localhost',
		'DB_NAME'           => 'mcm_test',
		'DB_USER'           => 'mcm_test',
		'DB_PASS'           => '',
		'COOKIE_RUNTIME'    => 1209600,
		'COOKIE_DOMAIN'     => '.example.test',
		'COOKIE_SECRET_KEY' => 'test-cookie-secret',
		'HASH_COST_FACTOR'  => '10',
	);

	$defines = array_merge($base, $defines);

	// The guard matches the one in inc/config/example_config.php, so a fixture
	// configuration is the same shape as a real one.
	$body = "<?php\n\n"
		. "// Test fixture configuration. Placeholders only; nothing here is real.\n"
		. "if (!defined('MCM_BOOTSTRAP')) {\n"
		. "\theader('HTTP/1.0 403 Forbidden');\n"
		. "\texit('Forbidden');\n"
		. "}\n\n";

	foreach ($defines as $name => $value) {
		if ($value === null) {
			continue;
		}
		if (is_array($value) && isset($value['raw'])) {
			$literal = $value['raw'];
		} else {
			$literal = var_export($value, true);
		}
		$body .= "define('" . $name . "', " . $literal . ");\n";
	}

	return $body;
}

/**
 * Build a fixture.
 *
 * @param string $name    directory name, unique within the run
 * @param array  $options config      - defines for inc/config/config.php
 *                        config_raw  - complete config file body, wins over config
 *                        config_file - false to leave inc/config/config.php absent
 *                        unreadable  - true to make the config file unreadable
 *                        fonts       - true to copy fonts/ (needed by the captcha)
 * @return array
 */
function mcm_fixture($name, array $options = array())
{
	$options += array(
		'config'      => array(),
		'config_raw'  => null,
		'config_file' => true,
		'unreadable'  => false,
		'fonts'       => false,
	);

	$root    = mcm_work_dir() . '/' . $name;
	$public  = $root . '/public';
	$log     = $root . '/error.log';
	$session = $root . '/sessions';

	mcm_rmtree($root);
	mcm_mkdir($public);
	mcm_mkdir($session);

	// The application itself: every document-root entry point plus the include
	// tree. Static assets are left out; nothing under test reads them.
	foreach (glob(MCM_REPO_ROOT . '/*.php') as $file) {
		copy($file, $public . '/' . basename($file));
	}
	mcm_copy_tree(MCM_REPO_ROOT . '/inc', $public . '/inc');
	mcm_rmtree($public . '/inc/config/config.php');

	if ($options['fonts']) {
		mcm_copy_tree(MCM_REPO_ROOT . '/fonts', $public . '/fonts');
	}

	// Probe and fault-injection pages, served from the document root like any
	// other page.
	foreach (glob(MCM_TESTS_DIR . '/pages/*.php') as $file) {
		copy($file, $public . '/' . basename($file));
	}

	$seed = t_seed();
	file_put_contents(
		$public . '/_seed.php',
		"<?php\n\n// Per-run secret, seeded by tests/run.php.\ndefine('MCM_TEST_SEED', '" . $seed . "');\n"
	);
	// A file that cannot be compiled, for the true compile-time fatal case. Its
	// name carries the seed, so the resulting message proves where detail went.
	file_put_contents(
		$public . '/broken_' . $seed . '.php',
		"<?php\n\n\$mcm_this_file_does_not_parse = ;\n"
	);

	if ($options['config_file']) {
		$body = $options['config_raw'] !== null
			? $options['config_raw']
			: mcm_config_php($options['config']);
		file_put_contents($public . '/inc/config/config.php', $body);

		if ($options['unreadable']) {
			chmod($public . '/inc/config/config.php', 0000);
		}
	}

	file_put_contents($log, '');

	return array(
		'name'     => $name,
		'root'     => $root,
		'public'   => $public,
		'log'      => $log,
		'sessions' => $session,
		'seed'     => $seed,
	);
}

/**
 * Seed a session file the files handler will adopt, exactly as a session issued
 * before any of this existed would look on disk.
 *
 * @param array  $fixture
 * @param string $id
 * @param array  $data
 */
function mcm_seed_session(array $fixture, $id, array $data)
{
	$encoded = '';
	foreach ($data as $key => $value) {
		$encoded .= $key . '|' . serialize($value);
	}

	file_put_contents($fixture['sessions'] . '/sess_' . $id, $encoded);
}

/*
 * ---------------------------------------------------------------------------
 * 3. Child-process runner
 * ---------------------------------------------------------------------------
 */

/**
 * The ini settings every fixture process runs with. They stand in for what the
 * web server would provide, and never override anything the bootstrap sets.
 *
 * @param array $fixture
 * @return array
 */
function mcm_base_ini(array $fixture)
{
	return array(
		// Everything the application logs must land in the fixture's own file,
		// including failures that happen before any configuration is loaded.
		'error_log'              => $fixture['log'],
		'log_errors'             => '1',
		'display_errors'         => '0',
		'html_errors'            => '0',
		'session.save_path'      => $fixture['sessions'],
		'session.save_handler'   => 'files',
		// A collection pass mid-run would delete the seeded session file.
		'session.gc_probability' => '0',
	);
}

/**
 * Turn ini settings into "-d name=value" arguments.
 */
function mcm_ini_args(array $ini)
{
	$args = '';
	foreach ($ini as $name => $value) {
		$args .= ' -d ' . escapeshellarg($name . '=' . $value);
	}

	return $args;
}

/**
 * Run one page as a child process and collect everything it produced.
 *
 * @param array  $fixture
 * @param string $script  file name inside the document root
 * @param array  $options ini    - extra ini settings
 *                        env    - extra environment variables
 *                        args   - extra command line arguments
 * @return array status, stdout, stderr, log
 */
function mcm_cli(array $fixture, $script, array $options = array())
{
	$options += array('ini' => array(), 'env' => array(), 'args' => '');

	file_put_contents($fixture['log'], '');

	$command = escapeshellarg(PHP_BINARY)
		. mcm_ini_args(array_merge(mcm_base_ini($fixture), $options['ini']))
		. ' ' . escapeshellarg($fixture['public'] . '/' . $script)
		. ($options['args'] === '' ? '' : ' ' . $options['args']);

	$descriptors = array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
		2 => array('pipe', 'w'),
	);

	$env = array_merge(mcm_environment(), $options['env']);

	$process = proc_open($command, $descriptors, $pipes, $fixture['public'], $env);
	if (!is_resource($process)) {
		throw new RuntimeException('could not start ' . $command);
	}

	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);

	return array(
		'status' => $status,
		'stdout' => $stdout,
		'stderr' => $stderr,
		'log'    => file_get_contents($fixture['log']),
	);
}

/**
 * The current environment as a name => value map, for handing to proc_open.
 *
 * @return array
 */
function mcm_environment()
{
	$env = array();
	foreach ($_SERVER as $name => $value) {
		if (is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
			$env[$name] = $value;
		}
	}

	return $env;
}

/*
 * ---------------------------------------------------------------------------
 * 4. Built-in server driver and raw HTTP client
 * ---------------------------------------------------------------------------
 *
 * Response headers are read off the wire rather than out of headers_list(), so
 * the cookie attribute cases assert what a browser would actually receive.
 */

/**
 * Pick a free TCP port by letting the operating system choose one.
 *
 * @return int
 */
function mcm_free_port()
{
	$socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
	if (!$socket) {
		throw new RuntimeException('could not reserve a port: ' . $error);
	}
	$name = stream_socket_get_name($socket, false);
	fclose($socket);

	return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * Start PHP's built-in server on a fixture.
 *
 * @param array $fixture
 * @param array $ini     extra ini settings
 * @return array
 */
function mcm_server_start(array $fixture, array $ini = array())
{
	$port   = mcm_free_port();
	$stderr = $fixture['root'] . '/server.log';

	// "exec" matters: without it the shell that proc_open spawns is what gets
	// signalled, the server survives as an orphan and the suite hangs waiting
	// for a port that never frees.
	$command = 'exec ' . escapeshellarg(PHP_BINARY)
		. mcm_ini_args(array_merge(mcm_base_ini($fixture), $ini))
		. ' -S 127.0.0.1:' . $port
		. ' -t ' . escapeshellarg($fixture['public']);

	$descriptors = array(
		0 => array('pipe', 'r'),
		1 => array('file', $stderr, 'a'),
		2 => array('file', $stderr, 'a'),
	);

	// The working directory is the document root, which is where a request
	// handled by the production server also resolves relative paths from.
	$process = proc_open($command, $descriptors, $pipes, $fixture['public'], mcm_environment());
	if (!is_resource($process)) {
		throw new RuntimeException('could not start the built-in server');
	}
	fclose($pipes[0]);

	$server = array(
		'process' => $process,
		'port'    => $port,
		'host'    => '127.0.0.1',
		'fixture' => $fixture,
		'stderr'  => $stderr,
	);

	for ($attempt = 0; $attempt < 200; $attempt++) {
		$probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.5);
		if ($probe) {
			fclose($probe);
			return $server;
		}
		usleep(25000);
	}

	mcm_server_stop($server);
	throw new RuntimeException('the built-in server did not come up on port ' . $port);
}

/**
 * Stop a server started by mcm_server_start().
 */
function mcm_server_stop(array $server)
{
	if (!is_resource($server['process'])) {
		return;
	}

	proc_terminate($server['process']);
	for ($attempt = 0; $attempt < 100; $attempt++) {
		$status = proc_get_status($server['process']);
		if (!$status['running']) {
			break;
		}
		usleep(20000);
	}
	proc_close($server['process']);
}

/**
 * Make one request and return the raw response, split into parts.
 *
 * @param array  $server
 * @param string $path
 * @param array  $options headers - extra request header lines
 *                        method  - defaults to GET
 * @return array status, headers, body, log
 */
function mcm_http(array $server, $path, array $options = array())
{
	$options += array('headers' => array(), 'method' => 'GET');

	file_put_contents($server['fixture']['log'], '');

	$socket = @fsockopen($server['host'], $server['port'], $errno, $error, 5);
	if (!$socket) {
		throw new RuntimeException('could not connect to the built-in server: ' . $error);
	}
	stream_set_timeout($socket, 10);

	$request = $options['method'] . ' ' . $path . " HTTP/1.1\r\n"
		. 'Host: ' . $server['host'] . ':' . $server['port'] . "\r\n"
		. "Connection: close\r\n";
	foreach ($options['headers'] as $header) {
		$request .= $header . "\r\n";
	}
	$request .= "\r\n";

	fwrite($socket, $request);

	$raw = '';
	while (!feof($socket)) {
		$chunk = fread($socket, 8192);
		if ($chunk === false || $chunk === '') {
			break;
		}
		$raw .= $chunk;
	}
	fclose($socket);

	$split = strpos($raw, "\r\n\r\n");
	if ($split === false) {
		throw new RuntimeException('malformed response: ' . t_show($raw));
	}
	$head = substr($raw, 0, $split);
	$body = substr($raw, $split + 4);

	$lines  = explode("\r\n", $head);
	$status = 0;
	if (preg_match('#^HTTP/[0-9.]+ (\d{3})#', array_shift($lines), $match)) {
		$status = (int) $match[1];
	}

	$headers = array();
	foreach ($lines as $line) {
		$colon = strpos($line, ':');
		if ($colon !== false) {
			$headers[] = array(trim(substr($line, 0, $colon)), trim(substr($line, $colon + 1)));
		}
	}

	return array(
		'status'  => $status,
		'headers' => $headers,
		'body'    => $body,
		'log'     => file_get_contents($server['fixture']['log']),
	);
}

/**
 * All values of one response header, in the order they arrived.
 *
 * @return array
 */
function mcm_header_values(array $response, $name)
{
	$values = array();
	foreach ($response['headers'] as $header) {
		if (strcasecmp($header[0], $name) === 0) {
			$values[] = $header[1];
		}
	}

	return $values;
}

/**
 * Parse a probe page's "key=value" report into an array.
 *
 * @param string $output
 * @return array
 */
function mcm_report($output)
{
	$report = array();
	foreach (explode("\n", $output) as $line) {
		$line = rtrim($line, "\r");
		if ($line === '') {
			continue;
		}
		$equals = strpos($line, '=');
		if ($equals !== false) {
			$report[substr($line, 0, $equals)] = substr($line, $equals + 1);
		}
	}

	return $report;
}

/**
 * The generic failure message the bootstrap ships with, as a client sees it.
 *
 * @return string
 */
function mcm_generic_message()
{
	return "Sorry, something went wrong. Please try again later.\n";
}

/*
 * ---------------------------------------------------------------------------
 * 5. Run
 * ---------------------------------------------------------------------------
 */

require_once MCM_TESTS_DIR . '/entrypoints.php';
require_once MCM_TESTS_DIR . '/cases.php';

$mcm_filter = '';
$mcm_keep   = false;
foreach (array_slice($argv, 1) as $mcm_argument) {
	if (strpos($mcm_argument, '--filter=') === 0) {
		$mcm_filter = substr($mcm_argument, 9);
	} elseif ($mcm_argument === '--keep') {
		$mcm_keep = true;
	} else {
		die("Usage: php tests/run.php [--filter=<substring>] [--keep]\n");
	}
}

echo 'mcm bootstrap test suite - PHP ' . PHP_VERSION . ' (' . PHP_BINARY . ")\n";
echo 'fixtures: ' . mcm_work_dir() . "\n";

foreach ($GLOBALS['mcm_state']['groups'] as $mcm_group) {
	if ($mcm_filter !== '' && stripos($mcm_group['name'], $mcm_filter) === false) {
		continue;
	}

	$GLOBALS['mcm_state']['group'] = $mcm_group['name'];
	echo "\n== " . $mcm_group['name'] . " ==\n";
	call_user_func($mcm_group['callback']);
}

$mcm_elapsed  = microtime(true) - $GLOBALS['mcm_state']['started'];
$mcm_failures = $GLOBALS['mcm_state']['failures'];

echo "\n";
if (count($GLOBALS['mcm_state']['skips']) > 0) {
	echo "skipped:\n";
	foreach ($GLOBALS['mcm_state']['skips'] as $mcm_skip) {
		echo '  - ' . $mcm_skip . "\n";
	}
	echo "\n";
}

if (count($mcm_failures) > 0) {
	echo "failures:\n";
	foreach ($mcm_failures as $mcm_failure) {
		echo '  - [' . $mcm_failure['group'] . '] ' . $mcm_failure['label'] . "\n";
	}
	echo "\n";
}

echo sprintf(
	"%s: %d assertions, %d failures, %d skipped in %.2fs\n",
	count($mcm_failures) === 0 ? 'PASS' : 'FAIL',
	$GLOBALS['mcm_state']['assertions'],
	count($mcm_failures),
	count($GLOBALS['mcm_state']['skips']),
	$mcm_elapsed
);

if ($mcm_keep) {
	echo 'fixtures kept in ' . mcm_work_dir() . "\n";
} else {
	mcm_rmtree(mcm_work_dir());
}

exit(count($mcm_failures) === 0 ? 0 : 1);
