<?php

/**
 * The backend-only TMDb client.
 *
 * A small first-party HTTP client for TMDb's read API, and deliberately
 * nothing more: no third-party library, no dependency manager, no ambition
 * beyond one GET at a time. It exists so that every TMDb request this site
 * makes goes out from the server, under limits the server chose, carrying a
 * credential the browser never sees.
 *
 * Three rules shape everything below.
 *
 *   1. The credential is a bearer token read from inc/config/config.php, and it
 *      only ever appears in one place: an Authorization request header on a
 *      handle that is built, used and thrown away inside a single call. It is
 *      never a query parameter, never part of a URL, never assigned into
 *      $_SESSION, never rendered into a page or a script, and never written to
 *      the log. mcm_tmdb_get() takes no credential argument for the same
 *      reason - there is nowhere for a caller to pass one in from.
 *
 *   2. A request is bounded before it is made. It has a connect timeout, a
 *      total timeout, and a size cap enforced as the body arrives, so a slow
 *      endpoint cannot hold a request open and a large one cannot be buffered
 *      into memory. The cap aborts the transfer on the first chunk that would
 *      cross it, so the excess bytes are never held at all.
 *
 *   3. A failure is a category and a fixed sentence, and that is the whole of
 *      what a caller can learn. The upstream body, the URL, the cURL detail
 *      and the credential all stop here; what actually went wrong goes to the
 *      server-side log through mcm_log(), exactly as a refused request's
 *      reason does in inc/guards.php.
 *
 * The file is inert on its own: it declares functions and does nothing else.
 * Nothing loads it yet - the entry point that will is separate work - so
 * including it changes no request.
 */

// The configuration, the error handling and mcm_log() all come from the shared
// bootstrap. Requiring it here is a no-op for an entry point that has already
// loaded it, and keeps this file usable on its own.
require_once(dirname(__FILE__) . '/bootstrap.php');

/**
 * The failure categories, and the one sentence each of them is allowed to say.
 *
 * This is inc/guards.php's error catalogue applied to an outbound request: two
 * different reasons sharing a category cannot be told apart from outside, so a
 * caller - and through it a visitor - learns which kind of thing went wrong and
 * never which particular one.
 *
 * @return array category => message
 */
function mcm_tmdb_failure_catalogue()
{
	return array(
		// Nothing was sent: this site has no usable token or endpoint.
		'configuration' => 'The movie database is not configured for this site.',
		// The request never completed: refused, unresolvable, or TLS declined.
		'unavailable'   => 'The movie database could not be reached.',
		// Connect or total timeout.
		'timeout'       => 'The movie database took too long to answer.',
		// The response crossed the size cap and was abandoned.
		'too_large'     => 'The movie database sent more than this site will read.',
		// A completed response with a status this site will not act on.
		'upstream'      => 'The movie database did not answer this request.',
		// A 2xx whose body is not the JSON object it has to be.
		'malformed'     => 'The movie database sent something this site could not read.',
	);
}

/**
 * Build one failure result, and record the reason where only the server sees it.
 *
 * $detail is for the log alone. Call sites keep it to fixed words and numbers -
 * a category, a cURL error number, the path that was asked for - because a
 * driver message or an upstream body would put unbounded text in the log, and
 * cURL's own message can carry the URL and the address it resolved to.
 *
 * @param string $category one of mcm_tmdb_failure_catalogue()'s keys
 * @param string $detail   for the log; never reaches the caller
 * @param int    $status   the upstream HTTP status, where there was one
 * @return array
 */
function mcm_tmdb_failure($category, $detail = '', $status = 0)
{
	$catalogue = mcm_tmdb_failure_catalogue();
	if (!isset($catalogue[$category])) {
		$category = 'unavailable';
	}

	if ($detail !== '') {
		mcm_log('TMDb request failed', $category . ': ' . $detail);
	}

	return array(
		'ok'       => false,
		'category' => $category,
		'message'  => $catalogue[$category],
		'status'   => (int) $status,
	);
}

/**
 * The API origin every request is built on.
 *
 * MCM_TMDB_BASE_URL reaches the client verbatim, which is the whole of the seam
 * the test suite needs: pointing it at a loopback address is how the suite
 * drives this code against a stub without a network, a live request or a real
 * credential. A production site never sets it.
 *
 * @return string
 */
function mcm_tmdb_base_url()
{
	return defined('MCM_TMDB_BASE_URL') ? rtrim(trim((string) MCM_TMDB_BASE_URL), '/') : '';
}

/**
 * The bearer token, or an empty string when the site has none.
 *
 * @return string
 */
function mcm_tmdb_token()
{
	return defined('TMDB_READ_ACCESS_TOKEN') ? trim((string) TMDB_READ_ACCESS_TOKEN) : '';
}

/**
 * The transport limits, as whole milliseconds and whole bytes.
 *
 * Every one of them has a bootstrap default, so a site that configures nothing
 * still gets bounded requests. A value that is not a positive number falls back
 * to the default rather than being honoured: "0" means "no limit" to cURL, and
 * an unbounded request is exactly what this client exists to prevent.
 *
 * @return array connect_ms, total_ms, max_bytes
 */
function mcm_tmdb_limits()
{
	$limits = array(
		'connect_ms' => array('MCM_TMDB_CONNECT_TIMEOUT_MS', 3000),
		'total_ms'   => array('MCM_TMDB_TIMEOUT_MS', 8000),
		'max_bytes'  => array('MCM_TMDB_MAX_BYTES', 1048576),
	);

	$resolved = array();
	foreach ($limits as $key => $limit) {
		$value = defined($limit[0]) ? constant($limit[0]) : null;
		$value = (is_int($value) || (is_string($value) && ctype_digit($value))) ? (int) $value : 0;
		$resolved[$key] = ($value > 0) ? $value : $limit[1];
	}
	return $resolved;
}

/**
 * Whether a host name is one of the loopback literals.
 *
 * This is the one case where a plain-HTTP endpoint is allowed, and the reason
 * is narrow: a request to 127.0.0.1, ::1 or localhost never leaves the machine,
 * so there is no wire for the Authorization header to be read off. Every other
 * host must be HTTPS, so a misconfigured endpoint cannot put the token on a
 * network in the clear.
 *
 * @param string $host
 * @return bool
 */
function mcm_tmdb_is_loopback_host($host)
{
	$host = strtolower(trim($host, '[]'));

	return ($host === 'localhost' || $host === '::1' || strpos($host, '127.') === 0);
}

/**
 * Why this endpoint may not be used, or an empty string when it may.
 *
 * @param string $base
 * @return string a fixed reason for the log
 */
function mcm_tmdb_endpoint_error($base)
{
	if ($base === '') {
		return 'no endpoint is configured';
	}

	$parts = parse_url($base);
	if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
		return 'the configured endpoint is not a usable absolute URL';
	}
	if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
		return 'the configured endpoint carries a query, a fragment or credentials';
	}

	$scheme = strtolower($parts['scheme']);
	if ($scheme === 'https') {
		return '';
	}
	if ($scheme === 'http' && mcm_tmdb_is_loopback_host($parts['host'])) {
		return '';
	}
	return 'the configured endpoint is not https and is not a loopback address';
}

/**
 * Why this path may not be requested, or an empty string when it may.
 *
 * The path is written by this application, never by a visitor, so this is a
 * second layer rather than the first one: it is what stops a future caller from
 * pasting a whole URL, a traversal or a query string in here and quietly
 * sending the Authorization header somewhere else.
 *
 * @param string $path
 * @return string a fixed reason for the log
 */
function mcm_tmdb_path_error($path)
{
	if (!is_string($path) || $path === '' || $path[0] !== '/') {
		return 'the requested path does not begin with a slash';
	}
	if (preg_match('#^/[A-Za-z0-9][A-Za-z0-9._~/-]*$#', $path) !== 1) {
		return 'the requested path holds a character this client will not send';
	}
	if (strpos($path, '//') !== false || strpos($path, '..') !== false) {
		return 'the requested path holds an empty or relative segment';
	}
	return '';
}

/**
 * Why this query may not be sent, or an empty string when it may.
 *
 * "api_key" is refused by name. This client authenticates with a header and
 * only with a header, so a parameter of that name could only ever be an
 * attempt - deliberate or accidental - to put a credential in a URL.
 *
 * @param array $query
 * @return string a fixed reason for the log
 */
function mcm_tmdb_query_error(array $query)
{
	foreach ($query as $name => $value) {
		if (!is_string($name) || preg_match('#^[A-Za-z0-9_.]+$#', $name) !== 1) {
			return 'a query parameter is not named the way this client will send one';
		}
		if (strcasecmp($name, 'api_key') === 0 || strcasecmp($name, 'session_id') === 0) {
			return 'a query parameter would have put a credential in the URL';
		}
		if (!is_scalar($value) && $value !== null) {
			return 'a query parameter is not a scalar value';
		}
	}
	return '';
}

/**
 * Why this token may not be sent, or an empty string when it may.
 *
 * The control-character check is the important one: a token holding a carriage
 * return or a newline would end the Authorization header early and let whatever
 * followed it be sent as headers of its own.
 *
 * @param string $token
 * @return string a fixed reason for the log
 */
function mcm_tmdb_token_error($token)
{
	if ($token === '') {
		return 'no read access token is configured';
	}
	if (preg_match('#[^\x21-\x7E]#', $token) === 1) {
		return 'the configured read access token holds a character an HTTP header cannot carry';
	}
	return '';
}

/**
 * The URL one request is made to: the configured origin, the path, and the
 * query encoded for a URL.
 *
 * @param string $base
 * @param string $path
 * @param array  $query
 * @return string
 */
function mcm_tmdb_url($base, $path, array $query)
{
	$url = $base . $path;
	if (count($query) > 0) {
		$url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}
	return $url;
}

/**
 * The cURL options one request is made with, as a plain array.
 *
 * Everything a review would want to check about the transport is decided here,
 * in one place, without a request being made: which protocols the handle will
 * speak, that it verifies the peer and the host name, that it does not follow a
 * redirect, how long it may spend connecting and in total, and that the only
 * thing carrying the credential is a header. The suite reads this array
 * directly, so those facts are asserted rather than inferred from a response.
 *
 * The size cap is not here. It is a write callback, which needs somewhere to
 * count into, so mcm_tmdb_transport() installs it around this array.
 *
 * @param string $url
 * @param string $token
 * @param array  $limits          as mcm_tmdb_limits() returns
 * @param bool   $allow_plaintext true only for the loopback endpoint the suite uses
 * @return array
 */
function mcm_tmdb_transport_options($url, $token, array $limits, $allow_plaintext = false)
{
	$options = array(
		CURLOPT_URL            => $url,
		CURLOPT_HTTPGET        => true,
		// The credential travels here and nowhere else.
		CURLOPT_HTTPHEADER     => array(
			'Authorization: Bearer ' . $token,
			'Accept: application/json',
		),
		CURLOPT_USERAGENT      => 'mcm-tmdb-client/1',
		// A redirect is a response, not an instruction: following one would let
		// the endpoint move this request - and its Authorization header -
		// somewhere this site never named.
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_MAXREDIRS      => 0,
		CURLOPT_SSL_VERIFYPEER => true,
		// 2, not true: on this option a boolean is the weaker setting.
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_CONNECTTIMEOUT_MS => $limits['connect_ms'],
		CURLOPT_TIMEOUT_MS        => $limits['total_ms'],
		// Millisecond timeouts need the resolver kept off the alarm signal.
		CURLOPT_NOSIGNAL       => true,
		CURLOPT_HEADER         => false,
		// Nothing about this request may end up in the process's output.
		CURLOPT_VERBOSE        => false,
	);

	// The handle is told which protocols exist for it at all, so a Location
	// header or a redirected URL cannot reach file:, ftp: or anything else.
	if (defined('CURLPROTO_HTTPS')) {
		$protocols = CURLPROTO_HTTPS | ($allow_plaintext ? CURLPROTO_HTTP : 0);
		$options[CURLOPT_PROTOCOLS]       = $protocols;
		$options[CURLOPT_REDIR_PROTOCOLS] = $protocols;
	}
	if (defined('CURL_SSLVERSION_TLSv1_2')) {
		$options[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
	}
	return $options;
}

/**
 * Make one request and report what came back, without judging it.
 *
 * The size cap lives in the write callback: each chunk is measured before it is
 * kept, and the first one that would cross the cap is refused instead, which
 * ends the transfer. The bytes over the cap are therefore never buffered, and
 * neither is the rest of a response that would have gone on for megabytes.
 *
 * @param string $url
 * @param string $token
 * @param array  $limits
 * @param bool   $allow_plaintext
 * @return array errno, status, body, over
 */
function mcm_tmdb_transport($url, $token, array $limits, $allow_plaintext = false)
{
	$state  = array('body' => '', 'size' => 0, 'over' => false);
	$cap    = $limits['max_bytes'];
	$handle = curl_init();

	curl_setopt_array($handle, mcm_tmdb_transport_options($url, $token, $limits, $allow_plaintext));
	curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($handle, $chunk) use (&$state, $cap) {
		$length = strlen($chunk);
		if ($state['size'] + $length > $cap) {
			// Returning a length cURL did not write ends the transfer, and this
			// chunk is dropped rather than added to what is already held.
			$state['over'] = true;
			return 0;
		}
		$state['size'] += $length;
		$state['body'] .= $chunk;
		return $length;
	});

	curl_exec($handle);
	$result = array(
		'errno'  => curl_errno($handle),
		'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
		'body'   => $state['body'],
		'over'   => $state['over'],
	);
	// The handle held the credential, so it does not outlive the request. On
	// PHP 8 it is an object and this is what drops the last reference to it.
	curl_close($handle);
	unset($handle);

	return $result;
}

/**
 * Ask TMDb for one thing.
 *
 * @param string $path  an absolute path under the configured origin, e.g. "/movie/550"
 * @param array  $query query parameters, values unencoded
 * @return array array('ok' => true, 'status' => int, 'data' => array) on success,
 *               or a bounded failure from mcm_tmdb_failure()
 */
function mcm_tmdb_get($path, array $query = array())
{
	if (!function_exists('curl_init')) {
		return mcm_tmdb_failure('configuration', 'this PHP has no cURL extension');
	}

	// Everything that can be settled without sending anything is settled first,
	// so a misconfigured site makes no request at all.
	$base  = mcm_tmdb_base_url();
	$token = mcm_tmdb_token();
	foreach (array(mcm_tmdb_endpoint_error($base), mcm_tmdb_token_error($token), mcm_tmdb_path_error($path), mcm_tmdb_query_error($query)) as $error) {
		if ($error !== '') {
			return mcm_tmdb_failure('configuration', $error);
		}
	}

	$scheme          = strtolower((string) parse_url($base, PHP_URL_SCHEME));
	$allow_plaintext = ($scheme !== 'https');
	$limits          = mcm_tmdb_limits();
	$response        = mcm_tmdb_transport(mcm_tmdb_url($base, $path, $query), $token, $limits, $allow_plaintext);

	// The path is safe to log and worth having: it is written by this
	// application and holds no credential. The URL, which would name the
	// endpoint, and cURL's message, which can quote both, are not logged.
	if ($response['over']) {
		return mcm_tmdb_failure('too_large', 'response exceeded ' . $limits['max_bytes'] . ' bytes for ' . $path);
	}
	if ($response['errno'] !== 0) {
		$timed_out = ($response['errno'] === CURLE_OPERATION_TIMEOUTED);
		return mcm_tmdb_failure(
			$timed_out ? 'timeout' : 'unavailable',
			'curl error ' . $response['errno'] . ' for ' . $path
		);
	}
	if ($response['status'] < 200 || $response['status'] > 299) {
		return mcm_tmdb_failure('upstream', 'status ' . $response['status'] . ' for ' . $path, $response['status']);
	}

	$data = json_decode($response['body'], true);
	if (!is_array($data)) {
		return mcm_tmdb_failure('malformed', 'the response body is not a JSON object for ' . $path);
	}

	return array('ok' => true, 'status' => $response['status'], 'data' => $data);
}
