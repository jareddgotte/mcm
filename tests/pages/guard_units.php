<?php

// The guard helpers that answer without touching the request: identifier
// validation, the constant-time comparison, the log-safe rendering of client
// input, and the fixed refusal bodies. Driven from the command line, where
// there is no request method at all.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');
require_once(__DIR__ . '/_guard_db.php');

$report = array();

/* Identifiers. */
$report['positive_int_int']      = var_export(mcm_positive_int(7), true);
$report['positive_int_string']   = var_export(mcm_positive_int('7'), true);
$report['positive_int_padded']   = var_export(mcm_positive_int('007'), true);
$report['positive_int_zero']     = var_export(mcm_positive_int(0), true);
$report['positive_int_negative'] = var_export(mcm_positive_int(-7), true);
$report['positive_int_empty']    = var_export(mcm_positive_int(''), true);
$report['positive_int_trailing'] = var_export(mcm_positive_int('7abc'), true);
$report['positive_int_sql']      = var_export(mcm_positive_int('11 OR 1=1'), true);
$report['positive_int_spaced']   = var_export(mcm_positive_int(' 7'), true);
$report['positive_int_float']    = var_export(mcm_positive_int(7.5), true);
$report['positive_int_bool']     = var_export(mcm_positive_int(true), true);
$report['positive_int_null']     = var_export(mcm_positive_int(null), true);
$report['positive_int_array']    = var_export(mcm_positive_int(array(7)), true);

/* The constant-time comparison, on its own. */
$token = str_repeat('a', 64);
$report['equals_same']        = mcm_guard_bool(mcm_constant_time_equals($token, $token));
$report['equals_first_byte']  = mcm_guard_bool(mcm_constant_time_equals($token, 'b' . substr($token, 1)));
$report['equals_last_byte']   = mcm_guard_bool(mcm_constant_time_equals($token, substr($token, 0, 63) . 'b'));
$report['equals_prefix']      = mcm_guard_bool(mcm_constant_time_equals($token, substr($token, 0, 63)));
$report['equals_longer']      = mcm_guard_bool(mcm_constant_time_equals($token, $token . 'a'));
$report['equals_both_empty']  = mcm_guard_bool(mcm_constant_time_equals('', ''));
$report['equals_known_empty'] = mcm_guard_bool(mcm_constant_time_equals('', $token));
$report['equals_given_empty'] = mcm_guard_bool(mcm_constant_time_equals($token, ''));
$report['equals_non_string']  = mcm_guard_bool(mcm_constant_time_equals($token, array()));
$report['hash_equals_same']   = mcm_guard_bool(mcm_hash_equals($token, $token));
$report['hash_equals_differs'] = mcm_guard_bool(mcm_hash_equals($token, substr($token, 0, 63) . 'b'));
$report['hash_equals_non_string'] = mcm_guard_bool(mcm_hash_equals($token, array()));

/* Tokens. */
$first  = mcm_random_token();
$second = mcm_random_token();
$report['token_length'] = strlen($first);
$report['token_hex']    = mcm_guard_bool(preg_match('/^[0-9a-f]{64}$/', $first) === 1);
$report['token_unique'] = mcm_guard_bool($first !== $second);
$report['token_floor']  = strlen(mcm_random_token(4));

/* Request method, which the command line does not have. */
$report['method']  = mcm_request_method();
$report['is_post'] = mcm_guard_bool(mcm_request_is_post());

/* Client input on its way to a log line. A log line that says only what type a
 * value was is not a diagnostic, so each rendering below has to carry the value
 * itself - without any of the dumping functions the application is forbidden to
 * call. */
$report['log_detail_plain']     = mcm_log_detail('list 11');
$report['log_detail_control']   = mcm_log_detail("first\nsecond\r\tthird");
$report['log_detail_length']    = strlen(mcm_log_detail(str_repeat('x', 500)));
$report['log_detail_true']      = mcm_log_detail(true);
$report['log_detail_false']     = mcm_log_detail(false);
$report['log_detail_null']      = mcm_log_detail(null);
$report['log_detail_int']       = mcm_log_detail(11);
$report['log_detail_float']     = mcm_log_detail(1.5);
$report['log_detail_non_string'] = mcm_log_detail(array(1, 2));

/* Refusal bodies. */
foreach (array(400, 401, 403, 405) as $status) {
	$report['body_' . $status] = mcm_json_error_body($status);
}
$report['status_unknown'] = mcm_json_error_status(599);
$report['status_known']   = mcm_json_error_status(403);
$report['status_string']  = mcm_json_error_status('403');
$report['body_unknown']   = mcm_json_error_body(599);

foreach ($report as $name => $value) {
	echo $name . '=' . $value . "\n";
}
