<?php

/**
 * Dependency-free test harness for the shared bootstrap.
 *
 *     php tests/run.php                 run every group
 *     php tests/run.php --filter=cookie run the groups whose name matches
 *
 * Needs nothing but a PHP CLI. Every case builds a throw-away copy of the
 * application under the system temp directory and drives it either as a child
 * process or through PHP's built-in server, so a run never touches the
 * checkout. The machinery lives here; the cases are in tests/cases.php and the
 * static checks in tests/entrypoints.php.
 */

if (PHP_SAPI !== 'cli') {
	die("This is a command line test suite.\n");
}

define('MCM_TESTS_DIR', dirname(__FILE__));
define('MCM_REPO_ROOT', dirname(MCM_TESTS_DIR));

/* Assertions and the group registry ---------------------------------------- */

$GLOBALS['mcm_state'] = array(
	'groups'     => array(),
	'group'      => '',
	'assertions' => 0,
	'failures'   => array(),
	'skipped'    => 0,
	'started'    => microtime(true),
);

/** Register a group of cases. They run in registration order; --filter matches on the name. */
function t_group($name, $callback)
{
	$GLOBALS['mcm_state']['groups'][] = array('name' => $name, 'callback' => $callback);
}

/** Record one assertion. $detail is shown only on failure. */
function t_ok($ok, $label, $detail = '')
{
	$GLOBALS['mcm_state']['assertions']++;

	if ($ok) {
		echo '  ok    ' . $label . "\n";
		return true;
	}

	$GLOBALS['mcm_state']['failures'][] = array(
		'group' => $GLOBALS['mcm_state']['group'],
		'label' => $label,
	);
	echo '  FAIL  ' . $label . "\n";
	foreach (explode("\n", rtrim($detail)) as $line) {
		if ($line !== '') {
			echo '          ' . $line . "\n";
		}
	}
	return false;
}

/** Assert strict equality. */
function t_same($expected, $actual, $label)
{
	return t_ok($expected === $actual, $label, 'expected: ' . t_show($expected) . "\nactual:   " . t_show($actual));
}

/** Assert that a string contains a substring. */
function t_contains($needle, $haystack, $label)
{
	return t_ok(strpos($haystack, $needle) !== false, $label, 'looked for: ' . t_show($needle) . "\nin:         " . t_show($haystack));
}

/** Assert that a string does not contain a substring. */
function t_lacks($needle, $haystack, $label)
{
	return t_ok(strpos($haystack, $needle) === false, $label, 'must not contain: ' . t_show($needle) . "\nin:               " . t_show($haystack));
}

/**
 * Assert a regular expression match. Cookie attributes need this rather than a
 * substring: "path=/" is a substring of "path=/movies/", which a fixture here emits.
 */
function t_matches($pattern, $subject, $label)
{
	return t_ok(preg_match($pattern, $subject) === 1, $label, 'pattern: ' . $pattern . "\nsubject: " . t_show($subject));
}

/** Assert that a regular expression does not match. */
function t_not_matches($pattern, $subject, $label)
{
	return t_ok(preg_match($pattern, $subject) === 0, $label, 'must not match: ' . $pattern . "\nsubject: " . t_show($subject));
}

/** Record a case that could not run here. Skips never fail the suite. */
function t_skip($label, $why)
{
	$GLOBALS['mcm_state']['skipped']++;
	echo '  skip  ' . $label . ' - ' . $why . "\n";
}

/** Render a value for a failure message, kept short enough to read. */
function t_show($value)
{
	if (!is_string($value)) {
		return var_export($value, true);
	}

	$value = strtr($value, array("\r" => '\r', "\n" => '\n', "\t" => '\t'));
	if (strlen($value) > 400) {
		$value = substr($value, 0, 400) . '...';
	}
	return '"' . $value . '"';
}

/* Fixtures - a disposable <work>/<name>/ per case, holding public/ (the
 * document root, and the working directory of the server or child process),
 * sessions/ (the session save path) and error.log (the private log). ------- */

/** Root directory for this run's fixtures. */
function mcm_work_dir()
{
	static $dir = null;

	if ($dir === null) {
		$dir = sys_get_temp_dir() . '/mcm-tests-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mcm_mkdir($dir);
	}
	return $dir;
}

/** Create a directory, including parents. */
function mcm_mkdir($path)
{
	if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
		throw new RuntimeException('could not create ' . $path);
	}
}

/** Remove a file or directory tree. */
function mcm_rmtree($path)
{
	if (!file_exists($path)) {
		return;
	}
	if (!is_dir($path) || is_link($path)) {
		// The mode-0000 configuration fixture has to be removable again.
		@chmod($path, 0666);
		@unlink($path);
		return;
	}

	foreach (scandir($path) as $entry) {
		if ($entry !== '.' && $entry !== '..') {
			mcm_rmtree($path . '/' . $entry);
		}
	}
	@chmod($path, 0777);
	@rmdir($path);
}

/** Copy a directory tree. */
function mcm_copy_tree($source, $target)
{
	mcm_mkdir($target);

	foreach (scandir($source) as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}
		if (is_dir($source . '/' . $entry)) {
			mcm_copy_tree($source . '/' . $entry, $target . '/' . $entry);
		} else {
			copy($source . '/' . $entry, $target . '/' . $entry);
		}
	}
}

/**
 * Build a configuration file body. A define whose value is null is omitted, so
 * a case can drop a required setting.
 */
function mcm_config_php(array $defines = array())
{
	$defines += array(
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

	// The guard matches inc/config/example_config.php, so a fixture
	// configuration is the same shape as a real one.
	$body = "<?php\n\n"
		. "// Test fixture configuration. Placeholders only; nothing here is real.\n"
		. "if (!defined('MCM_BOOTSTRAP')) {\n"
		. "\theader('HTTP/1.0 403 Forbidden');\n"
		. "\texit('Forbidden');\n"
		. "}\n\n";

	foreach ($defines as $name => $value) {
		if ($value !== null) {
			$body .= "define('" . $name . "', " . var_export($value, true) . ");\n";
		}
	}
	return $body;
}

/**
 * Build a fixture: a disposable copy of the application with its own
 * configuration, session store and error log.
 *
 * @param array $options config      - defines for inc/config/config.php
 *                       config_raw  - complete config body, wins over config
 *                       config_file - false to leave inc/config/config.php absent
 *                       unreadable  - true to make the config file unreadable
 */
function mcm_fixture($name, array $options = array())
{
	$options += array(
		'config'      => array(),
		'config_raw'  => null,
		'config_file' => true,
		'unreadable'  => false,
	);

	// One secret per run, seeded into failure messages and file names so the
	// suite can prove detail reaches the log and never reaches the client.
	static $seed = null;
	if ($seed === null) {
		$seed = 's' . bin2hex(random_bytes(8));
	}

	$root   = mcm_work_dir() . '/' . $name;
	$public = $root . '/public';

	mcm_rmtree($root);
	mcm_mkdir($public);
	mcm_mkdir($root . '/sessions');
	foreach (glob(MCM_REPO_ROOT . '/*.php') as $file) {
		copy($file, $public . '/' . basename($file));
	}
	mcm_copy_tree(MCM_REPO_ROOT . '/inc', $public . '/inc');
	mcm_rmtree($public . '/inc/config/config.php');

	// fonts/ is copied into every fixture on purpose. inc/showCaptcha.php reads
	// its font through a path relative to the script ("../fonts/..."), so a
	// fixture without it makes the captcha log a font warning and a case fail
	// for a harness reason rather than a real one.
	mcm_copy_tree(MCM_REPO_ROOT . '/fonts', $public . '/fonts');

	// Probe and fault-injection pages, served from the document root like any
	// other page.
	foreach (glob(MCM_TESTS_DIR . '/pages/*.php') as $file) {
		copy($file, $public . '/' . basename($file));
	}

	file_put_contents($public . '/_seed.php', "<?php\n\n// Per-run secret, seeded by tests/run.php.\ndefine('MCM_TEST_SEED', '" . $seed . "');\n");
	// Two pages the fault cases need: one that cannot be compiled, and one that
	// declares a function, so requiring it twice is a compile-time fatal. Both
	// carry the seed, so the resulting message proves where detail went.
	file_put_contents($public . '/broken_' . $seed . '.php', "<?php\n\n\$mcm_this_file_does_not_parse = ;\n");
	file_put_contents($public . '/declares_' . $seed . '.php', "<?php\n\nfunction mcm_declared_" . $seed . "()\n{\n\treturn true;\n}\n");

	if ($options['config_file']) {
		$body = $options['config_raw'] !== null ? $options['config_raw'] : mcm_config_php($options['config']);
		file_put_contents($public . '/inc/config/config.php', $body);

		if ($options['unreadable']) {
			chmod($public . '/inc/config/config.php', 0000);
		}
	}

	file_put_contents($root . '/error.log', '');

	return array(
		'root'     => $root,
		'public'   => $public,
		'log'      => $root . '/error.log',
		'sessions' => $root . '/sessions',
		'seed'     => $seed,
	);
}

/**
 * Seed a session file the files handler will adopt, exactly as a session issued
 * before any of this existed would look on disk.
 */
function mcm_seed_session(array $fixture, $id, array $data)
{
	$encoded = '';
	foreach ($data as $key => $value) {
		$encoded .= $key . '|' . serialize($value);
	}
	file_put_contents($fixture['sessions'] . '/sess_' . $id, $encoded);
}

/** The session identifiers the fixture currently has files for, sorted. */
function mcm_session_files(array $fixture)
{
	$found = array();
	foreach (scandir($fixture['sessions']) as $entry) {
		if (strpos($entry, 'sess_') === 0) {
			$found[] = substr($entry, 5);
		}
	}
	sort($found);
	return $found;
}

/* Running a fixture --------------------------------------------------------- */

/**
 * The ini settings every fixture process runs with, as "-d name=value"
 * arguments. They stand in for what the web server provides, and never override
 * anything the bootstrap sets.
 */
function mcm_ini_args(array $fixture)
{
	$ini = array(
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

	$args = '';
	foreach ($ini as $name => $value) {
		$args .= ' -d ' . escapeshellarg($name . '=' . $value);
	}
	return $args;
}

/**
 * Run one page, named relative to the document root, as a child process.
 *
 * @param array $env extra environment variables for the page, so a case can
 *                   hand it a value - a stored password hash, a cookie issued
 *                   by an older version of the site - without writing it into
 *                   the fixture.
 * @return array status, stdout, log
 */
function mcm_cli(array $fixture, $script, array $env = array())
{
	file_put_contents($fixture['log'], '');

	$command = escapeshellarg(PHP_BINARY) . mcm_ini_args($fixture) . ' ' . escapeshellarg($fixture['public'] . '/' . $script);
	$pipes   = array();
	$output  = array('file', $fixture['root'] . '/stderr.log', 'a');
	// proc_open() replaces the whole environment rather than adding to it, so
	// this process's own is merged in; without it the child loses PATH.
	$environment = (count($env) > 0) ? $env + getenv() : null;
	$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => $output), $pipes, $fixture['public'], $environment);

	if (!is_resource($process)) {
		throw new RuntimeException('could not start ' . $command);
	}

	$stdout = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	return array(
		'status' => proc_close($process),
		'stdout' => $stdout,
		'log'    => file_get_contents($fixture['log']),
	);
}

/** Start PHP's built-in server on a fixture, on a port the system picks. */
function mcm_server_start(array $fixture)
{
	$socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
	if (!$socket) {
		throw new RuntimeException('could not reserve a port: ' . $error);
	}
	$name = stream_socket_get_name($socket, false);
	$port = (int) substr($name, strrpos($name, ':') + 1);
	fclose($socket);

	// "exec" matters: without it the shell that proc_open spawns is what gets
	// signalled, the server survives as an orphan and the suite hangs waiting
	// for a port that never frees.
	$command = 'exec ' . escapeshellarg(PHP_BINARY) . mcm_ini_args($fixture)
		. ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($fixture['public']);

	// The server's own chatter goes to a file rather than a pipe, which nobody
	// would drain. The working directory is the document root.
	$pipes   = array();
	$log     = array('file', $fixture['root'] . '/server.log', 'a');
	$process = proc_open($command, array(1 => $log, 2 => $log), $pipes, $fixture['public']);

	if (!is_resource($process)) {
		throw new RuntimeException('could not start the built-in server');
	}

	$server = array('process' => $process, 'port' => $port, 'host' => '127.0.0.1', 'fixture' => $fixture);
	// Without this poll the first request of every group races the server.
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

/** Stop a server started by mcm_server_start(). */
function mcm_server_stop(array $server)
{
	if (is_resource($server['process'])) {
		proc_terminate($server['process']);
		proc_close($server['process']);
	}
}

/**
 * Make one request, with optional extra request header lines. The response is
 * read off the wire rather than out of headers_list(), so the cookie cases
 * assert what a browser would actually receive.
 *
 * A supplied "Host:" line replaces the default one rather than being added to
 * it: two Host headers is a malformed request, and a case that spoofs the host
 * needs the server to see exactly one.
 *
 * @param string $method GET unless a case needs the request method itself to
 *                       matter, as the method-preserving redirect does
 * @return array status, headers, body, log
 */
function mcm_http(array $server, $path, array $headers = array(), $method = 'GET')
{
	file_put_contents($server['fixture']['log'], '');

	$socket = @fsockopen($server['host'], $server['port'], $errno, $error, 5);
	if (!$socket) {
		throw new RuntimeException('could not connect to the built-in server: ' . $error);
	}
	stream_set_timeout($socket, 10);

	$lines = array(
		'Host: ' . $server['host'] . ':' . $server['port'],
		'Connection: close',
	);
	if (strtoupper($method) !== 'GET' && strtoupper($method) !== 'HEAD') {
		// A request with a method that may carry a body has to say it carries
		// none, or the server waits for one.
		$lines[] = 'Content-Length: 0';
	}
	foreach ($headers as $header) {
		if (stripos($header, 'host:') === 0) {
			$lines[0] = $header;
		} else {
			$lines[] = $header;
		}
	}

	$request = strtoupper($method) . ' ' . $path . " HTTP/1.1\r\n" . implode("\r\n", $lines) . "\r\n";
	fwrite($socket, $request . "\r\n");
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

	$lines  = explode("\r\n", substr($raw, 0, $split));
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
		'body'    => substr($raw, $split + 4),
		'log'     => file_get_contents($server['fixture']['log']),
	);
}

/** All values of one response header, in the order they arrived. */
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
 * Parse a response body as HTML, so a case can ask what the browser would
 * actually build rather than which characters happen to be in the response.
 *
 * @return DOMXPath|null null when this PHP has no DOM extension
 */
function mcm_dom($html)
{
	if (!class_exists('DOMDocument')) {
		return null;
	}

	$document = new DOMDocument();
	$previous = libxml_use_internal_errors(true);
	// The page declares UTF-8 in a meta tag, but libxml only believes an
	// encoding it sees ahead of the markup.
	$document->loadHTML('<?xml encoding="UTF-8">' . $html);
	libxml_clear_errors();
	libxml_use_internal_errors($previous);

	return new DOMXPath($document);
}

/** The element with this id, or null. */
function mcm_element($xpath, $id)
{
	if ($xpath === null) {
		return null;
	}
	$found = $xpath->query('//*[@id="' . $id . '"]');

	return ($found->length > 0) ? $found->item(0) : null;
}

/** Parse a probe page's "key=value" report into an array. */
function mcm_report($output)
{
	$report = array();
	foreach (explode("\n", $output) as $line) {
		$equals = strpos($line, '=');
		if ($equals !== false) {
			$report[substr($line, 0, $equals)] = rtrim(substr($line, $equals + 1), "\r");
		}
	}
	return $report;
}

/** The generic failure message the bootstrap ships with, as a client sees it. */
function mcm_generic_message()
{
	return "Sorry, something went wrong. Please try again later.\n";
}

/* Run ----------------------------------------------------------------------- */

require_once MCM_TESTS_DIR . '/entrypoints.php';
require_once MCM_TESTS_DIR . '/cases.php';

$mcm_filter = '';
foreach (array_slice($argv, 1) as $mcm_argument) {
	if (strpos($mcm_argument, '--filter=') === 0) {
		$mcm_filter = substr($mcm_argument, 9);
	} else {
		die("Usage: php tests/run.php [--filter=<substring>]\n");
	}
}

echo 'mcm bootstrap test suite - PHP ' . PHP_VERSION . "\n";

foreach ($GLOBALS['mcm_state']['groups'] as $mcm_group) {
	if ($mcm_filter !== '' && stripos($mcm_group['name'], $mcm_filter) === false) {
		continue;
	}

	$GLOBALS['mcm_state']['group'] = $mcm_group['name'];
	echo "\n== " . $mcm_group['name'] . " ==\n";
	call_user_func($mcm_group['callback']);
}

$mcm_failures = $GLOBALS['mcm_state']['failures'];

echo "\n";
foreach ($mcm_failures as $mcm_failure) {
	echo 'failed: [' . $mcm_failure['group'] . '] ' . $mcm_failure['label'] . "\n";
}

echo sprintf(
	"%s: %d assertions, %d failures, %d skipped in %.2fs\n",
	count($mcm_failures) === 0 ? 'PASS' : 'FAIL',
	$GLOBALS['mcm_state']['assertions'],
	count($mcm_failures),
	$GLOBALS['mcm_state']['skipped'],
	microtime(true) - $GLOBALS['mcm_state']['started']
);

mcm_rmtree(mcm_work_dir());

exit(count($mcm_failures) === 0 ? 0 : 1);
