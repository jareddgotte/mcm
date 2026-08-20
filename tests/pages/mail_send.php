<?php

/**
 * Drive one of the site's two mail paths, and report what happened.
 *
 * A stand-in for the mail half of register.php and password_reset.php: it loads
 * the bootstrap and php-login exactly as those pages do, then calls the very
 * method they call - Registration::sendVerificationEmail() or
 * Login::sendPasswordResetMail() - so what runs is the real path, message and
 * all, with only the far end replaced by a stand-in on the loopback interface.
 *
 * The report is printed as it happens rather than at the end, and that is the
 * point: a runtime on which the send dies never reaches the lines after
 * "attempt=start", so what this page printed is what the suite reads to tell a
 * failed send from a send that could not finish at all.
 *
 * Which path runs, and the values it sends, come from the environment:
 *
 *   MCM_MAIL_PATH   registration (default) or password_reset
 *   MCM_MAIL_TO     the recipient address
 *   MCM_MAIL_USER   the account name or id the link is built for
 *   MCM_MAIL_TOKEN  the activation or reset token the link carries
 */

require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/php-login.php');

/** One "key=value" line, sent on its way immediately. */
function mcm_mail_report($key, $value)
{
	echo $key . '=' . $value . "\n";
	flush();
}

/** An environment value, or a default. */
function mcm_mail_env($name, $default)
{
	$value = getenv($name);

	return ($value === false || $value === '') ? $default : $value;
}

$path  = mcm_mail_env('MCM_MAIL_PATH', 'registration');
$to    = mcm_mail_env('MCM_MAIL_TO', 'recipient@example.test');
$user  = mcm_mail_env('MCM_MAIL_USER', '7');
$token = mcm_mail_env('MCM_MAIL_TOKEN', 'token');

mcm_mail_report('php_version', PHP_VERSION);
// The two functions this library calls that PHP 8.0 removed. Reported off the
// runtime rather than assumed, so the matrix says which runtime it ran on and
// what that runtime actually offers.
mcm_mail_report('each_exists', function_exists('each') ? 'yes' : 'no');
mcm_mail_report('get_magic_quotes_runtime_exists', function_exists('get_magic_quotes_runtime') ? 'yes' : 'no');
mcm_mail_report('transport', EMAIL_USE_SMTP ? 'smtp' : 'mail');
mcm_mail_report('path', $path);

// Which classes this request has read at the moment before it sends anything.
// Loading them is the autoloader's job now, so on a page that has done nothing
// but load the bootstrap and php-login the answer is no - the same answer every
// page that never sends gives, and the reason this one is worth reporting.
mcm_mail_report('phpmailer_before', class_exists('PHPMailer', false) ? 'yes' : 'no');
mcm_mail_report('smtp_before', class_exists('SMTP', false) ? 'yes' : 'no');

// And which it has read by the time the request ends, whichever way it ends.
// Reported from a shutdown function on purpose: on a runtime where the send
// dies inside the call, nothing after that line runs, and "the send is what
// loaded the library" would otherwise be unanswerable on exactly the runtimes
// this project targets.
register_shutdown_function(function () {
	mcm_mail_report('phpmailer_after', class_exists('PHPMailer', false) ? 'yes' : 'no');
	mcm_mail_report('smtp_after', class_exists('SMTP', false) ? 'yes' : 'no');
});

// Everything above this line is what the runtime and the configuration say.
// Everything below it is what the send did.
mcm_mail_report('attempt', 'start');

if ($path === 'password_reset') {
	$login  = new Login();
	$result = $login->sendPasswordResetMail($user, $to, $token);
	$errors = $login->errors;
} else {
	$registration = new Registration();
	$result       = $registration->sendVerificationEmail($user, $to, $token);
	$errors       = $registration->errors;
}

mcm_mail_report('attempt', 'returned');
mcm_mail_report('result', $result ? 'true' : 'false');
// What the visitor would be shown. On the path that returns false this carries
// the library's own message, which is why it is reported rather than assumed.
mcm_mail_report('errors_json', json_encode($errors));
