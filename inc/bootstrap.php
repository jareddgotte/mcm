<?php

/**
 * Shared application bootstrap.
 *
 * Every public entry point includes this file, first and exactly once. It is
 * the single place responsible for:
 *   1. installing the error, exception and shutdown handlers;
 *   2. loading configuration, on top of safe defaults;
 *   3. starting the one and only session;
 *   4. handing out database connections, and deciding what a database failure
 *      does to the request.
 *
 * The file is inert on its own: nothing happens until an entry point includes
 * it, and including it a second time in the same request is a no-op.
 *
 * Nothing here changes what a successful request produces. It only decides
 * what happens when configuration is missing, what happens when something
 * fails, and which attributes the session cookie carries.
 */

// Second and later includes in the same request stop right here.
if (defined('MCM_BOOTSTRAP')) {
	return;
}

/**
 * MCM_BOOTSTRAP marks a legitimate application bootstrap. Config files check
 * for it so that a direct web request to a config file cannot execute
 * meaningfully; defining it here has no other effect.
 */
define('MCM_BOOTSTRAP', true);

/**
 * Write one line to the server-side error log.
 *
 * Everything useful for diagnosis goes here and only here. The client never
 * sees any of it.
 *
 * @param string $label   short classification, e.g. "Uncaught Exception"
 * @param string $message the failure detail
 * @param string $file    file the failure was raised in, if known
 * @param int    $line    line the failure was raised on, if known
 * @param string $trace   optional stack trace
 */
function mcm_log($label, $message, $file = '', $line = 0, $trace = '')
{
	// MCM_LOG_ERRORS is not defined yet while the configuration is still being
	// loaded, and a failure that early must always be recorded.
	if (defined('MCM_LOG_ERRORS') && !MCM_LOG_ERRORS) {
		return;
	}

	if (isset($_SERVER['REQUEST_URI'])) {
		$request = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] . ' ' : '') . $_SERVER['REQUEST_URI'];
	} else {
		$request = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : 'no request context';
	}

	$entry = '[mcm] ' . $label . ': ' . $message;
	if ($file !== '') {
		$entry .= ' in ' . $file . ' on line ' . (int) $line;
	}
	$entry .= ' [' . $request . ']';
	if ($trace !== '') {
		$entry .= PHP_EOL . $trace;
	}

	error_log($entry);
}

/**
 * Send a generic failure response and stop the request.
 *
 * The body is deliberately free of any detail about what went wrong; the
 * detail has already gone to the error log. Repeat calls within one request
 * emit nothing further, so a fatal error arriving after an uncaught exception
 * cannot produce a second body.
 */
function mcm_fail()
{
	static $already_sent = false;

	if ($already_sent) {
		return;
	}
	$already_sent = true;

	if (!headers_sent()) {
		header('HTTP/1.1 500 Internal Server Error', true, 500);
		header('Content-Type: text/plain; charset=utf-8');
	}
	echo defined('MCM_GENERIC_ERROR_MESSAGE')
		? MCM_GENERIC_ERROR_MESSAGE
		: 'Sorry, something went wrong. Please try again later.';
	echo "\n";

	exit(1);
}

/**
 * Log a bootstrap-time failure and stop with the generic response.
 *
 * Used for problems that make the request impossible to serve at all, such as
 * configuration that is missing or unusable.
 *
 * @param string $message detail for the log; never reaches the client
 */
function mcm_bootstrap_fail($message)
{
	mcm_log('Bootstrap failure', $message);
	mcm_fail();
}

/**
 * Remove quoted call arguments from a stack trace.
 *
 * File, line and function name are what a trace is read for. The arguments are
 * what makes keeping one dangerous: PHP records them as plain strings, and on
 * this site those strings include the database password on a connection frame
 * and a visitor's own password on a login frame. Runtimes from PHP 7.4 on can
 * be told to leave them out at the source; this covers the ones that cannot,
 * and costs nothing where they are already gone.
 *
 * @param string $trace
 * @return string
 */
function mcm_scrub_trace($trace)
{
	return preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "'...'", $trace);
}

/**
 * Human-readable name for a PHP error level.
 *
 * @param int $errno
 * @return string
 */
function mcm_error_label($errno)
{
	$labels = array(
		E_ERROR             => 'Error',
		E_WARNING           => 'Warning',
		E_PARSE             => 'Parse error',
		E_NOTICE            => 'Notice',
		E_CORE_ERROR        => 'Core error',
		E_CORE_WARNING      => 'Core warning',
		E_COMPILE_ERROR     => 'Compile error',
		E_COMPILE_WARNING   => 'Compile warning',
		E_USER_ERROR        => 'User error',
		E_USER_WARNING      => 'User warning',
		E_USER_NOTICE       => 'User notice',
		E_RECOVERABLE_ERROR => 'Recoverable error',
		E_DEPRECATED        => 'Deprecated',
		E_USER_DEPRECATED   => 'User deprecated',
	);

	return isset($labels[$errno]) ? $labels[$errno] : 'Error level ' . (int) $errno;
}

/**
 * Error handler: log server-side, tell the client nothing.
 *
 * Levels PHP would have carried on from (notices, warnings) still let the
 * request carry on, so no currently working page changes. Only levels that
 * cannot be recovered from end the request with the generic response.
 */
function mcm_error_handler($errno, $errstr, $errfile = '', $errline = 0)
{
	// Diagnostics suppressed with "@", or filtered out by the configured
	// error_reporting level, stay silent and are not logged.
	if ((error_reporting() & $errno) === 0) {
		return true;
	}

	mcm_log(mcm_error_label($errno), $errstr, $errfile, $errline);

	if ($errno === E_USER_ERROR || $errno === E_RECOVERABLE_ERROR) {
		mcm_fail();
	}

	// Handled: PHP's own reporting must not add anything of its own.
	return true;
}

/**
 * Exception handler: log the exception with its trace, return a generic body.
 *
 * The parameter is deliberately untyped so that it also catches PHP 7's Error
 * hierarchy on newer runtimes while staying parseable on older ones.
 */
function mcm_exception_handler($exception)
{
	mcm_log(
		'Uncaught ' . get_class($exception),
		$exception->getMessage(),
		$exception->getFile(),
		$exception->getLine(),
		mcm_scrub_trace($exception->getTraceAsString())
	);
	mcm_fail();
}

/**
 * Shutdown handler: catch the fatal errors that never reach the error handler.
 */
function mcm_shutdown_handler()
{
	$error = error_get_last();
	if ($error === null) {
		return;
	}

	$fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
	if (($error['type'] & $fatal) === 0) {
		return;
	}

	mcm_log(mcm_error_label($error['type']), $error['message'], $error['file'], $error['line']);
	mcm_fail();
}

/**
 * Whether the current request arrived over HTTPS.
 *
 * Only signals the web server itself sets are trusted here; forwarded headers
 * are not consulted.
 *
 * @return bool
 */
function mcm_request_is_https()
{
	if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
		return true;
	}
	if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
		return true;
	}

	return false;
}

/**
 * Open a database connection.
 *
 * The only place in the application that builds a DSN or touches the
 * credentials, which is why it is also the only place that has to be careful
 * with them. On failure the driver's own message is logged - it names the real
 * problem, such as a refused connection or an unknown database - and null is
 * returned. The stack trace is deliberately left out: PHP records call
 * arguments in a trace, and the arguments on the frame that just failed are the
 * DSN, the user and the password.
 *
 * @param string $context what the connection was being opened for; logged
 * @return PDO|null the connection, or null when it could not be opened
 */
function mcm_db_connect($context)
{
	try {
		$connection = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
	} catch (PDOException $exception) {
		mcm_log('Database error', $context . ': connection failed: ' . $exception->getMessage());
		return null;
	}

	// The driver's own default differs between runtimes - silent on the ones
	// this code grew up on, throwing from PHP 8 on - so a failing statement
	// used to behave differently depending on where the site was hosted.
	// Pinning it makes that one behaviour everywhere; mcm_db_execute() handles
	// both shapes regardless.
	$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	return $connection;
}

/**
 * Open a database connection, or stop the request.
 *
 * A page that cannot reach the database has nothing to serve. Carrying on with
 * a connection that is not one turns the outage into a second, unrelated
 * failure on the next line, which is what used to reach the client; this either
 * returns a usable connection or does not return at all.
 *
 * @param string $context what the connection was being opened for; logged
 * @return PDO
 */
function mcm_db_or_fail($context)
{
	$connection = mcm_db_connect($context);

	if ($connection === null) {
		// mcm_db_connect() has already logged why. The client gets the generic
		// body and nothing else.
		mcm_fail();
	}

	return $connection;
}

/**
 * Run a prepared statement, or stop the request.
 *
 * Both shapes of statement failure are covered: the exception a modern driver
 * throws, and the false an older one returns. Either way the driver's message
 * and the statement's own SQL go to the log - between them they are enough to
 * find the fault - and the client gets the generic body.
 *
 * @param PDOStatement $statement the prepared statement to run
 * @param string       $context   what the query was for; logged
 * @return bool true; on failure the request has already ended
 */
function mcm_db_execute($statement, $context)
{
	if (!is_object($statement)) {
		$detail = 'the statement was never prepared';
	} else {
		try {
			if ($statement->execute() !== false) {
				return true;
			}

			$info   = $statement->errorInfo();
			$detail = (isset($info[2]) && $info[2] !== null) ? $info[2] : 'the driver reported no detail';
			if (isset($info[0]) && $info[0] !== null) {
				$detail = 'SQLSTATE[' . $info[0] . '] ' . $detail;
			}
		} catch (PDOException $exception) {
			$detail = $exception->getMessage();
		}

		// A prepared statement carries placeholders, not values, so the SQL is
		// safe to log and is the fastest way to recognise which query failed.
		if (isset($statement->queryString) && $statement->queryString !== '') {
			$detail .= ' [query: ' . $statement->queryString . ']';
		}
	}

	mcm_log('Database error', $context . ': ' . $detail);
	mcm_fail();
}

/*
 * ---------------------------------------------------------------------------
 * 1. Error behaviour, before anything else
 * ---------------------------------------------------------------------------
 *
 * The handlers go in ahead of the configuration load so that a configuration
 * file which is missing, unreadable or broken also produces the generic
 * response instead of a PHP message on the page. The configured values are
 * applied again below, once they are known.
 */

error_reporting(E_ALL & ~E_NOTICE);
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
// Keep call arguments - which include passwords - out of the traces PHP builds
// for exceptions. Available from PHP 7.4; mcm_scrub_trace() covers the rest.
@ini_set('zend.exception_ignore_args', '1');

set_error_handler('mcm_error_handler');
set_exception_handler('mcm_exception_handler');
register_shutdown_function('mcm_shutdown_handler');

/*
 * ---------------------------------------------------------------------------
 * 2. Configuration
 * ---------------------------------------------------------------------------
 *
 * Two layers, in this order:
 *   a. inc/config/config.php - the site's own values, never in this repository
 *   b. the defaults below    - fill in anything (a) did not define
 *
 * Because the site configuration is loaded first and PHP constants cannot be
 * redefined, an existing config.php always wins and needs no edits at all.
 */

$mcm_config_file = dirname(__FILE__) . '/config/config.php';

if (!is_readable($mcm_config_file)) {
	// No config means no database, no cookie key and no mail settings. There is
	// nothing safe to serve, and the reason must not leak to the client.
	mcm_bootstrap_fail('configuration file is missing or unreadable');
}

require_once $mcm_config_file;

// Safe defaults. Every one of these is a value a production site can run on
// unchanged; see inc/config/example_config.php for how to override them.
$mcm_defaults = array(
	// Report everything except notices, which is what this codebase has always
	// run with. Reports are logged, never shown to the client.
	'MCM_ERROR_REPORTING'         => E_ALL & ~E_NOTICE,
	'MCM_DISPLAY_ERRORS'          => false,
	'MCM_LOG_ERRORS'              => true,
	// Empty means "wherever PHP is already configured to log".
	'MCM_ERROR_LOG'               => '',
	'MCM_GENERIC_ERROR_MESSAGE'   => 'Sorry, something went wrong. Please try again later.',
	// Session cookie. The cookie NAME is intentionally absent: it stays at the
	// server default, because renaming it would drop every current visitor's
	// session for no meaningful gain.
	'MCM_SESSION_COOKIE_LIFETIME' => 0,
	'MCM_SESSION_COOKIE_PATH'     => '/',
	'MCM_SESSION_COOKIE_SAMESITE' => 'Lax',
	// null means "secure when the request came in over HTTPS". Set it to true
	// once the site is HTTPS-only.
	'MCM_SESSION_COOKIE_SECURE'   => null,
);

foreach ($mcm_defaults as $mcm_name => $mcm_value) {
	if (!defined($mcm_name)) {
		define($mcm_name, $mcm_value);
	}
}
unset($mcm_defaults, $mcm_value, $mcm_config_file);

// A config.php that loads but does not carry the settings the application
// cannot run without is treated the same as no config.php at all.
$mcm_required = array('DB_HOST', 'DB_NAME', 'DB_USER', 'COOKIE_RUNTIME', 'COOKIE_SECRET_KEY');
$mcm_invalid  = array();

foreach ($mcm_required as $mcm_name) {
	if (!defined($mcm_name)) {
		$mcm_invalid[] = $mcm_name . ' (not defined)';
	} elseif (constant($mcm_name) === '' || constant($mcm_name) === null) {
		$mcm_invalid[] = $mcm_name . ' (empty)';
	}
}
// DB_PASS may legitimately be empty on a local database, so only its presence
// is checked.
if (!defined('DB_PASS')) {
	$mcm_invalid[] = 'DB_PASS (not defined)';
}
if (defined('COOKIE_RUNTIME') && !is_numeric(COOKIE_RUNTIME)) {
	$mcm_invalid[] = 'COOKIE_RUNTIME (not a number)';
}

if (count($mcm_invalid) > 0) {
	mcm_bootstrap_fail('configuration is unusable: ' . implode(', ', $mcm_invalid));
}
unset($mcm_required, $mcm_invalid, $mcm_name);

// Now that the configuration is known, apply what it says about errors.
error_reporting(MCM_ERROR_REPORTING);
@ini_set('display_errors', MCM_DISPLAY_ERRORS ? '1' : '0');
@ini_set('display_startup_errors', MCM_DISPLAY_ERRORS ? '1' : '0');
@ini_set('log_errors', MCM_LOG_ERRORS ? '1' : '0');
if (MCM_ERROR_LOG !== '') {
	@ini_set('error_log', MCM_ERROR_LOG);
}

/*
 * ---------------------------------------------------------------------------
 * 3. Session
 * ---------------------------------------------------------------------------
 *
 * This is the only session_start() in the application. Entry points and the
 * Login / Registration classes all rely on the session already being open by
 * the time they run.
 */

// Only cookies may carry the session id, and an id the server never issued is
// not adopted.
@ini_set('session.use_only_cookies', '1');
@ini_set('session.use_strict_mode', '1');
@ini_set('session.use_trans_sid', '0');

$mcm_cookie_secure = (MCM_SESSION_COOKIE_SECURE === null)
	? mcm_request_is_https()
	: (bool) MCM_SESSION_COOKIE_SECURE;

if (PHP_VERSION_ID >= 70300) {
	session_set_cookie_params(array(
		'lifetime' => (int) MCM_SESSION_COOKIE_LIFETIME,
		'path'     => MCM_SESSION_COOKIE_PATH,
		// An empty domain keeps the cookie host-only: it is not offered to any
		// sub-domain, unlike the leading-dot COOKIE_DOMAIN used for remember-me.
		'domain'   => '',
		'secure'   => $mcm_cookie_secure,
		'httponly' => true,
		'samesite' => MCM_SESSION_COOKIE_SAMESITE,
	));
} else {
	// SameSite cannot be expressed through session_set_cookie_params() before
	// PHP 7.3; the remaining attributes are still applied.
	session_set_cookie_params(
		(int) MCM_SESSION_COOKIE_LIFETIME,
		MCM_SESSION_COOKIE_PATH,
		'',
		$mcm_cookie_secure,
		true
	);
}
unset($mcm_cookie_secure);

$mcm_session_active = function_exists('session_status')
	? (session_status() === PHP_SESSION_ACTIVE)
	: (session_id() !== '');

if (!$mcm_session_active && !session_start()) {
	mcm_bootstrap_fail('session could not be started');
}
unset($mcm_session_active);
