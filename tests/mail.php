<?php

/**
 * The mail group's machinery: a local stand-in for the far end of a send, and
 * the list of runtimes the same question is asked of.
 *
 * Nothing here contacts a mail service, and nothing here holds a credential. A
 * send is driven against tests/pages/smtp_stub.php on the loopback interface,
 * or against tests/pages/sendmail_stub.php through sendmail_path, and what
 * "sending" did is read off the file that stand-in wrote. The credentials in a
 * fixture's configuration are placeholders generated for the run.
 *
 * The other half is the matrix. What the mail path does is decided by the
 * runtime it runs on rather than by the site, so one runtime is one data point.
 * MCM_TEST_PHP names further PHP CLI binaries - the same seam MCM_TEST_MYSQLD
 * gives the database group - and the mail cases ask each of them the same
 * question. With none set, the check still runs, on the runtime running the
 * suite, and says loudly what a single data point cannot show.
 */

/**
 * What a run with only one runtime does not cover.
 *
 * Printed verbatim by the notice, so a developer sees what was not answered
 * rather than a bare "one runtime".
 */
function mcm_mail_uncovered()
{
	return array(
		'whether the mail path behaves the same on the target runtime as on the'
			. ' one that happens to be running the suite - the send path calls'
			. ' functions that some runtimes have and some do not, so this is'
			. ' exactly the kind of difference one runtime cannot show',
		'whether a runtime the project also exercises for forward compatibility'
			. ' has moved further still',
	);
}

/* The runtimes ------------------------------------------------------------- */

/**
 * The PHP runtimes the mail cases run against.
 *
 * Always includes the one running the suite. MCM_TEST_PHP adds others, as a
 * list separated by the path separator; each is asked for its own version
 * rather than being labelled from its file name, and one that cannot be run is
 * reported rather than silently dropped.
 *
 * @return array of binary, version, label, problem
 */
function mcm_mail_runtimes()
{
	static $runtimes = null;

	if ($runtimes !== null) {
		return $runtimes;
	}

	$runtimes = array(mcm_mail_runtime(PHP_BINARY));
	$seen     = array(mcm_mail_runtime_key(PHP_BINARY) => true);

	$configured = getenv('MCM_TEST_PHP');
	if ($configured === false || trim($configured) === '') {
		return $runtimes;
	}

	foreach (explode(PATH_SEPARATOR, $configured) as $binary) {
		$binary = trim($binary);
		if ($binary === '') {
			continue;
		}
		$key = mcm_mail_runtime_key($binary);
		if (isset($seen[$key])) {
			continue;
		}
		$seen[$key]  = true;
		$runtimes[] = mcm_mail_runtime($binary);
	}

	return $runtimes;
}

/** One runtime, described by asking it rather than by reading its name. */
function mcm_mail_runtime($binary)
{
	$runtime = array('binary' => $binary, 'version' => '', 'label' => '', 'problem' => '');

	// The label never carries the path, only the name: it goes into case names
	// and the suite's output, and a developer's directory layout is not part of
	// a result.
	$runtime['label'] = basename($binary);

	if (!is_file($binary) || !is_executable($binary)) {
		$runtime['problem'] = 'not an executable file';
		return $runtime;
	}

	$pipes   = array();
	$process = proc_open(
		escapeshellarg($binary) . ' -r ' . escapeshellarg('echo PHP_VERSION;'),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes
	);
	if (!is_resource($process)) {
		$runtime['problem'] = 'could not be run';
		return $runtime;
	}
	$version = trim((string) stream_get_contents($pipes[1]));
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	if ($version === '') {
		$runtime['problem'] = 'did not report a version';
		return $runtime;
	}

	$runtime['version'] = $version;
	$runtime['label']   = 'PHP ' . $version;

	return $runtime;
}

/** How two names for the same binary are recognised as one runtime. */
function mcm_mail_runtime_key($binary)
{
	$real = realpath($binary);

	return ($real === false) ? $binary : $real;
}

/** The runtimes that can actually be run, in the order they were named. */
function mcm_mail_usable_runtimes()
{
	$usable = array();
	foreach (mcm_mail_runtimes() as $runtime) {
		if ($runtime['problem'] === '') {
			$usable[] = $runtime;
		}
	}

	return $usable;
}

/**
 * Say, at length, that the mail check ran on one runtime only.
 *
 * The group itself still ran, so this is not a skipped group; what is missing
 * is the comparison between runtimes, and that is exactly what this check
 * exists to make. A grey line nobody reads would hide it.
 */
function mcm_mail_print_matrix_notice(array $runtimes)
{
	$rule = '  ' . str_repeat('-', 72) . "\n";

	echo "\n" . $rule;
	echo "  ONE RUNTIME ONLY: the mail check ran on " . $runtimes[0]['label'] . " and nothing else.\n\n";
	echo "  Not answered by this run:\n";
	foreach (mcm_mail_uncovered() as $index => $line) {
		echo '    ' . ($index + 1) . '. ' . wordwrap($line, 66, "\n       ") . "\n";
	}
	echo "\n";
	echo "  To answer them, point the suite at the other PHP CLI binaries you have,\n";
	echo "  separated the way PATH separates directories:\n\n";
	echo "      MCM_TEST_PHP=/path/to/php8.1" . PATH_SEPARATOR . "/path/to/php8.4 php tests/run.php\n\n";
	echo "  Nothing is installed: each one is run as a child process against the\n";
	echo "  same throw-away fixture. See README.md.\n";
	echo $rule . "\n";
}

/* The stand-in ------------------------------------------------------------- */

/**
 * Start the SMTP stand-in on a port the system picks.
 *
 * @param array $fixture the fixture whose document root holds the stand-in and
 *                       will hold its transcript
 * @param array $options reject - none, auth or data, as the stand-in takes it
 * @return array process, port, transcript, fixture
 */
function mcm_mail_stub_start(array $fixture, array $options = array())
{
	$options += array('reject' => 'none', 'abandoned' => 'wait', 'name' => 'smtp');

	$socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
	if (!$socket) {
		throw new RuntimeException('could not reserve a port for the mail stand-in: ' . $error);
	}
	$name = stream_socket_get_name($socket, false);
	$port = (int) substr($name, strrpos($name, ':') + 1);
	fclose($socket);

	$transcript = $fixture['root'] . '/' . $options['name'] . '-transcript.log';
	@unlink($transcript);

	// "exec" for the reason the built-in server needs it: without it the shell
	// proc_open spawns is what gets signalled, and the stand-in survives as an
	// orphan holding the port.
	$command = 'exec ' . escapeshellarg(PHP_BINARY)
		. ' ' . escapeshellarg($fixture['public'] . '/smtp_stub.php')
		. ' ' . escapeshellarg('--port=' . $port)
		. ' ' . escapeshellarg('--transcript=' . $transcript)
		. ' ' . escapeshellarg('--reject=' . $options['reject'])
		. ' ' . escapeshellarg('--abandoned=' . $options['abandoned']);

	$pipes   = array();
	$log     = array('file', $fixture['root'] . '/mail-stub.log', 'a');
	$process = proc_open($command, array(1 => $log, 2 => $log), $pipes, $fixture['public']);

	if (!is_resource($process)) {
		throw new RuntimeException('could not start the mail stand-in');
	}

	$stub = array('process' => $process, 'port' => $port, 'transcript' => $transcript, 'fixture' => $fixture);

	// Without this poll the first send races the stand-in, and a send that
	// arrived too early would look like a refused connection. It waits for the
	// stand-in to say it is listening rather than connecting to find out: a
	// connection made by the suite would sit in the transcript looking like
	// something the send did.
	for ($attempt = 0; $attempt < 200; $attempt++) {
		foreach (mcm_mail_transcript($stub) as $line) {
			if (strpos($line, 'listening ') === 0) {
				return $stub;
			}
		}
		usleep(25000);
	}

	mcm_mail_stub_stop($stub);
	throw new RuntimeException('the mail stand-in did not come up on port ' . $port);
}

/**
 * Stop the stand-in, and be certain about it.
 *
 * Bounded like the database server's stop path and for the same reason: it sits
 * blocked on a socket, and proc_close() on a process that ignored the signal
 * would hang the run rather than fail it.
 */
function mcm_mail_stub_stop(array $stub)
{
	if (!isset($stub['process']) || !is_resource($stub['process'])) {
		return;
	}

	proc_terminate($stub['process']);
	if (!mcm_wait_for_exit($stub['process'], 5.0)) {
		proc_terminate($stub['process'], 9);
		mcm_wait_for_exit($stub['process'], 5.0);
	}
	proc_close($stub['process']);
}

/** Forget everything the stand-in has recorded so far. */
function mcm_mail_stub_reset(array $stub)
{
	@unlink($stub['transcript']);
}

/** The stand-in's transcript, one entry per line, in the order they happened. */
function mcm_mail_transcript(array $stub)
{
	if (!is_file($stub['transcript'])) {
		return array();
	}

	$lines = array();
	foreach (explode("\n", file_get_contents($stub['transcript'])) as $line) {
		if (rtrim($line) !== '') {
			$lines[] = rtrim($line, "\r\n");
		}
	}

	return $lines;
}

/**
 * The command verbs the stand-in was sent, upper-cased, in order.
 *
 * The verb alone rather than the whole line, so a case can say "it got as far as
 * DATA" without quoting an address or a credential into an assertion.
 *
 * @return array
 */
function mcm_mail_commands(array $stub)
{
	$verbs = array();
	foreach (mcm_mail_transcript($stub) as $line) {
		if (strpos($line, 'command ') === 0) {
			$command = substr($line, 8);
			$space   = strpos($command, ' ');
			$verbs[] = strtoupper($space === false ? $command : substr($command, 0, $space));
		}
	}

	return $verbs;
}

/**
 * The messages the stand-in accepted in full, as their body lines joined.
 *
 * Only a message the client terminated counts. That is the difference the whole
 * group turns on: a client that dies inside DATA has already sent MAIL FROM and
 * RCPT TO, so the commands say a send was attempted and only this says one
 * arrived.
 *
 * @return array
 */
function mcm_mail_messages(array $stub)
{
	$messages = array();
	$current  = null;

	foreach (mcm_mail_transcript($stub) as $line) {
		if ($line === 'data-begin') {
			$current = array();
		} elseif (strpos($line, 'body ') === 0 && $current !== null) {
			$current[] = substr($line, 5);
		} elseif (strpos($line, 'data-end') === 0 && $current !== null) {
			$messages[] = implode("\n", $current);
			$current    = null;
		} elseif (strpos($line, 'data-abort') === 0) {
			$current = null;
		}
	}

	return $messages;
}

/** How many messages the client began and then abandoned. */
function mcm_mail_aborted(array $stub)
{
	$aborted = 0;
	foreach (mcm_mail_transcript($stub) as $line) {
		if (strpos($line, 'data-abort') === 0) {
			$aborted++;
		}
	}

	return $aborted;
}

/* Configuring a fixture ---------------------------------------------------- */

/**
 * The mail settings a real deployment defines, pointed at the stand-in.
 *
 * The shape is inc/config/example_config.php's, SMTP and all, because SMTP is
 * what that file recommends and what a deployment following it would run. Only
 * the host and port differ from a real one, and the credential is a placeholder
 * this run generated.
 *
 * @param array|null $stub      the SMTP stand-in, or null for the mail() path
 * @param array      $overrides settings a case wants to differ
 * @return array defines for mcm_config_php()
 */
function mcm_mail_config($stub, array $overrides = array())
{
	static $password = null;
	if ($password === null) {
		// Generated rather than written down, so no committed file holds
		// anything shaped like a mail credential.
		$password = 'mail-placeholder-' . bin2hex(random_bytes(8));
	}

	$config = array(
		'EMAIL_USE_SMTP'       => $stub !== null,
		'EMAIL_SMTP_AUTH'      => true,
		'EMAIL_SMTP_USERNAME'  => 'noreply@example.test',
		'EMAIL_SMTP_PASSWORD'  => $password,
		'EMAIL_SMTP_HOST'      => '127.0.0.1',
		'EMAIL_SMTP_PORT'      => ($stub === null) ? 25 : $stub['port'],
		// Deliberately empty. Both callers ask defined(EMAIL_SMTP_ENCRYPTION) -
		// the constant's value rather than its name - so no value here would
		// ever set SMTPSecure. Empty is the one value for which that call means
		// what it looks like it means.
		'EMAIL_SMTP_ENCRYPTION' => '',

		'EMAIL_VERIFICATION_URL'       => 'http://example.test/register.php',
		'EMAIL_VERIFICATION_FROM'      => 'noreply@example.test',
		'EMAIL_VERIFICATION_FROM_NAME' => 'Movie Collection Manager',
		'EMAIL_VERIFICATION_SUBJECT'   => 'Account Activation for Movie Collection Manager',
		'EMAIL_VERIFICATION_CONTENT'   => 'Please click on this link to activate your account:',

		'EMAIL_PASSWORDRESET_URL'       => 'http://example.test/password_reset.php',
		'EMAIL_PASSWORDRESET_FROM'      => 'noreply@example.test',
		'EMAIL_PASSWORDRESET_FROM_NAME' => 'Movie Collection Manager',
		'EMAIL_PASSWORDRESET_SUBJECT'   => 'Password reset for Movie Collection Manager',
		'EMAIL_PASSWORDRESET_CONTENT'   => 'Please click on this link to reset your password:',
	);

	return $overrides + $config;
}

/** The placeholder credential a fixture built by mcm_mail_config() sends. */
function mcm_mail_password()
{
	$config = mcm_mail_config(null);

	return $config['EMAIL_SMTP_PASSWORD'];
}

/**
 * The ini settings that point mail() at the local mailbox stand-in.
 *
 * sendmail_path is PHP_INI_SYSTEM, so a page cannot set it and a fixture has to
 * be started with it. Without this, a fixture that turned SMTP off would hand a
 * message to whatever agent the machine happens to have.
 *
 * @param string $binary the PHP CLI that will run the stand-in
 */
function mcm_mail_sendmail_ini(array $fixture, $binary = null)
{
	$binary = ($binary === null) ? PHP_BINARY : $binary;

	return array('sendmail_path' => escapeshellarg($binary)
		. ' ' . escapeshellarg($fixture['public'] . '/sendmail_stub.php')
		. ' ' . escapeshellarg('--mailbox=' . mcm_mail_mailbox($fixture)));
}

/** Where the mail() stand-in writes what it was given. */
function mcm_mail_mailbox(array $fixture)
{
	return $fixture['root'] . '/mailbox.log';
}

/** The messages the mail() stand-in has been handed, in order. */
function mcm_mail_mailbox_messages(array $fixture)
{
	$path = mcm_mail_mailbox($fixture);
	if (!is_file($path)) {
		return array();
	}

	$messages = array();
	$current  = null;
	foreach (explode("\n", file_get_contents($path)) as $line) {
		if (rtrim($line, "\r") === 'message-begin') {
			$current = array();
		} elseif (rtrim($line, "\r") === 'message-end' && $current !== null) {
			$messages[] = implode("\n", $current);
			$current    = null;
		} elseif ($current !== null) {
			$current[] = rtrim($line, "\r");
		}
	}

	return $messages;
}

/* Driving a send ----------------------------------------------------------- */

/**
 * Point a fixture's configuration at a stand-in.
 *
 * The fixture has to exist before the stand-in can be started - the stand-in is
 * a file in its document root and writes beside it - and the stand-in has to be
 * running before the configuration can name its port, so the configuration is
 * written last, exactly as the TMDb cases do it.
 *
 * @param array|null $stub the SMTP stand-in, or null for the mail() path
 */
function mcm_mail_configure(array $fixture, $stub, array $overrides = array())
{
	file_put_contents(
		$fixture['public'] . '/inc/config/config.php',
		mcm_config_php(mcm_mail_config($stub, $overrides))
	);
}

/**
 * Point a fixture at both the run's database server and a mail stand-in.
 *
 * The database group's fixtures name the server in their configuration; the
 * mail cases that need rows need both halves in the same file.
 */
function mcm_mail_db_configure(array $fixture, array $server, $stub, array $overrides = array())
{
	$overrides += array(
		'DB_HOST' => mcm_db_host_setting($server),
		'DB_NAME' => $server['database'],
		'DB_USER' => $server['user'],
		'DB_PASS' => $server['password'],
	);

	mcm_mail_configure($fixture, $stub, $overrides);
}

/**
 * Drive one of the site's mail paths once, under one runtime.
 *
 * @param array $runtime from mcm_mail_runtimes()
 * @param array $env     what the probe page reads: MCM_MAIL_PATH and the values
 *                       the link is built from
 * @param array $ini     extra ini settings, for the mail() path's sendmail_path
 * @return array status, stdout, log, report
 */
function mcm_mail_drive(array $fixture, array $runtime, array $env = array(), array $ini = array())
{
	$env += array(
		'MCM_MAIL_PATH' => 'registration',
		'MCM_MAIL_TO'   => 'recipient@example.test',
		'MCM_MAIL_USER' => '7',
	);

	$result           = mcm_cli($fixture, 'mail_send.php', $env, array('binary' => $runtime['binary'], 'ini' => $ini));
	$result['report'] = mcm_report($result['stdout']);

	return $result;
}

/**
 * A token to look for in what the stand-in received.
 *
 * Generated per call, so "the message that arrived is the message this send
 * built" is a claim about one send rather than about the shape of a link.
 */
function mcm_mail_token()
{
	return 'tok' . bin2hex(random_bytes(8));
}

/* The counterfactual ------------------------------------------------------- */

/**
 * Rewrite the two each() loops in a fixture's copy of the SMTP library.
 *
 * This is the smallest change that could make the difference: the same fixture,
 * the same runtime, the same stand-in, with each() replaced by the foreach it
 * is standing in for and nothing else touched. A send that then completes says
 * those two lines are the whole cause rather than something else on the path.
 *
 * The checkout is never touched - this rewrites a throw-away copy - and the
 * count is returned rather than assumed, so a case fails loudly if the library
 * ever stops looking like this instead of quietly proving nothing.
 *
 * @return int how many loops were rewritten
 */
function mcm_mail_rewrite_each(array $fixture)
{
	$path   = $fixture['public'] . '/inc/libs/class.smtp.php';
	$source = file_get_contents($path);
	if ($source === false) {
		throw new RuntimeException('could not read ' . $path);
	}

	$replacements = array(
		'while(list(, $line) = @each($lines)) {'         => 'foreach($lines as $line) {',
		'while(list(, $line_out) = @each($lines_out)) {' => 'foreach($lines_out as $line_out) {',
	);

	$rewritten = 0;
	foreach ($replacements as $from => $to) {
		$count  = 0;
		$source = str_replace($from, $to, $source, $count);
		$rewritten += $count;
	}

	file_put_contents($path, $source);

	return $rewritten;
}
