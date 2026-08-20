<?php

/**
 * A stand-in for the local mail transfer agent, for the path mail() takes.
 *
 * The site can be configured either way: EMAIL_USE_SMTP false makes both mail
 * callers ask PHPMailer for mail(), which hands the message to whatever
 * sendmail_path names. A fixture points sendmail_path here, so that path runs
 * end to end - the same PHPMailer, the same message - with nothing delivered
 * anywhere: the message is appended to a mailbox file and that is all.
 *
 *     ... | php sendmail_stub.php --mailbox=<file> [anything else]
 *
 * PHP appends mail()'s own arguments to the command, so unknown arguments are
 * ignored rather than refused. The message arrives on standard input, exactly
 * as a real agent would receive it, and is written out between markers so that
 * a case can tell one message from the next.
 */

if (PHP_SAPI !== 'cli') {
	// Copied into the fixture's document root with the probe pages; it is not a
	// page, and serving it does nothing.
	die("This is a command line mail stand-in.\n");
}

$mcm_sendmail_mailbox = '';
foreach (array_slice($argv, 1) as $mcm_sendmail_argument) {
	if (strpos($mcm_sendmail_argument, '--mailbox=') === 0) {
		$mcm_sendmail_mailbox = substr($mcm_sendmail_argument, 10);
	}
}

if ($mcm_sendmail_mailbox === '') {
	// Exit non-zero: mail() reports the exit status back to PHPMailer, so a
	// misconfigured stand-in must look like a failed send rather than a
	// successful one that delivered nowhere.
	fwrite(STDERR, "usage: php sendmail_stub.php --mailbox=<file>\n");
	exit(1);
}

$mcm_sendmail_message = stream_get_contents(STDIN);

file_put_contents(
	$mcm_sendmail_mailbox,
	"message-begin\n" . $mcm_sendmail_message . "\nmessage-end\n",
	FILE_APPEND | LOCK_EX
);

exit(0);
