<?php

/**
 * Drives inc/tmdb.php and reports what it did.
 *
 * A page rather than a unit test because that is how the rest of this suite
 * reaches application code: the bootstrap loads the fixture's configuration,
 * the client reads it, and the request that goes out is a real one to the stub
 * on the loopback interface. MCM_TMDB_MODE picks what to report; the assertions
 * live in tests/cases.php.
 */
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/tmdb.php');
require_once(__DIR__ . '/_report.php');

header('Content-Type: text/plain; charset=utf-8');

$mcm_report = array();

/** Record one fact for the report. */
function mcm_tmdb_fact($name, $value)
{
	$GLOBALS['mcm_report'][$name] = mcm_probe_value($value);
}

/** Record a policy answer as "ok" or the reason it was refused. */
function mcm_tmdb_policy($name, $reason)
{
	mcm_tmdb_fact($name, ($reason === '') ? 'ok' : 'refused');
}

/** Record a client result: the shape, and every bounded field it carries. */
function mcm_tmdb_result($name, array $result)
{
	mcm_tmdb_fact($name . '_ok', mcm_probe_flag(!empty($result['ok'])));
	mcm_tmdb_fact($name . '_keys', implode(',', array_keys($result)));
	mcm_tmdb_fact($name . '_status', isset($result['status']) ? $result['status'] : '');
	mcm_tmdb_fact($name . '_category', isset($result['category']) ? $result['category'] : '');
	mcm_tmdb_fact($name . '_message', isset($result['message']) ? $result['message'] : '');
}

$mcm_mode = getenv('MCM_TMDB_MODE');
if ($mcm_mode === false || $mcm_mode === '') {
	$mcm_mode = 'live';
}

if ($mcm_mode === 'policy') {
	// Pure decisions, made without sending anything.
	mcm_tmdb_policy('endpoint_configured', mcm_tmdb_endpoint_error(mcm_tmdb_base_url()));
	mcm_tmdb_policy('endpoint_https', mcm_tmdb_endpoint_error('https://api.themoviedb.org/3'));
	mcm_tmdb_policy('endpoint_http_remote', mcm_tmdb_endpoint_error('http://api.themoviedb.org/3'));
	mcm_tmdb_policy('endpoint_http_loopback', mcm_tmdb_endpoint_error('http://127.0.0.1:8080/3'));
	mcm_tmdb_policy('endpoint_http_localhost', mcm_tmdb_endpoint_error('http://localhost:8080/3'));
	mcm_tmdb_policy('endpoint_http_ipv6_loopback', mcm_tmdb_endpoint_error('http://[::1]:8080/3'));
	// A host that merely starts with the loopback name is a different host.
	mcm_tmdb_policy('endpoint_http_lookalike', mcm_tmdb_endpoint_error('http://localhost.example.com/3'));
	mcm_tmdb_policy('endpoint_ftp', mcm_tmdb_endpoint_error('ftp://api.themoviedb.org/3'));
	mcm_tmdb_policy('endpoint_file', mcm_tmdb_endpoint_error('file:///etc/passwd'));
	mcm_tmdb_policy('endpoint_relative', mcm_tmdb_endpoint_error('/3'));
	mcm_tmdb_policy('endpoint_empty', mcm_tmdb_endpoint_error(''));
	mcm_tmdb_policy('endpoint_userinfo', mcm_tmdb_endpoint_error('https://user:secret@api.themoviedb.org/3'));
	mcm_tmdb_policy('endpoint_query', mcm_tmdb_endpoint_error('https://api.themoviedb.org/3?api_key=abc'));

	mcm_tmdb_policy('path_plain', mcm_tmdb_path_error('/movie/550'));
	mcm_tmdb_policy('path_dotted', mcm_tmdb_path_error('/movie/550/videos'));
	mcm_tmdb_policy('path_empty', mcm_tmdb_path_error(''));
	mcm_tmdb_policy('path_relative', mcm_tmdb_path_error('movie/550'));
	mcm_tmdb_policy('path_traversal', mcm_tmdb_path_error('/movie/../../etc/passwd'));
	mcm_tmdb_policy('path_absolute_url', mcm_tmdb_path_error('https://evil.example.com/movie'));
	mcm_tmdb_policy('path_with_query', mcm_tmdb_path_error('/movie/550?api_key=abc'));
	mcm_tmdb_policy('path_double_slash', mcm_tmdb_path_error('//evil.example.com/movie'));
	mcm_tmdb_policy('path_newline', mcm_tmdb_path_error("/movie/550\r\nX-Injected: 1"));

	mcm_tmdb_policy('query_plain', mcm_tmdb_query_error(array('language' => 'en', 'page' => 2)));
	mcm_tmdb_policy('query_api_key', mcm_tmdb_query_error(array('api_key' => 'abc')));
	mcm_tmdb_policy('query_api_key_cased', mcm_tmdb_query_error(array('API_KEY' => 'abc')));
	mcm_tmdb_policy('query_session_id', mcm_tmdb_query_error(array('session_id' => 'abc')));
	mcm_tmdb_policy('query_bad_name', mcm_tmdb_query_error(array('lang uage' => 'en')));
	mcm_tmdb_policy('query_array_value', mcm_tmdb_query_error(array('language' => array('en'))));

	mcm_tmdb_policy('token_configured', mcm_tmdb_token_error(mcm_tmdb_token()));
	mcm_tmdb_policy('token_empty', mcm_tmdb_token_error(''));
	mcm_tmdb_policy('token_crlf', mcm_tmdb_token_error("abc\r\nX-Injected: 1"));
	mcm_tmdb_policy('token_space', mcm_tmdb_token_error('abc def'));
	mcm_tmdb_policy('token_tab', mcm_tmdb_token_error("abc\tdef"));

	$mcm_limits = mcm_tmdb_limits();
	mcm_tmdb_fact('limit_connect_ms', $mcm_limits['connect_ms']);
	mcm_tmdb_fact('limit_total_ms', $mcm_limits['total_ms']);
	mcm_tmdb_fact('limit_max_bytes', $mcm_limits['max_bytes']);
	mcm_tmdb_fact('url_built', mcm_tmdb_url('https://api.themoviedb.org/3', '/movie/550', array('language' => 'en gb')));
	mcm_tmdb_fact('url_no_query', mcm_tmdb_url('https://api.themoviedb.org/3', '/movie/550', array()));
} elseif ($mcm_mode === 'options') {
	// The transport's own settings, read out of the array the request is made
	// with rather than inferred from a response.
	$mcm_token   = mcm_tmdb_token();
	$mcm_limits  = mcm_tmdb_limits();
	$mcm_options = mcm_tmdb_transport_options('https://api.themoviedb.org/3/movie/550', $mcm_token, $mcm_limits);

	mcm_tmdb_fact('opt_url', $mcm_options[CURLOPT_URL]);
	mcm_tmdb_fact('opt_headers', implode(' | ', $mcm_options[CURLOPT_HTTPHEADER]));
	mcm_tmdb_fact('opt_followlocation', mcm_probe_value($mcm_options[CURLOPT_FOLLOWLOCATION]));
	mcm_tmdb_fact('opt_maxredirs', $mcm_options[CURLOPT_MAXREDIRS]);
	mcm_tmdb_fact('opt_verifypeer', mcm_probe_value($mcm_options[CURLOPT_SSL_VERIFYPEER]));
	mcm_tmdb_fact('opt_verifyhost', $mcm_options[CURLOPT_SSL_VERIFYHOST]);
	mcm_tmdb_fact('opt_connect_ms', $mcm_options[CURLOPT_CONNECTTIMEOUT_MS]);
	mcm_tmdb_fact('opt_total_ms', $mcm_options[CURLOPT_TIMEOUT_MS]);
	mcm_tmdb_fact('opt_httpget', mcm_probe_value($mcm_options[CURLOPT_HTTPGET]));
	mcm_tmdb_fact('opt_protocols_https_only', mcm_probe_flag($mcm_options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS));
	mcm_tmdb_fact('opt_redir_https_only', mcm_probe_flag($mcm_options[CURLOPT_REDIR_PROTOCOLS] === CURLPROTO_HTTPS));
	mcm_tmdb_fact('opt_has_http', mcm_probe_flag(($mcm_options[CURLOPT_PROTOCOLS] & CURLPROTO_HTTP) !== 0));

	// The one place the credential is allowed to be, and the proof it is
	// nowhere else in what the handle is given.
	$mcm_elsewhere = $mcm_options;
	unset($mcm_elsewhere[CURLOPT_HTTPHEADER]);
	mcm_tmdb_fact('opt_url_has_token', mcm_probe_flag(strpos($mcm_options[CURLOPT_URL], $mcm_token) !== false));
	mcm_tmdb_fact('opt_elsewhere_has_token', mcm_probe_flag(strpos(json_encode($mcm_elsewhere), $mcm_token) !== false));

	// The loopback variant, which is the only one that may speak plain HTTP.
	$mcm_loopback = mcm_tmdb_transport_options('http://127.0.0.1:9/x', $mcm_token, $mcm_limits, true);
	mcm_tmdb_fact('loopback_has_http', mcm_probe_flag(($mcm_loopback[CURLOPT_PROTOCOLS] & CURLPROTO_HTTP) !== 0));
	mcm_tmdb_fact('loopback_followlocation', mcm_probe_value($mcm_loopback[CURLOPT_FOLLOWLOCATION]));
	mcm_tmdb_fact('loopback_verifypeer', mcm_probe_value($mcm_loopback[CURLOPT_SSL_VERIFYPEER]));
} elseif ($mcm_mode === 'session') {
	// What a caching page would do: keep the answer, never the client.
	// Not /echo: that scenario reports the request's own Authorization header
	// back, so caching its answer would be caching the credential by way of the
	// endpoint. /configuration answers the way TMDb's own does.
	$mcm_result = mcm_tmdb_get('/configuration');
	$_SESSION['user_id']     = 7;
	$_SESSION['tmdb_config'] = isset($mcm_result['data']) ? $mcm_result['data'] : array();
	mcm_tmdb_result('session_request', $mcm_result);
	mcm_tmdb_fact('session_id', session_id());
	mcm_tmdb_fact('session_serialized_has_token', mcm_probe_flag(strpos(serialize($_SESSION), mcm_tmdb_token()) !== false));
	session_write_close();
} else {
	// Everything that needs the stub at the other end.
	$mcm_token = mcm_tmdb_token();

	$mcm_echo = mcm_tmdb_get('/echo', array('language' => 'en-GB'));
	mcm_tmdb_result('echo', $mcm_echo);
	$mcm_seen = isset($mcm_echo['data']) ? $mcm_echo['data'] : array();
	mcm_tmdb_fact('echo_authorization', isset($mcm_seen['authorization']) ? $mcm_seen['authorization'] : '');
	mcm_tmdb_fact('echo_accept', isset($mcm_seen['accept']) ? $mcm_seen['accept'] : '');
	mcm_tmdb_fact('echo_method', isset($mcm_seen['method']) ? $mcm_seen['method'] : '');
	mcm_tmdb_fact('echo_uri', isset($mcm_seen['uri']) ? $mcm_seen['uri'] : '');
	mcm_tmdb_fact('echo_uri_has_token', mcm_probe_flag(isset($mcm_seen['uri']) && strpos($mcm_seen['uri'], $mcm_token) !== false));
	mcm_tmdb_fact('echo_query_language', isset($mcm_seen['query']['language']) ? $mcm_seen['query']['language'] : '');
	mcm_tmdb_fact('echo_marker', isset($mcm_seen['marker']) ? $mcm_seen['marker'] : '');

	mcm_tmdb_result('large', mcm_tmdb_get('/large', array('bytes' => '65536')));
	mcm_tmdb_result('redirect', mcm_tmdb_get('/redirect'));
	mcm_tmdb_result('upstream', mcm_tmdb_get('/status', array('code' => '500', 'marker' => 'upstream-body-marker')));
	mcm_tmdb_result('notfound', mcm_tmdb_get('/status', array('code' => '404', 'marker' => 'upstream-body-marker')));
	mcm_tmdb_result('notjson', mcm_tmdb_get('/notjson'));

	// Nothing is sent for any of these: the client refuses them itself.
	mcm_tmdb_result('bad_path', mcm_tmdb_get('movie/550'));
	mcm_tmdb_result('bad_query', mcm_tmdb_get('/echo', array('api_key' => 'abc')));

	// Last on purpose. The stub is one PHP built-in server, which serves one
	// request at a time: it goes on sleeping after the client has given up on
	// it, so anything asked for after this would be answered late through no
	// fault of the client's.
	$mcm_started = microtime(true);
	mcm_tmdb_result('slow', mcm_tmdb_get('/slow', array('delay' => '1.5')));
	mcm_tmdb_fact('slow_elapsed_ms', (int) round((microtime(true) - $mcm_started) * 1000));
}

foreach ($mcm_report as $mcm_name => $mcm_value) {
	echo $mcm_name . '=' . $mcm_value . "\n";
}
