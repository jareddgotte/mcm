<?php

/**
 * The test harness: everything a case is written against.
 *
 * The cases in tests/cases.php - and the static checks in
 * tests/entrypoints.php and the optional database group in tests/database.php -
 * are written once, here, and two runners consume them:
 *
 *     php tests/run.php        the dependency-free runner, which needs a PHP
 *                              CLI and nothing else, and is the only one that
 *                              reaches PHP 8.1
 *     vendor/bin/phpunit       PHPUnit 12, which adds discovery, group
 *                              selection and a machine-readable report, and
 *                              needs PHP 8.3 or later
 *
 * Neither runner owns a case. A group registers itself with t_group() and
 * asserts through t_ok() and its wrappers; a runner installs a recorder with
 * mcm_set_recorder() and decides what an assertion, a skip and a note do. The
 * dependency-free runner's recorder prints and counts; PHPUnit's turns each
 * assertion into a PHPUnit assertion. Nothing below knows which is running.
 *
 * The rest is the machinery the assertions are made against: a throw-away copy
 * of the application per case under the system temp directory, driven either as
 * a child process or through PHP's built-in server, so a run never touches the
 * checkout. The hard part does not move between runners, and could not: a
 * request the bootstrap refuses ends in exit(), which would take an in-process
 * runner with it, so those assertions are made from a child.
 */

if (!defined('MCM_TESTS_DIR')) {
	define('MCM_TESTS_DIR', dirname(__FILE__));
}
if (!defined('MCM_REPO_ROOT')) {
	define('MCM_REPO_ROOT', dirname(MCM_TESTS_DIR));
}

/* Assertions and the group registry ---------------------------------------- */

$GLOBALS['mcm_state'] = array(
	'groups'     => array(),
	'group'      => '',
	'assertions' => 0,
	'failures'   => array(),
	'skipped'    => 0,
	'skips'      => array(),
	'started'    => microtime(true),
	// The runner's recorder, installed by mcm_set_recorder(). Null means the
	// dependency-free one below, which prints a line per assertion and counts.
	'recorder'   => null,
);

/**
 * What a group needs to run, as tags. A group declares its own at
 * registration; nothing is inferred from the name.
 *
 *   source    reads the project's own files and starts no process at all
 *   fixture   builds a throw-away copy of the site and drives it as a child
 *   server    additionally listens on a socket and talks to it - PHP's
 *             built-in server, or a stand-in of this suite's own bound to
 *             the loopback interface
 *   database  additionally needs the optional, private database server, and
 *             skips loudly without one
 *
 * The two tier tags are derived from those by mcm_group_tags() rather than
 * declared, so the line between a quick group and a longer one is drawn in one
 * place: "quick" is anything that listens on no socket, "integration" is the
 * rest. A caller selects with --group=<tag> here and --group <tag> under
 * PHPUnit.
 */
function mcm_requirement_tags()
{
	return array('source', 'fixture', 'server', 'database');
}

/**
 * Every tag a group answers to: what it declared, plus its tier.
 *
 * @param array $group as t_group() stored it
 * @return array
 */
function mcm_group_tags(array $group)
{
	$tags = $group['tags'];
	$tags[] = (in_array('server', $tags, true) || in_array('database', $tags, true)) ? 'integration' : 'quick';

	return $tags;
}

/**
 * Register a group of cases. They run in registration order; --filter matches
 * on the name and --group on a tag.
 *
 * @param string   $name
 * @param array    $tags what the group needs, from mcm_requirement_tags()
 * @param callable $callback
 */
function t_group($name, array $tags, $callback)
{
	foreach ($tags as $tag) {
		if (!in_array($tag, mcm_requirement_tags(), true)) {
			throw new RuntimeException('unknown tag "' . $tag . '" on group "' . $name . '"');
		}
	}

	$GLOBALS['mcm_state']['groups'][] = array(
		'name'     => $name,
		'tags'     => $tags,
		'callback' => $callback,
	);
}

/** Every registered group, in registration order. */
function mcm_groups()
{
	return $GLOBALS['mcm_state']['groups'];
}

/**
 * Whether a group is one the caller asked for.
 *
 * @param array  $group  as t_group() stored it
 * @param string $filter a substring of the name, or '' for every name
 * @param string $tag    a tag from mcm_group_tags(), or '' for every tag
 */
function mcm_group_selected(array $group, $filter, $tag)
{
	if ($filter !== '' && stripos($group['name'], $filter) === false) {
		return false;
	}
	if ($tag !== '' && !in_array($tag, mcm_group_tags($group), true)) {
		return false;
	}
	return true;
}

/**
 * Install the runner's recorder, and return the one it replaces.
 *
 * The recorder is called as $recorder($what, $a, $b, $c) with $what one of
 * 'assert' ($ok, $label, $detail), 'skip' ($label, $why) or 'note' ($text).
 * An 'assert' call returns whether the assertion held, because cases branch on
 * it; the others return nothing.
 *
 * @param callable|null $recorder null restores the printing one
 * @return callable|null
 */
function mcm_set_recorder($recorder)
{
	$previous = $GLOBALS['mcm_state']['recorder'];
	$GLOBALS['mcm_state']['recorder'] = $recorder;

	return $previous;
}

/** Record one assertion. $detail is shown only on failure. */
function t_ok($ok, $label, $detail = '')
{
	$ok = (bool) $ok;
	$GLOBALS['mcm_state']['assertions']++;

	if (!$ok) {
		$GLOBALS['mcm_state']['failures'][] = array(
			'group' => $GLOBALS['mcm_state']['group'],
			'label' => $label,
		);
	}

	$recorder = $GLOBALS['mcm_state']['recorder'];
	if ($recorder !== null) {
		call_user_func($recorder, 'assert', $ok, $label, $detail);
		return $ok;
	}

	if ($ok) {
		echo '  ok    ' . $label . "\n";
		return true;
	}

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
	$GLOBALS['mcm_state']['skips'][] = array(
		'group' => $GLOBALS['mcm_state']['group'],
		'label' => $label,
		'why'   => $why,
	);

	$recorder = $GLOBALS['mcm_state']['recorder'];
	if ($recorder !== null) {
		call_user_func($recorder, 'skip', $label, $why);
		return;
	}
	echo '  skip  ' . $label . ' - ' . $why . "\n";
}

/**
 * Say something to whoever is reading the run that is not an assertion: which
 * database server a group found, or the long notice the optional group prints
 * when it did not run at all.
 *
 * A case must not echo directly. The dependency-free runner prints to the
 * screen it already owns; PHPUnit owns its own, and a note that went straight
 * to stdout would land outside the test it belongs to.
 */
function t_note($text)
{
	$recorder = $GLOBALS['mcm_state']['recorder'];
	if ($recorder !== null) {
		call_user_func($recorder, 'note', $text);
		return;
	}
	echo rtrim($text, "\n") . "\n";
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
	$dir = mcm_work_dir_path();

	if ($dir === '') {
		$dir = sys_get_temp_dir() . '/mcm-tests-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mcm_mkdir($dir);
		mcm_work_dir_path($dir);
	}
	return $dir;
}

/**
 * The work directory this run built, or an empty string when it has not built
 * one.
 *
 * mcm_cleanup() asks this rather than mcm_work_dir(), because a run that
 * never needed a fixture must not have one created for it on the way out just
 * so it can be deleted again.
 *
 * @param string|null $set the directory, when mcm_work_dir() has just made it
 */
function mcm_work_dir_path($set = null)
{
	static $dir = '';

	if ($set !== null) {
		$dir = $set;
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
		// Fake, and the only credential this suite ever sends. It goes to a
		// stub on the loopback interface; no case here contacts TMDb.
		'TMDB_READ_ACCESS_TOKEN' => 'test-tmdb-read-token',
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

	file_put_contents($public . '/_seed.php', "<?php\n\n// Per-run secret, seeded by tests/harness.php.\ndefine('MCM_TEST_SEED', '" . $seed . "');\n");
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

/**
 * The CSRF token a seeded session holds, derived from its own identifier.
 *
 * Deriving it means a case names one value - the session - and still knows the
 * token, so a request's cookie and its token can never drift apart by accident.
 * The identifiers here are 32 hex characters and the column that would hold a
 * token is 64, so doubling one gives a token of exactly the right shape.
 *
 * @param string $id
 * @return string
 */
function mcm_session_token($id)
{
	return substr(str_repeat($id, 4), 0, 64);
}

/**
 * A signed-in session, seeded with a CSRF token of its own.
 *
 * The session key is written out here rather than read from inc/guards.php,
 * because the suite must not load the application to describe it. That the
 * literal is the right one is not taken on trust: 'guard csrf tokens' seeds a
 * session this way and then asks a page what token it sees.
 *
 * @param array  $fixture
 * @param string $id
 * @param array  $data the rest of the session, as mcm_seed_session() takes it
 */
function mcm_seed_signed_in(array $fixture, $id, array $data)
{
	$data['mcm_csrf_token'] = mcm_session_token($id);
	mcm_seed_session($fixture, $id, $data);
}

/**
 * The headers a request from a seeded signed-in session's browser carries: the
 * session cookie, and the session's token in the header js/mc.js sends it in.
 *
 * @param string $id
 * @return array
 */
function mcm_session_headers($id)
{
	return array('Cookie: PHPSESSID=' . $id, 'X-CSRF-Token: ' . mcm_session_token($id));
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
 *
 * @param array $extra settings a case needs on top of these, for something the
 *                     application cannot be told about any other way - the mail
 *                     cases set sendmail_path, which is PHP_INI_SYSTEM and so
 *                     cannot be reached with ini_set() from a page
 */
function mcm_ini_args(array $fixture, array $extra = array())
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
	$ini = $extra + $ini;

	$args = '';
	foreach ($ini as $name => $value) {
		$args .= ' -d ' . escapeshellarg($name . '=' . $value);
	}
	return $args;
}

/**
 * Run one page, named relative to the document root, as a child process.
 *
 * @param array $env     extra environment variables for the page, so a case can
 *                       hand it a value - a stored password hash, a cookie
 *                       issued by an older version of the site - without
 *                       writing it into the fixture.
 * @param array $options binary - a PHP CLI other than the one running the
 *                       suite, which is how the mail cases ask the same
 *                       question of several runtimes; ini - extra ini settings,
 *                       as mcm_ini_args() takes them
 * @return array status, stdout, log
 */
function mcm_cli(array $fixture, $script, array $env = array(), array $options = array())
{
	$options += array('binary' => PHP_BINARY, 'ini' => array());
	file_put_contents($fixture['log'], '');

	$command = escapeshellarg($options['binary']) . mcm_ini_args($fixture, $options['ini']) . ' ' . escapeshellarg($fixture['public'] . '/' . $script);
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

/**
 * Every built-in server this run started and has not stopped, by handle.
 *
 * A server is passed to a case by value, so the array a case holds is a copy
 * and cannot be the thing that is tracked. The handle is what is tracked, and
 * mcm_stop_all_servers() is what an interrupt or an exception reaches: a group
 * that dies between starting a server and stopping it must not leave one
 * holding a port and the fixture it was serving.
 */
$GLOBALS['mcm_servers'] = array();

/**
 * Start PHP's built-in server on a fixture, on a port the system picks.
 *
 * @param array $options binary and ini, as mcm_cli() takes them
 */
function mcm_server_start(array $fixture, array $options = array())
{
	static $handle = 0;

	$options += array('binary' => PHP_BINARY, 'ini' => array());

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
	$command = 'exec ' . escapeshellarg($options['binary']) . mcm_ini_args($fixture, $options['ini'])
		. ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($fixture['public']);

	// The server's own chatter goes to a file rather than a pipe, which nobody
	// would drain. The working directory is the document root.
	$pipes   = array();
	$log     = array('file', $fixture['root'] . '/server.log', 'a');
	$process = proc_open($command, array(1 => $log, 2 => $log), $pipes, $fixture['public']);

	if (!is_resource($process)) {
		throw new RuntimeException('could not start the built-in server');
	}

	$handle++;
	$GLOBALS['mcm_servers'][$handle] = $process;

	$server = array('process' => $process, 'port' => $port, 'host' => '127.0.0.1', 'fixture' => $fixture, 'handle' => $handle);
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

/** Stop a server started by mcm_server_start(). Safe to call more than once. */
function mcm_server_stop(array $server)
{
	if (isset($server['handle'])) {
		unset($GLOBALS['mcm_servers'][$server['handle']]);
	}
	mcm_end_process($server['process']);
}

/**
 * Stop every built-in server this run still has open.
 *
 * Reached from mcm_cleanup(), so a run that ended on an interrupt, an
 * exception or a fatal leaves no server behind. Nothing here can throw: it runs
 * from a shutdown function and a signal handler, where an exception would be
 * the last thing anyone saw.
 */
function mcm_stop_all_servers()
{
	foreach ($GLOBALS['mcm_servers'] as $handle => $process) {
		unset($GLOBALS['mcm_servers'][$handle]);
		mcm_end_process($process);
	}
}

/**
 * End a child process and reap it, without ever waiting forever.
 *
 * proc_close() waits for the child, so calling it on a process that ignored the
 * signal hangs the run rather than failing it - and a hang says nothing about
 * what is wrong. So: ask politely, wait a bounded time, insist with a signal
 * that cannot be declined, and only then reap.
 *
 * @param float $grace how long to wait for the polite signal to be honoured; a
 *                     database server shutting down needs longer than a
 *                     built-in server does
 */
function mcm_end_process($process, $grace = 10.0)
{
	if (!is_resource($process)) {
		return;
	}

	proc_terminate($process);
	if (!mcm_wait_for_exit($process, $grace)) {
		// SIGKILL: the process cannot decline it, so the wait after it is a
		// formality rather than a hope.
		proc_terminate($process, 9);
		mcm_wait_for_exit($process, 5.0);
	}
	proc_close($process);
}

/**
 * Wait up to $seconds for a process to exit.
 *
 * @return bool whether it exited
 */
function mcm_wait_for_exit($process, $seconds)
{
	$deadline = microtime(true) + $seconds;

	do {
		$status = proc_get_status($process);
		if (!is_array($status) || !$status['running']) {
			return true;
		}
		usleep(50000);
	} while (microtime(true) < $deadline);

	return false;
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
 * @param string $body   the request body; a form-encoded content type is added
 *                       unless $headers already set one
 * @return array status, headers, body, log
 */
function mcm_http(array $server, $path, array $headers = array(), $method = 'GET', $body = '')
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
		// A request with a method that may carry a body has to say how long the
		// body is - zero included, or the server waits for one.
		$lines[] = 'Content-Length: ' . strlen($body);
		// A body PHP is meant to parse into $_POST needs a content type too, and
		// a case that sets its own keeps it.
		if ($body !== '' && !mcm_has_header($headers, 'Content-Type')) {
			$lines[] = 'Content-Type: application/x-www-form-urlencoded';
		}
	}
	foreach ($headers as $header) {
		if (stripos($header, 'host:') === 0) {
			$lines[0] = $header;
		} elseif (stripos($header, 'content-type:') === 0) {
			// A caller that names a content type replaces the default one
			// rather than sending a second, which no server would agree on.
			$lines = mcm_replace_header($lines, 'Content-Type', $header);
		} else {
			$lines[] = $header;
		}
	}

	// A body only goes out on a method that announced its length above; a GET
	// with one would be a request no server agrees on how to read.
	$payload = (strtoupper($method) === 'GET' || strtoupper($method) === 'HEAD') ? '' : $body;
	$request = strtoupper($method) . ' ' . $path . " HTTP/1.1\r\n" . implode("\r\n", $lines) . "\r\n";
	fwrite($socket, $request . "\r\n" . $payload);
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

/** Whether a request header line for this name was supplied by the case. */
function mcm_has_header(array $headers, $name)
{
	foreach ($headers as $header) {
		if (stripos($header, $name . ':') === 0) {
			return true;
		}
	}
	return false;
}

/** Make one POST, with the fields form-encoded exactly as a browser or jQuery sends them. */
function mcm_http_post(array $server, $path, array $fields = array(), array $headers = array())
{
	return mcm_http($server, $path, $headers, 'POST', http_build_query($fields));
}

/** Replace a request header line of that name, or append it when there is none. */
function mcm_replace_header(array $lines, $name, $replacement)
{
	foreach ($lines as $index => $line) {
		if (stripos($line, $name . ':') === 0) {
			$lines[$index] = $replacement;
			return $lines;
		}
	}
	$lines[] = $replacement;

	return $lines;
}

/** Build a form-encoded request body from field names and values. */
function mcm_form_body(array $fields)
{
	$parts = array();
	foreach ($fields as $name => $value) {
		$parts[] = urlencode($name) . '=' . urlencode($value);
	}
	return implode('&', $parts);
}

/**
 * Every response header as one "name: value" block.
 *
 * The body is not the only thing a client reads, so a case that asserts private
 * detail did not reach the client has to look here too.
 */
function mcm_header_text(array $response)
{
	$text = '';
	foreach ($response['headers'] as $header) {
		$text .= $header[0] . ': ' . $header[1] . "\n";
	}
	return $text;
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
 * The value a response sets one cookie to, or an empty string.
 *
 * The last Set-Cookie for that name wins, which is what a browser would do.
 */
function mcm_cookie_value(array $response, $name)
{
	$value = '';
	foreach (mcm_header_values($response, 'Set-Cookie') as $header) {
		if (strpos($header, $name . '=') === 0) {
			$value = substr($header, strlen($name) + 1);
			$end   = strpos($value, ';');
			if ($end !== false) {
				$value = substr($value, 0, $end);
			}
		}
	}
	return $value;
}

/**
 * The whole Set-Cookie line a response sets one cookie with, or an empty string.
 *
 * A cookie's attributes cannot be read off the concatenation of every
 * Set-Cookie header the response carries. The session cookie brings HttpOnly,
 * SameSite and, over HTTPS, Secure of its own, so a pattern looking for one of
 * those on a different cookie finds the session cookie's and passes however
 * that other cookie was actually set.
 *
 * The last Set-Cookie for the name wins, which is what a browser would do.
 */
function mcm_cookie_header(array $response, $name)
{
	$found = '';
	foreach (mcm_header_values($response, 'Set-Cookie') as $header) {
		if (strpos($header, $name . '=') === 0) {
			$found = $header;
		}
	}
	return $found;
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


/* Leaving nothing behind ---------------------------------------------------- */

/**
 * Stop everything this run started and remove what it wrote.
 *
 * Registered as a shutdown function below, which covers a normal end, an
 * uncaught exception and a fatal error, and called from the signal handler,
 * which covers the rest. Idempotent, because all three can happen to the same
 * run. Order matters: the optional database server keeps its data directory
 * under the work directory, and removing that from under a running server
 * would leave both the process and its port behind.
 */
function mcm_cleanup()
{
	mcm_stop_all_servers();

	// tests/database.php is optional; the harness is usable without it.
	if (function_exists('mcm_db_stop_server')) {
		mcm_db_stop_server();
	}

	// Never through mcm_work_dir(), which would create the directory it is
	// about to delete on a run that never built a fixture.
	$work = mcm_work_dir_path();
	if ($work !== '') {
		mcm_rmtree($work);
	}
}

/**
 * Catch the signals that would otherwise end the run with a server still up.
 *
 * register_shutdown_function() does not run on a signal: PHP with no handler
 * installed dies at once. An interrupt at the terminal usually reaches the
 * whole process group and takes the children with it, but a signal aimed at
 * this process alone does not, and would leave a built-in server holding a port
 * or a database server holding a data directory that is about to be deleted.
 * pcntl is a command-line extension and may be absent, so this is an
 * improvement where it exists rather than something relied on.
 */
function mcm_catch_signals()
{
	if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
		return;
	}

	pcntl_async_signals(true);
	foreach (array(SIGINT, SIGTERM, SIGHUP) as $signal) {
		pcntl_signal($signal, 'mcm_signal_handler');
	}
}

/** Clean up and end the run, reporting the signal in the exit status. */
function mcm_signal_handler($signal)
{
	fwrite(STDERR, "\ninterrupted: stopping every server this run started\n");
	mcm_cleanup();

	// The conventional status for a process a signal ended.
	exit(128 + (int) $signal);
}

register_shutdown_function('mcm_cleanup');
mcm_catch_signals();
