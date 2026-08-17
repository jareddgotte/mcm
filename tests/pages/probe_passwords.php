<?php

// Checks a password against a stored hash handed in through the environment,
// and reports whether that hash would be recalculated on a successful login.
// tests/cases.php supplies hashes that were written by earlier versions of the
// site.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_report.php');

header('Content-Type: text/plain; charset=utf-8');

$password = getenv('MCM_TEST_PASSWORD');
$stored   = getenv('MCM_TEST_HASH');
$password = ($password === false) ? '' : $password;
$stored   = ($stored === false) ? '' : $stored;

// What a successful login does: verify, then rehash only if the stored hash was
// calculated with weaker settings than the ones in use now.
$verified     = mcm_password_verify($password, $stored);
$needs_rehash = mcm_password_needs_rehash($stored);
$recalculated = mcm_password_hash($password);

$report = array(
	'options'             => json_encode(mcm_password_options()),
	'stored_hash'         => $stored,
	'verify'              => mcm_probe_flag($verified),
	'verify_wrong'        => mcm_probe_flag(mcm_password_verify($password . '-not-it', $stored)),
	'verify_empty_hash'   => mcm_probe_flag(mcm_password_verify($password, '')),
	'verify_garbage_hash' => mcm_probe_flag(mcm_password_verify($password, 'not-a-hash')),
	'needs_rehash'        => mcm_probe_flag($needs_rehash),
	'needs_rehash_empty'  => mcm_probe_flag(mcm_password_needs_rehash('')),
	'recalculated'        => $recalculated,
	'recalculated_verify' => mcm_probe_flag(mcm_password_verify($password, $recalculated)),
	'recalculated_wrong'  => mcm_probe_flag(mcm_password_verify($password . '-not-it', $recalculated)),
	// The recalculated hash has to settle: a login must not rewrite the row
	// every single time.
	'recalculated_needs_rehash' => mcm_probe_flag(mcm_password_needs_rehash($recalculated)),
	'recalculated_differs'      => mcm_probe_flag($recalculated !== $stored),
	// The old hash is still the one in the database until the update lands, so
	// it has to keep verifying while that is true.
	'stored_still_verifies'     => mcm_probe_flag(mcm_password_verify($password, $stored)),
);

foreach ($report as $name => $value) {
	echo $name . '=' . $value . "\n";
}
