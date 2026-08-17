<?php

/**
 * The optional real-server database group's machinery.
 *
 * Everything else in this suite needs a PHP CLI and nothing more, and that
 * stays true: this file starts a database server only when a developer has one
 * to start, and reports a loud skip when they do not. Nothing here is required
 * for the rest of the suite to run or to pass.
 *
 * What a real server buys is the three classes of regression that no amount of
 * reading the source can see - see mcm_db_uncovered() for the list, which is
 * also what the skip message prints.
 *
 * The server is private and disposable: its data directory, socket, log, port
 * and credentials are all created for this run, under the same temporary work
 * directory as every other fixture, and are destroyed with it. No system
 * service is started, no system data directory is touched, nothing needs root,
 * and no credential exists before the run or survives it.
 */

/**
 * The regression classes that only a real server can catch, as short lines.
 *
 * The skip message prints these verbatim, so a developer without a database
 * sees exactly what the run did not cover rather than a bare "skipped".
 */
function mcm_db_uncovered()
{
	return array(
		'a call that is present in a method but never reached - the source-level'
			. ' checks count the call and cannot tell whether the path runs',
		'a value stored in a column that is too narrow to hold it - the width is'
			. ' the schema\'s, so only the schema can enforce it',
		'a query whose WHERE clause stops restricting the rows it reads or'
			. ' changes - the SQL still parses and still runs',
	);
}

/* Finding a server ---------------------------------------------------------- */

/**
 * Locate a database server binary and everything derived from it.
 *
 * MCM_TEST_MYSQLD names one explicitly; otherwise the usual names are looked
 * for on PATH and in the directories a system package puts a server in. The
 * base directory is the binary's grandparent, which is what both an unpacked
 * binary tarball (<root>/bin/mariadbd) and a system package (/usr/sbin/mysqld)
 * need passed as --basedir.
 *
 * @return array server, install, family, basedir, version, problem
 */
function mcm_db_binaries()
{
	static $found = null;

	if ($found !== null) {
		return $found;
	}

	$found = array('server' => '', 'install' => '', 'family' => '', 'basedir' => '', 'version' => '', 'problem' => '');

	$configured = getenv('MCM_TEST_MYSQLD');
	if ($configured !== false && trim($configured) !== '') {
		$server = trim($configured);
		if (!is_file($server) || !is_executable($server)) {
			$found['problem'] = 'MCM_TEST_MYSQLD is set to ' . $server . ', which is not an executable file';
			return $found;
		}
	} else {
		$server = mcm_db_search_server();
		if ($server === '') {
			$found['problem'] = 'no mariadbd or mysqld was found on PATH, and MCM_TEST_MYSQLD is not set';
			return $found;
		}
	}

	$server  = realpath($server);
	$basedir = dirname(dirname($server));

	// The family decides how the data directory is created: MariaDB ships a
	// script for it, MySQL does it with a flag on the server itself.
	$version = mcm_db_shell(escapeshellarg($server) . ' --version 2>&1');
	$family  = (stripos($version, 'mariadb') !== false) ? 'mariadb' : 'mysql';

	$install = '';
	if ($family === 'mariadb') {
		foreach (array('scripts/mariadb-install-db', 'scripts/mysql_install_db', 'bin/mariadb-install-db', 'bin/mysql_install_db') as $candidate) {
			if (is_file($basedir . '/' . $candidate) && is_executable($basedir . '/' . $candidate)) {
				$install = $basedir . '/' . $candidate;
				break;
			}
		}
		if ($install === '') {
			$install = mcm_db_which('mariadb-install-db');
		}
		if ($install === '') {
			$install = mcm_db_which('mysql_install_db');
		}
		if ($install === '') {
			$found['problem'] = 'found ' . $server . ' but no mariadb-install-db beside it, so no data directory can be created';
			return $found;
		}
	}

	$found['server']  = $server;
	$found['install'] = $install;
	$found['family']  = $family;
	$found['basedir'] = $basedir;
	// The version banner opens with the binary's full path; the name is enough
	// to say which server this is, and keeps a machine's directory layout out
	// of the suite's output.
	$version = trim(strtok($version, "\n"));
	if (strpos($version, $server) === 0) {
		$version = basename($server) . substr($version, strlen($server));
	}
	$found['version'] = $version;

	return $found;
}

/** Look for a server binary on PATH and in the directories packages use. */
function mcm_db_search_server()
{
	foreach (array('mariadbd', 'mysqld') as $name) {
		$path = mcm_db_which($name);
		if ($path !== '') {
			return $path;
		}
		foreach (array('/usr/sbin', '/usr/local/sbin', '/usr/libexec', '/usr/local/mysql/bin', '/opt/homebrew/bin') as $directory) {
			if (is_file($directory . '/' . $name) && is_executable($directory . '/' . $name)) {
				return $directory . '/' . $name;
			}
		}
	}
	return '';
}

/** The first executable of that name on PATH, or an empty string. */
function mcm_db_which($name)
{
	$path = getenv('PATH');
	if ($path === false) {
		return '';
	}

	foreach (explode(PATH_SEPARATOR, $path) as $directory) {
		if ($directory === '') {
			continue;
		}
		$candidate = rtrim($directory, '/') . '/' . $name;
		if (is_file($candidate) && is_executable($candidate)) {
			return $candidate;
		}
	}
	return '';
}

/** Run a command and return its output; used only for --version probes. */
function mcm_db_shell($command)
{
	$output = @shell_exec($command);
	return is_string($output) ? $output : '';
}

/* Starting and stopping ----------------------------------------------------- */

/**
 * Start the run's private database server, or return null.
 *
 * Called at most once: the handle is remembered, and a failed start is
 * remembered too, so a group that asks twice does not try twice. The reason a
 * start failed is in the returned skip reason, never thrown, because a missing
 * or unusable database must skip this group rather than fail the suite.
 *
 * @return array|null server, port, database, user, password, pdo, ...
 */
function mcm_db_server()
{
	if (array_key_exists('mcm_db_server', $GLOBALS)) {
		return $GLOBALS['mcm_db_server'];
	}
	$GLOBALS['mcm_db_server']      = null;
	$GLOBALS['mcm_db_skip_reason'] = '';

	$binaries = mcm_db_binaries();
	if ($binaries['server'] === '') {
		$GLOBALS['mcm_db_skip_reason'] = $binaries['problem'];
		return null;
	}

	try {
		$GLOBALS['mcm_db_server'] = mcm_db_boot($binaries);
	} catch (Exception $exception) {
		// Whatever went wrong, the server may already be up; the stop path is
		// idempotent and is the only thing that can be trusted to end it.
		mcm_db_stop_server();
		$GLOBALS['mcm_db_server']      = null;
		$GLOBALS['mcm_db_skip_reason'] = $exception->getMessage();
	}
	return $GLOBALS['mcm_db_server'];
}

/** Why the database group is not running. Empty once it is. */
function mcm_db_skip_reason()
{
	mcm_db_server();
	return $GLOBALS['mcm_db_skip_reason'];
}

/**
 * Create a data directory, start a server on it and load the tracked schema.
 *
 * @param array $binaries from mcm_db_binaries()
 * @return array the server handle
 * @throws RuntimeException when any step fails
 */
function mcm_db_boot(array $binaries)
{
	$root = mcm_work_dir() . '/database';
	mcm_mkdir($root . '/data');
	mcm_mkdir($root . '/tmp');

	// Credentials for a server that exists only for this run, generated here so
	// that no committed file holds one. The administrator loads the schema; the
	// application connects as the second account, which can reach nothing but
	// the one database, exactly as a deployed site's account should.
	$suffix   = bin2hex(random_bytes(4));
	$admin    = 'mcm_a_' . $suffix;
	$user     = 'mcm_u_' . $suffix;
	$admin_pw = bin2hex(random_bytes(16));
	$user_pw  = bin2hex(random_bytes(16));

	// The database name is read from the tracked dump rather than repeated
	// here, so the harness follows the schema instead of duplicating it.
	$schema   = MCM_REPO_ROOT . '/.your_database.sql';
	$database = mcm_db_schema_database($schema);
	if ($database === '') {
		throw new RuntimeException('could not read a database name out of ' . $schema);
	}

	mcm_db_initialize($binaries, $root);

	// Each statement has to be on one line: that is all --init-file promises to
	// read. Creating the accounts here rather than over a connection means the
	// server is never reachable without credentials, not even briefly. The
	// generated values are hexadecimal, so nothing in them can end the quoted
	// string they are written into.
	$init = "CREATE USER '" . $admin . "'@'127.0.0.1' IDENTIFIED BY '" . $admin_pw . "';\n"
		. "GRANT ALL PRIVILEGES ON *.* TO '" . $admin . "'@'127.0.0.1' WITH GRANT OPTION;\n"
		. "CREATE USER '" . $user . "'@'127.0.0.1' IDENTIFIED BY '" . $user_pw . "';\n"
		. 'GRANT ALL PRIVILEGES ON `' . $database . "`.* TO '" . $user . "'@'127.0.0.1';\n";
	file_put_contents($root . '/init.sql', $init);
	chmod($root . '/init.sql', 0600);

	$port    = mcm_db_free_port();
	$options = array(
		'--no-defaults',
		'--basedir=' . $binaries['basedir'],
		'--datadir=' . $root . '/data',
		'--tmpdir=' . $root . '/tmp',
		'--port=' . $port,
		'--bind-address=127.0.0.1',
		'--socket=' . $root . '/server.sock',
		'--pid-file=' . $root . '/server.pid',
		'--log-error=' . $root . '/server-error.log',
		'--init-file=' . $root . '/init.sql',
		// Without this the account host has to match a resolved name rather
		// than the address the application connects to.
		'--skip-name-resolve',
		'--innodb-buffer-pool-size=16M',
		'--performance-schema=0',
	);
	if ($binaries['family'] === 'mysql') {
		// A second listener on a fixed port would collide with anything else on
		// the machine, and nothing here speaks the protocol it offers.
		$options[] = '--mysqlx=0';
	}

	// "exec" for the same reason the built-in server needs it, and with worse
	// consequences here: signalling the shell instead of the server leaves a
	// server holding the port and the data directory, and the suite then waits
	// for a shutdown that never comes. A hang is worse than a failure, so the
	// stop path below never trusts a signal to have worked.
	$command = 'exec ' . escapeshellarg($binaries['server']);
	foreach ($options as $option) {
		$command .= ' ' . escapeshellarg($option);
	}

	$pipes  = array();
	$stream = array('file', $root . '/server-stdout.log', 'a');
	$handle = proc_open($command, array(1 => $stream, 2 => $stream), $pipes, $root);
	if (!is_resource($handle)) {
		throw new RuntimeException('could not start ' . $binaries['server']);
	}

	$server = array(
		'process'  => $handle,
		'root'     => $root,
		'port'     => $port,
		'host'     => '127.0.0.1',
		'database' => $database,
		'user'     => $user,
		'password' => $user_pw,
		'admin'    => $admin,
		'family'   => $binaries['family'],
		'version'  => $binaries['version'],
		'log'      => $root . '/server-error.log',
		'pdo'      => null,
		// Loading the tracked schema is the one step here that is not about the
		// developer's machine, so it is reported rather than turned into a skip:
		// a schema this suite cannot load is the repository's problem and has to
		// fail the run.
		'schema_error' => '',
	);
	// Remembered before the first thing that can fail, so the stop path can
	// find the process however the boot ends.
	$GLOBALS['mcm_db_server'] = $server;
	register_shutdown_function('mcm_db_stop_server');
	mcm_db_catch_signals();

	mcm_db_wait_for_port($server);

	// The administrator connects with no database selected: the schema creates
	// its own, and the account for the application only exists inside it.
	$server['pdo'] = mcm_db_pdo($server, $admin, $admin_pw, '');
	$GLOBALS['mcm_db_server'] = $server;

	try {
		mcm_db_load_schema($server['pdo'], $schema);
	} catch (Exception $exception) {
		$server['schema_error'] = $exception->getMessage();
	}
	$GLOBALS['mcm_db_server'] = $server;

	return $server;
}

/** Create the data directory, the one step whose shape differs by family. */
function mcm_db_initialize(array $binaries, $root)
{
	if ($binaries['family'] === 'mariadb') {
		$command = escapeshellarg($binaries['install'])
			. ' ' . escapeshellarg('--basedir=' . $binaries['basedir'])
			. ' ' . escapeshellarg('--datadir=' . $root . '/data');
	} else {
		// Not exercised: no MySQL server was available where this was written,
		// only MariaDB. The flags are the documented ones, and a failure here
		// skips the group with the server's own message rather than failing the
		// suite, so a developer on MySQL sees what to fix.
		$command = escapeshellarg($binaries['server']) . ' --no-defaults --initialize-insecure'
			. ' ' . escapeshellarg('--basedir=' . $binaries['basedir'])
			. ' ' . escapeshellarg('--datadir=' . $root . '/data');
	}

	$output = $root . '/install.log';
	$pipes  = array();
	$stream = array('file', $output, 'a');
	$handle = proc_open($command, array(1 => $stream, 2 => $stream), $pipes, $root);
	$status = is_resource($handle) ? proc_close($handle) : -1;

	if ($status !== 0) {
		$detail = is_file($output) ? trim(substr(file_get_contents($output), -600)) : '';
		throw new RuntimeException('could not create a data directory (status ' . $status . '): ' . $detail);
	}
}

/**
 * Wait for the server to accept connections.
 *
 * A server that never comes up must fail here rather than hang: the loop is
 * bounded, and a process that has already exited is noticed straight away
 * instead of being waited out.
 */
function mcm_db_wait_for_port(array $server)
{
	for ($attempt = 0; $attempt < 600; $attempt++) {
		$probe = @fsockopen($server['host'], $server['port'], $errno, $error, 0.5);
		if ($probe) {
			fclose($probe);
			return;
		}

		$status = proc_get_status($server['process']);
		if (is_array($status) && !$status['running']) {
			throw new RuntimeException('the database server exited during start-up: ' . mcm_db_error_tail($server));
		}
		usleep(50000);
	}

	throw new RuntimeException('the database server did not accept connections on port ' . $server['port'] . ': ' . mcm_db_error_tail($server));
}

/** The end of the server's own error log, for a start-up failure message. */
function mcm_db_error_tail(array $server)
{
	if (!is_file($server['log'])) {
		return 'no server log was written';
	}
	return trim(substr(file_get_contents($server['log']), -600));
}

/**
 * Stop the server when the run itself is interrupted, where that is possible.
 *
 * register_shutdown_function() covers a normal end, an uncaught exception and a
 * fatal error, but not a signal: PHP with no handler installed dies at once and
 * runs nothing. An interrupt at the terminal usually reaches the whole process
 * group and takes the server with it, but a signal aimed at this process alone
 * does not, and the server would be left holding a data directory that is about
 * to be deleted. pcntl is a command-line extension and may be absent, so this
 * is an improvement where it exists rather than something relied on.
 */
function mcm_db_catch_signals()
{
	if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
		return;
	}

	pcntl_async_signals(true);
	foreach (array(SIGINT, SIGTERM, SIGHUP) as $signal) {
		pcntl_signal($signal, 'mcm_db_signal_handler');
	}
}

/** Stop the server and end the run, reporting the signal in the exit status. */
function mcm_db_signal_handler($signal)
{
	echo "\ninterrupted: stopping the database server\n";
	mcm_db_stop_server();

	// The conventional status for a process a signal ended.
	exit(128 + (int) $signal);
}

/**
 * Stop the run's server, and be certain about it.
 *
 * Reliability matters more here than anywhere else in the suite. proc_close()
 * waits for the child, so calling it on a server that ignored the signal hangs
 * the run rather than failing it, and a hang says nothing about what is wrong.
 * So: ask politely, wait a bounded time, insist, wait again, and only then
 * reap. Safe to call repeatedly, and registered as a shutdown function so that
 * an exception anywhere in the group still ends the server.
 */
function mcm_db_stop_server()
{
	if (empty($GLOBALS['mcm_db_server']) || !is_array($GLOBALS['mcm_db_server'])) {
		return;
	}

	$server = $GLOBALS['mcm_db_server'];
	// Cleared first: a failure below must not leave a handle that a later call
	// would signal again.
	$GLOBALS['mcm_db_server'] = null;

	// The connection holds a socket the server waits on while shutting down.
	$server['pdo'] = null;
	unset($server['pdo']);

	if (!isset($server['process']) || !is_resource($server['process'])) {
		return;
	}
	$process = $server['process'];

	proc_terminate($process);
	if (!mcm_db_wait_for_exit($process, 20.0)) {
		// SIGKILL: the process cannot decline it, so the wait after it is a
		// formality rather than a hope.
		proc_terminate($process, 9);
		mcm_db_wait_for_exit($process, 5.0);
	}

	proc_close($process);

	// A data directory is a hundred megabytes or so, and a run that ends on an
	// interrupt never reaches the work directory's own clean-up at the end of
	// tests/run.php. Removing this much here costs nothing on a normal run.
	if (isset($server['root']) && $server['root'] !== '') {
		mcm_rmtree($server['root']);
	}
}

/**
 * Wait up to $seconds for a process to exit.
 *
 * @return bool whether it exited
 */
function mcm_db_wait_for_exit($process, $seconds)
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

/** Reserve a port the system is not using, the way mcm_server_start() does. */
function mcm_db_free_port()
{
	$socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
	if (!$socket) {
		throw new RuntimeException('could not reserve a port for the database server: ' . $error);
	}
	$name = stream_socket_get_name($socket, false);
	$port = (int) substr($name, strrpos($name, ':') + 1);
	fclose($socket);

	return $port;
}

/* Talking to it ------------------------------------------------------------- */

/**
 * Open a connection to the run's server.
 *
 * The DSN is built the same way inc/bootstrap.php builds its own, so what the
 * harness talks to is what the application talks to.
 */
function mcm_db_pdo(array $server, $user, $password, $database)
{
	$dsn = 'mysql:host=' . $server['host'] . ';port=' . $server['port'];
	if ($database !== '') {
		$dsn .= ';dbname=' . $database;
	}

	$pdo = new PDO($dsn, $user, $password);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	return $pdo;
}

/**
 * The value DB_HOST has to carry for the application to reach this server.
 *
 * inc/bootstrap.php puts DB_HOST into the DSN verbatim, so a port travels in it
 * as a second DSN field. That is the whole reason this group needs no change to
 * any application file: the existing configuration already carries everything a
 * connection needs.
 */
function mcm_db_host_setting(array $server)
{
	return $server['host'] . ';port=' . $server['port'];
}

/**
 * The database name the tracked schema creates.
 *
 * @param string $file path to .your_database.sql
 * @return string
 */
function mcm_db_schema_database($file)
{
	if (!is_readable($file)) {
		return '';
	}
	if (preg_match('/CREATE\s+DATABASE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_$]+)`?/i', file_get_contents($file), $match)) {
		return $match[1];
	}
	return '';
}

/**
 * Split a dump into statements.
 *
 * Deliberately small: it understands comment lines and the DELIMITER command,
 * which is all the tracked dump uses, and nothing else. DELIMITER is a client
 * instruction rather than SQL, so a server never sees it; without handling it
 * the stored procedure's body would be cut at its first internal semicolon.
 *
 * @param string $sql
 * @return array statements, in order
 */
function mcm_sql_statements($sql)
{
	$statements = array();
	$delimiter  = ';';
	$buffer     = '';

	foreach (preg_split("/\r\n|\n|\r/", $sql) as $line) {
		$trimmed = trim($line);
		if ($trimmed === '' || substr($trimmed, 0, 2) === '--') {
			continue;
		}
		if (stripos($trimmed, 'DELIMITER ') === 0) {
			$delimiter = trim(substr($trimmed, 10));
			continue;
		}

		$buffer .= $line . "\n";
		$ends    = rtrim($buffer);
		if (substr($ends, -strlen($delimiter)) === $delimiter) {
			$statement = trim(substr($ends, 0, -strlen($delimiter)));
			if ($statement !== '') {
				$statements[] = $statement;
			}
			$buffer = '';
		}
	}

	if (trim($buffer) !== '') {
		$statements[] = trim($buffer);
	}
	return $statements;
}

/**
 * Load the tracked schema, statement by statement.
 *
 * Two things about that file decide whether it loads at all, and both are the
 * site's rather than the harness's:
 *
 *   - Every table is ENGINE=MyISAM, so nothing here is transactional. A case
 *     cannot roll a change back; it re-seeds instead, which is what
 *     mcm_db_reset() is for.
 *   - users.user_registration_datetime defaults to '0000-00-00 00:00:00'. A
 *     server whose sql_mode contains NO_ZERO_DATE refuses that column, which
 *     MySQL 5.7 and later have on by default and MariaDB does not. The dump
 *     opens with its own SET SQL_MODE line, and running that line as part of
 *     the load is what makes the rest of it loadable; the application's
 *     connections are untouched by it and run on whatever the server's default
 *     sql_mode is, which on a current server includes STRICT_TRANS_TABLES.
 *
 * @param PDO    $pdo  an administrator connection with no database selected
 * @param string $file path to .your_database.sql
 */
function mcm_db_load_schema($pdo, $file)
{
	$sql = file_get_contents($file);
	if ($sql === false) {
		throw new RuntimeException('could not read ' . $file);
	}

	foreach (mcm_sql_statements($sql) as $statement) {
		try {
			$pdo->exec($statement);
		} catch (PDOException $exception) {
			$short = substr(preg_replace('/\s+/', ' ', $statement), 0, 120);
			throw new RuntimeException('the tracked schema would not load: ' . $exception->getMessage() . ' [' . $short . ']');
		}
	}
}

/* What a case works with ---------------------------------------------------- */

/**
 * A fixture whose configuration points at the run's own database server.
 *
 * Nothing about the application changes: DB_HOST, DB_NAME, DB_USER and DB_PASS
 * are ordinary configuration values, and the credentials in them were generated
 * minutes ago for a server that will not outlive the run.
 */
function mcm_db_fixture($name, array $server, array $config = array())
{
	$config += array(
		'DB_HOST' => mcm_db_host_setting($server),
		'DB_NAME' => $server['database'],
		'DB_USER' => $server['user'],
		'DB_PASS' => $server['password'],
	);

	return mcm_fixture($name, array('config' => $config));
}

/** An application-level connection, as the site's own account. */
function mcm_db_app_pdo(array $server)
{
	return mcm_db_pdo($server, $server['user'], $server['password'], $server['database']);
}

/**
 * Empty the users table and hand back a connection to the application database.
 *
 * TRUNCATE rather than DELETE so that user ids start from 1 again and a case
 * can name the id it seeded.
 */
function mcm_db_reset(array $server)
{
	$pdo = mcm_db_app_pdo($server);
	$pdo->exec('TRUNCATE TABLE users');

	return $pdo;
}

/**
 * Insert one user, the way a row that predates this suite would look.
 *
 * The password hash is calculated here rather than through inc/security.php, so
 * a case is checking the application against a hash it did not produce. The
 * cost is deliberately the cheapest bcrypt accepts: these hashes exist to be
 * verified quickly, and a stored hash weaker than the configured cost is also
 * what makes the login path's opportunistic rehash observable.
 *
 * @param array $fields columns to override, e.g. user_active
 * @return int the new user's id
 */
function mcm_db_seed_user($pdo, $name, $password, array $fields = array())
{
	$fields += array(
		'user_name'                     => $name,
		'user_password_hash'            => password_hash($password, PASSWORD_DEFAULT, array('cost' => 4)),
		'user_email'                    => $name . '@example.test',
		'user_active'                   => 1,
		'user_activation_hash'          => null,
		'user_password_reset_hash'      => null,
		'user_password_reset_timestamp' => null,
		'user_rememberme_token'         => null,
		'user_registration_ip'          => '127.0.0.1',
	);

	$columns      = array_keys($fields);
	$placeholders = array();
	foreach ($columns as $column) {
		$placeholders[] = ':' . $column;
	}

	$statement = $pdo->prepare(
		'INSERT INTO users (' . implode(', ', $columns) . ', user_registration_datetime)'
		. ' VALUES (' . implode(', ', $placeholders) . ', now())'
	);
	foreach ($fields as $column => $value) {
		$statement->bindValue(':' . $column, $value);
	}
	$statement->execute();

	return (int) $pdo->lastInsertId();
}

/** One user row as an array, or an empty array when there is none. */
function mcm_db_user_row($pdo, $name)
{
	$statement = $pdo->prepare('SELECT * FROM users WHERE user_name = :user_name');
	$statement->bindValue(':user_name', $name);
	$statement->execute();
	$row = $statement->fetch(PDO::FETCH_ASSOC);

	return is_array($row) ? $row : array();
}

/* The skip ------------------------------------------------------------------ */

/**
 * Say, at length, that the database group did not run and what that costs.
 *
 * A skipped group that prints one grey line is a group nobody notices is
 * missing, and the whole point of this one is the coverage that is not there
 * without it. So the reason, the uncovered classes and the way to fix it all go
 * to the screen.
 */
function mcm_db_print_skip($reason)
{
	$rule = '  ' . str_repeat('-', 72) . "\n";

	echo "\n" . $rule;
	echo "  SKIPPED: the optional real-database group did not run.\n";
	echo '  Reason: ' . $reason . "\n\n";
	echo "  Not covered by this run:\n";
	foreach (mcm_db_uncovered() as $index => $line) {
		echo '    ' . ($index + 1) . '. ' . wordwrap($line, 66, "\n       ") . "\n";
	}
	echo "\n";
	echo "  To cover them, unpack a MariaDB or MySQL binary tarball anywhere and\n";
	echo "  point the suite at the server binary inside it:\n\n";
	echo "      MCM_TEST_MYSQLD=/path/to/unpacked/bin/mariadbd php tests/run.php\n\n";
	echo "  Nothing is installed and no service is started: the harness creates a\n";
	echo "  private data directory, port and credentials under the system temp\n";
	echo "  directory and destroys them when the run ends. See README.md.\n";
	echo $rule . "\n";
}
