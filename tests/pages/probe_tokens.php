<?php

// Reports what the shared token generator and the constant-time comparison
// produce. The assertions live in tests/cases.php.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_report.php');

header('Content-Type: text/plain; charset=utf-8');

$token = mcm_random_token(64);
$flipped = substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a');

$report = array(
	'token_a'       => mcm_random_token(64),
	'token_b'       => mcm_random_token(64),
	'token_c'       => mcm_random_token(64),
	'token_default' => mcm_random_token(),
	'token_40'      => mcm_random_token(40),
	// An odd length is rounded up, so a token always comes from whole bytes.
	'token_odd'     => mcm_random_token(7),
	'token_zero'    => mcm_random_token(0),
	'bytes_length'  => strlen(mcm_random_bytes(16)),

	'equals_same'      => mcm_probe_flag(mcm_hash_equals('a token value', 'a token value')),
	'equals_different' => mcm_probe_flag(mcm_hash_equals('a token value', 'a token valuf')),
	'equals_prefix'    => mcm_probe_flag(mcm_hash_equals('a token value', 'a token')),
	'equals_longer'    => mcm_probe_flag(mcm_hash_equals('a token', 'a token value')),
	'equals_empty'     => mcm_probe_flag(mcm_hash_equals('', '')),
	'equals_missing'   => mcm_probe_flag(mcm_hash_equals(null, 'a token value')),
	'equals_token'     => mcm_probe_flag(mcm_hash_equals($token, $token)),
	'equals_flipped'   => mcm_probe_flag(mcm_hash_equals($token, $flipped)),
);

foreach ($report as $name => $value) {
	echo $name . '=' . $value . "\n";
}
