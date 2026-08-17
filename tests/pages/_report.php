<?php

/**
 * Shared reporting helper for the probe pages.
 *
 * Prints one "key=value" line per fact the suite wants to look at. Never
 * included before the bootstrap: a probe page is a stand-in for a real entry
 * point, and a real entry point loads the bootstrap first.
 */

/**
 * Format a constant for the report.
 *
 * @param mixed $value
 * @return string
 */
function mcm_probe_value($value)
{
	if ($value === null) {
		return 'null';
	}
	if ($value === true) {
		return 'true';
	}
	if ($value === false) {
		return 'false';
	}

	return (string) $value;
}

/**
 * Print the report.
 */
function mcm_probe_report()
{
	if (!headers_sent()) {
		header('Content-Type: text/plain; charset=utf-8');
	}

	$report = array(
		'php_version'    => PHP_VERSION,
		'bootstrap'      => defined('MCM_BOOTSTRAP') ? 'defined' : 'missing',
		'session_status' => session_status(),
		'session_id'     => session_id(),
		'session_name'   => session_name(),
		'session_json'   => json_encode($_SESSION, JSON_UNESCAPED_SLASHES),
		'cookies_json'   => json_encode($_COOKIE, JSON_UNESCAPED_SLASHES),
		'headers_json'   => json_encode(headers_list()),
	);

	$params = session_get_cookie_params();
	foreach ($params as $name => $value) {
		$report['cookie_param_' . $name] = mcm_probe_value($value);
	}

	// Only the bootstrap's own settings are reported. Credentials from the
	// configuration are deliberately never printed, not even in a fixture.
	foreach (get_defined_constants() as $name => $value) {
		if (strpos($name, 'MCM_') === 0) {
			$report['const_' . $name] = mcm_probe_value($value);
		}
	}
	$report['const_COOKIE_RUNTIME'] = defined('COOKIE_RUNTIME') ? mcm_probe_value(COOKIE_RUNTIME) : 'undefined';

	foreach ($report as $name => $value) {
		echo $name . '=' . $value . "\n";
	}
}
