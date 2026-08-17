<?php

// Reads a remember-me cookie handed in through the environment and reports
// whether the site still accepts it. tests/cases.php supplies a cookie built
// the way the site built them before any of this changed, hashing it itself
// rather than through the code under test.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_report.php');

header('Content-Type: text/plain; charset=utf-8');

$cookie = getenv('MCM_TEST_COOKIE');
$cookie = ($cookie === false) ? '' : $cookie;
$parts  = mcm_remember_me_cookie_parts($cookie);

// A cookie issued now, to compare the shape of against the one handed in.
$fresh_token  = mcm_random_token(64);
$fresh_cookie = mcm_remember_me_cookie_value('7', $fresh_token);
$fresh_parts  = mcm_remember_me_cookie_parts($fresh_cookie);

// Two ways of tampering with that fresh cookie: change the hash, and change the
// token while leaving the hash alone.
$tampered_hash  = substr($fresh_cookie, 0, -1) . (substr($fresh_cookie, -1) === 'a' ? 'b' : 'a');
$tampered_token = '7:' . strrev($fresh_token) . ':' . mcm_remember_me_hash('7', $fresh_token);

$report = array(
	'valid'   => mcm_probe_flag($parts !== false),
	'user_id' => ($parts === false) ? '' : $parts['user_id'],
	'token'   => ($parts === false) ? '' : $parts['token'],

	'fresh_cookie'    => $fresh_cookie,
	'fresh_token'     => $fresh_token,
	'fresh_valid'     => mcm_probe_flag($fresh_parts !== false),
	'fresh_roundtrip' => mcm_probe_flag($fresh_parts !== false && $fresh_parts['user_id'] === '7' && $fresh_parts['token'] === $fresh_token),

	'tampered_hash_valid'  => mcm_probe_flag(mcm_remember_me_cookie_parts($tampered_hash) !== false),
	'tampered_token_valid' => mcm_probe_flag(mcm_remember_me_cookie_parts($tampered_token) !== false),
	'malformed_valid'      => mcm_probe_flag(mcm_remember_me_cookie_parts('nonsense') !== false),
	'two_part_valid'       => mcm_probe_flag(mcm_remember_me_cookie_parts('7:' . $fresh_token) !== false),
	// A correctly hashed cookie with no token at all must still be refused.
	'empty_token_valid'    => mcm_probe_flag(mcm_remember_me_cookie_parts('7::' . mcm_remember_me_hash('7', '')) !== false),
);

foreach ($report as $name => $value) {
	echo $name . '=' . $value . "\n";
}
