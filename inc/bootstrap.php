<?php

/**
 * Shared application bootstrap.
 *
 * Every public entry point includes this file, first and exactly once. It is
 * the single place responsible for:
 *   1. installing the error, exception and shutdown handlers;
 *   2. loading configuration, on top of safe defaults;
 *   3. sending a plain-HTTP request on to the canonical HTTPS origin;
 *   4. starting the one and only session.
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
		$exception->getTraceAsString()
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
 * Only signals the web server itself sets are trusted. The forwarded header a
 * TLS-terminating proxy sends is consulted only when the configuration says
 * there is such a proxy, because any client can send that header.
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
	if (defined('MCM_TRUST_FORWARDED_PROTO') && MCM_TRUST_FORWARDED_PROTO && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
		// A chain of proxies appends to the header; the first entry is the one
		// the visitor's own connection used.
		$forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO']);
		return strtolower(trim($forwarded[0])) === 'https';
	}

	return false;
}

/**
 * Whether a string can be used as the host part of a redirect URL.
 *
 * Deliberately narrow: a host name or address, with an optional port, and
 * nothing else. Anything that could carry credentials, a path or a second host
 * fails here. The caller trims a scheme and path off a configured value first,
 * so this only ever judges what is left.
 *
 * @param string $host
 * @return bool
 */
function mcm_valid_host($host)
{
	return preg_match('#^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9]([A-Za-z0-9\-.]*[A-Za-z0-9])?)(:[0-9]{1,5})?$#', $host) === 1;
}

/**
 * The origin every absolute redirect is built on: "https://" and the
 * configured canonical host.
 *
 * Returns an empty string when no canonical host is configured, or when the
 * configured one is unusable. The request's own Host header is never a
 * fallback - that is the whole point of this function - so redirects stay
 * relative instead, which keeps them on whichever host the visitor is already
 * using without letting the request name a different one.
 *
 * @return string
 */
function mcm_canonical_origin()
{
	static $origin = null;

	if ($origin !== null) {
		return $origin;
	}

	$host = defined('MCM_CANONICAL_HOST') ? trim((string) MCM_CANONICAL_HOST) : '';
	if ($host === '') {
		return $origin = '';
	}

	// Be forgiving about the shape a site writes it in: "https://example.com/"
	// means the same thing as "example.com".
	$scheme = strpos($host, '://');
	if ($scheme !== false) {
		$host = substr($host, $scheme + 3);
	}
	$slash = strpos($host, '/');
	if ($slash !== false) {
		$host = substr($host, 0, $slash);
	}

	if (!mcm_valid_host($host)) {
		mcm_log('Configuration', 'MCM_CANONICAL_HOST is not a usable host name, so redirects stay relative');
		return $origin = '';
	}

	return $origin = 'https://' . $host;
}

/**
 * Reduce any value to a path on this site.
 *
 * Whatever comes in, what comes out starts with exactly one "/" and names no
 * origin of its own, so it cannot send a visitor to another site. Query
 * strings survive; a scheme and host do not.
 *
 * @param string $path
 * @return string
 */
function mcm_local_path($path)
{
	$path = (string) $path;

	// A header value ends at the first control character anyway, and PHP would
	// refuse the whole header rather than truncate it.
	$path = substr($path, 0, strcspn($path, "\r\n\0"));

	// "http://elsewhere/x" keeps only "/x".
	$scheme = strpos($path, '://');
	if ($scheme !== false) {
		$rest  = substr($path, $scheme + 3);
		$slash = strpos($rest, '/');
		$path  = ($slash === false) ? '' : substr($rest, $slash);
	}

	// "//elsewhere/x" is a URL for another origin rather than a path on this
	// one, and browsers read a backslash here as a slash, so both go.
	return '/' . ltrim($path, "/\\");
}

/**
 * The value a Location header should carry to send a visitor to $path here.
 *
 * Absolute and HTTPS when a canonical host is configured, and a path on the
 * current host otherwise. Either way the request cannot influence it.
 *
 * @param string $path path on this site, e.g. "/index.php"
 * @return string
 */
function mcm_redirect_target($path)
{
	return mcm_canonical_origin() . mcm_local_path($path);
}

/**
 * Redirect to $path on this site and stop the request.
 *
 * @param string $path   path on this site
 * @param int    $status 302 by default; see the note on permanence below
 */
function mcm_redirect($path, $status = 302)
{
	if (!headers_sent()) {
		header('Location: ' . mcm_redirect_target($path), true, $status);
	}
	exit;
}

/**
 * Whether a plain-HTTP request should be sent on to the HTTPS origin.
 *
 * Enforcement needs a canonical host: without one there is no HTTPS address to
 * send anyone to that the request itself did not supply.
 *
 * @return bool
 */
function mcm_https_is_enforced()
{
	$forced = defined('MCM_FORCE_HTTPS') ? MCM_FORCE_HTTPS : null;

	if ($forced === false) {
		return false;
	}
	if (mcm_canonical_origin() !== '') {
		return true;
	}

	if ($forced) {
		static $logged = false;
		if (!$logged) {
			$logged = true;
			mcm_log('Configuration', 'MCM_FORCE_HTTPS is on but MCM_CANONICAL_HOST is not set, so HTTPS is not enforced');
		}
	}
	return false;
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
	// The one host the application is willing to name in a redirect, e.g.
	// "example.com". Empty means none is configured and redirects stay
	// relative; either way the request's Host header is never used.
	'MCM_CANONICAL_HOST'          => '',
	// null means "enforce HTTPS once a canonical host is configured". false
	// switches enforcement off again with no code change.
	'MCM_FORCE_HTTPS'             => null,
	// Only true where a proxy in front of this application terminates TLS and
	// forwards the request over plain HTTP.
	'MCM_TRUST_FORWARDED_PROTO'   => false,
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
 * 3. HTTPS
 * ---------------------------------------------------------------------------
 *
 * A plain-HTTP request is sent on to the same address on the canonical HTTPS
 * origin, before the session is started, so no session cookie is ever issued
 * over an unencrypted connection.
 *
 * Two deliberate limits:
 *
 *   - The redirect is temporary (302, or 307 so that a form submission keeps
 *     its method and body). A permanent redirect is remembered by browsers and
 *     would outlive the switch that turns this off, which is the same trap that
 *     keeps HSTS out of this file for now.
 *   - Only the scheme is corrected. A request that already arrived over HTTPS
 *     is served whatever host it came in on, so a site with more than one
 *     working host name keeps them all, and a mistake in MCM_CANONICAL_HOST
 *     cannot make the site unreachable.
 *
 * To switch enforcement off, put this in inc/config/config.php:
 *
 *     define('MCM_FORCE_HTTPS', false);
 */

// The command line has no request to redirect.
if (PHP_SAPI !== 'cli' && !mcm_request_is_https() && mcm_https_is_enforced()) {
	$mcm_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
	if (isset($_SERVER['REQUEST_URI'])) {
		$mcm_here = $_SERVER['REQUEST_URI'];
	} else {
		$mcm_here = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/';
	}

	mcm_redirect($mcm_here, ($mcm_method === 'GET' || $mcm_method === 'HEAD') ? 302 : 307);
}

/*
 * ---------------------------------------------------------------------------
 * 4. Session
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
