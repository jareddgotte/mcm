<?php

/**
 * A stand-in for an SMTP server, spoken to over the loopback interface.
 *
 * This is not part of the application and deliberately does not load the
 * bootstrap: it plays the far end of a mail send, not this site. The suite
 * points EMAIL_SMTP_HOST and EMAIL_SMTP_PORT at it, so the real send path -
 * PHPMailer, inc/libs/class.smtp.php, the same commands in the same order - runs
 * against a real socket without a mail service, a real credential, or a message
 * that leaves this machine. It binds 127.0.0.1 and nothing else, so there is no
 * address on it that anything off this machine could reach.
 *
 *     php smtp_stub.php --port=<port> --transcript=<file>
 *         [--reject=none|auth|data] [--abandoned=wait|close]
 *
 * --reject decides where a send is refused, which is how a case tells the two
 * failures apart:
 *
 *   none   every command is accepted, so a send only fails if the client cannot
 *          finish one
 *   auth   AUTH is answered 535, so the client fails before it has a message to
 *          send
 *   data   DATA is answered 554, so the client is refused at exactly the step
 *          that a runtime without each() dies on - the same step, refused
 *          politely instead
 *
 * --abandoned decides how patient this is with a client that stopped sending a
 * message it had started. A client whose send died mid-message still runs its
 * own clean-up, which sends "quit" - and a server in the middle of receiving a
 * message can only read that as one more line of the message, so it answers
 * nothing and the client waits out its own timeout:
 *
 *   wait   what a patient server does, and what makes that wait - ten seconds
 *          of it, on the client's own clock - visible to a case
 *   close  the connection is dropped as soon as that parting line arrives, so a
 *          case that has already established the wait need not pay it again.
 *          Only the waiting changes; the send has already failed either way
 *
 * Everything the client says is appended to the transcript, one line each, and
 * that file is the only thing this writes:
 *
 *   listening <port>     the port is bound and a client may connect
 *   connect              a client opened a connection
 *   command <line>       one command line, exactly as it arrived
 *   data-begin           DATA was accepted and the message is arriving
 *   body <line>          one line of the message, un-dot-stuffed
 *   data-end <bytes>     the message was terminated with "." and accepted
 *   data-abort <bytes>   the client vanished mid-message; nothing was accepted
 *   disconnect quit|eof  how the connection ended
 *
 * data-end and data-abort are the whole point: a client that dies inside DATA
 * has already sent MAIL FROM and RCPT TO, so counting commands says a send
 * happened. Only the terminator says a message was delivered.
 *
 * The transcript holds whatever credential the fixture configured, because the
 * client sends it and this records what the client sent. That is a placeholder
 * generated for one run, in a temporary directory destroyed with the run; no
 * real credential is ever configured here.
 */

if (PHP_SAPI !== 'cli') {
	// This is copied into the fixture's document root along with the probe
	// pages, so the built-in server can be asked for it. It is not a page, and
	// serving it does nothing.
	die("This is a command line mail stand-in.\n");
}

$mcm_smtp_options = array('port' => '0', 'transcript' => '', 'reject' => 'none', 'abandoned' => 'wait');
foreach (array_slice($argv, 1) as $mcm_smtp_argument) {
	if (preg_match('/^--([a-z-]+)=(.*)$/', $mcm_smtp_argument, $mcm_smtp_match)) {
		$mcm_smtp_options[$mcm_smtp_match[1]] = $mcm_smtp_match[2];
	}
}

$mcm_smtp_port       = (int) $mcm_smtp_options['port'];
$mcm_smtp_transcript = (string) $mcm_smtp_options['transcript'];
$mcm_smtp_reject     = (string) $mcm_smtp_options['reject'];
$mcm_smtp_abandoned  = (string) $mcm_smtp_options['abandoned'];

if ($mcm_smtp_port <= 0 || $mcm_smtp_transcript === '') {
	die("usage: php smtp_stub.php --port=<port> --transcript=<file> [--reject=none|auth|data] [--abandoned=wait|close]\n");
}

/** Append one line to the transcript. */
function mcm_smtp_record($line)
{
	// Appended and locked rather than buffered: the suite reads this file while
	// the stand-in is still running, and a half-written line would be read as a
	// fact about the send.
	file_put_contents($GLOBALS['mcm_smtp_transcript'], $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Send one reply line, with the line ending SMTP requires. */
function mcm_smtp_reply($connection, $line)
{
	fwrite($connection, $line . "\r\n");
}

/**
 * Read the message that follows an accepted DATA command.
 *
 * Ends at a line holding a single ".", which is what the client sends when it
 * has finished. A client that dies before sending one closes its socket
 * instead, and this returns false so the caller can say so.
 *
 * @param string $abandoned wait to keep reading whatever arrives, close to drop
 *                          the connection when the client's parting line does
 * @return int|false the number of bytes accepted, or false when the client left
 */
function mcm_smtp_read_message($connection, $abandoned)
{
	mcm_smtp_record('data-begin');
	$bytes = 0;

	while (($line = fgets($connection, 4096)) !== false) {
		$line = rtrim($line, "\r\n");
		if ($line === '.') {
			mcm_smtp_record('data-end ' . $bytes);
			return $bytes;
		}
		if ($abandoned === 'close' && strtolower($line) === 'quit') {
			// Not a command - inside a message there are no commands - but the
			// one line a client sends when its send has already died. Nothing
			// this suite sends has a body line like it.
			mcm_smtp_record('body ' . $line);
			mcm_smtp_record('data-abort ' . $bytes);
			return false;
		}
		// A real server undoes the doubled leading period the client added so
		// that a body line could not end the message early.
		if (substr($line, 0, 2) === '..') {
			$line = substr($line, 1);
		}
		$bytes += strlen($line) + 2;
		mcm_smtp_record('body ' . $line);
	}

	mcm_smtp_record('data-abort ' . $bytes);
	return false;
}

/** Play the server side of one connection. */
function mcm_smtp_serve($connection, $reject, $abandoned)
{
	// Bounded, so a client that opens a connection and then says nothing cannot
	// hold the stand-in - and with it the case waiting on it - for ever.
	stream_set_timeout($connection, 30);
	mcm_smtp_record('connect');
	mcm_smtp_reply($connection, '220 mcm mail stand-in');

	while (($line = fgets($connection, 4096)) !== false) {
		$command = rtrim($line, "\r\n");
		mcm_smtp_record('command ' . $command);
		$verb = strtoupper(substr($command, 0, 4));

		if ($verb === 'EHLO') {
			// Multi-line, so the client's own reply reader is exercised: every
			// line but the last carries a "-" after the code.
			mcm_smtp_reply($connection, '250-mcm mail stand-in');
			mcm_smtp_reply($connection, '250-AUTH LOGIN PLAIN');
			mcm_smtp_reply($connection, '250 HELP');
		} elseif ($verb === 'HELO') {
			mcm_smtp_reply($connection, '250 mcm mail stand-in');
		} elseif ($verb === 'AUTH') {
			if ($reject === 'auth') {
				mcm_smtp_reply($connection, '535 authentication failed');
				continue;
			}
			if (stripos($command, 'AUTH LOGIN') === 0) {
				// The two prompts a LOGIN exchange asks for, base64 as the
				// protocol wants them: "Username:" and "Password:".
				mcm_smtp_reply($connection, '334 VXNlcm5hbWU6');
				$sent = fgets($connection, 4096);
				mcm_smtp_record('command ' . rtrim((string) $sent, "\r\n"));
				mcm_smtp_reply($connection, '334 UGFzc3dvcmQ6');
				$sent = fgets($connection, 4096);
				mcm_smtp_record('command ' . rtrim((string) $sent, "\r\n"));
			} elseif (strtoupper(trim($command)) === 'AUTH PLAIN') {
				mcm_smtp_reply($connection, '334 ');
				$sent = fgets($connection, 4096);
				mcm_smtp_record('command ' . rtrim((string) $sent, "\r\n"));
			}
			mcm_smtp_reply($connection, '235 authentication succeeded');
		} elseif ($verb === 'MAIL' || $verb === 'RCPT') {
			mcm_smtp_reply($connection, '250 ok');
		} elseif ($verb === 'DATA') {
			if ($reject === 'data') {
				mcm_smtp_reply($connection, '554 message data not accepted');
				continue;
			}
			mcm_smtp_reply($connection, '354 end with <CRLF>.<CRLF>');
			if (mcm_smtp_read_message($connection, $abandoned) === false) {
				mcm_smtp_record('disconnect eof');
				return;
			}
			mcm_smtp_reply($connection, '250 ok');
		} elseif ($verb === 'RSET' || $verb === 'NOOP') {
			mcm_smtp_reply($connection, '250 ok');
		} elseif ($verb === 'QUIT') {
			mcm_smtp_reply($connection, '221 bye');
			mcm_smtp_record('disconnect quit');
			return;
		} else {
			mcm_smtp_reply($connection, '502 command not implemented');
		}
	}

	mcm_smtp_record('disconnect eof');
}

$mcm_smtp_server = @stream_socket_server('tcp://127.0.0.1:' . $mcm_smtp_port, $mcm_smtp_errno, $mcm_smtp_error);
if (!$mcm_smtp_server) {
	die('could not listen on 127.0.0.1:' . $mcm_smtp_port . ': ' . $mcm_smtp_error . "\n");
}

// Announced in the transcript rather than left to be discovered by connecting:
// a suite that probed the port to find out whether it was open would leave a
// connection of its own in the record of what the send did.
mcm_smtp_record('listening ' . $mcm_smtp_port);

// One connection at a time, for as long as the suite leaves it running. The
// harness signals it when the group is done; nothing here ends on its own,
// because a stand-in that stopped early would look like a refused connection.
while (true) {
	$mcm_smtp_connection = @stream_socket_accept($mcm_smtp_server, -1);
	if ($mcm_smtp_connection === false) {
		continue;
	}
	mcm_smtp_serve($mcm_smtp_connection, $mcm_smtp_reject, $mcm_smtp_abandoned);
	fclose($mcm_smtp_connection);
}
