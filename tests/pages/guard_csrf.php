<?php

// The CSRF token, as a page that hands one out sees it. Everything that reads
// the session happens before the token is minted, so the first request against
// a session shows what a session with no token of its own accepts: nothing.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');
require_once(__DIR__ . '/_guard_db.php');

if (!headers_sent()) {
	header('Content-Type: text/plain; charset=utf-8');
}

$submitted = mcm_submitted_csrf_token();

echo 'method=' . mcm_request_method() . "\n";
echo 'is_post=' . mcm_guard_bool(mcm_request_is_post()) . "\n";
echo 'session_token_before=' . (isset($_SESSION[MCM_CSRF_SESSION_KEY]) ? $_SESSION[MCM_CSRF_SESSION_KEY] : '(none)') . "\n";
echo 'submitted=' . ($submitted === '' ? '(none)' : $submitted) . "\n";
echo 'valid_submitted=' . mcm_guard_bool(mcm_csrf_token_is_valid($submitted)) . "\n";
echo 'valid_empty=' . mcm_guard_bool(mcm_csrf_token_is_valid('')) . "\n";
echo 'valid_stranger=' . mcm_guard_bool(mcm_csrf_token_is_valid(str_repeat('0', 64))) . "\n";
echo 'valid_array=' . mcm_guard_bool(mcm_csrf_token_is_valid(array())) . "\n";

// Minting is last, so that reading a session never creates a token the checks
// above could have matched.
echo 'token=' . mcm_csrf_token() . "\n";
echo 'token_again=' . mcm_csrf_token() . "\n";
