<?php

// mcm_user_owns_list() against a real prepared statement: list 11 is user 7's,
// list 12 is user 8's, and list 99 does not exist.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');
require_once(__DIR__ . '/_guard_db.php');

if (!headers_sent()) {
	header('Content-Type: text/plain; charset=utf-8');
}

$db = mcm_guard_test_db();
if ($db === null) {
	echo "sqlite=missing\n";
	return;
}

echo "sqlite=present\n";
echo 'own_list=' . mcm_guard_bool(mcm_user_owns_list($db, 11, 7)) . "\n";
echo 'own_list_as_strings=' . mcm_guard_bool(mcm_user_owns_list($db, '11', '7')) . "\n";
echo 'other_users_list=' . mcm_guard_bool(mcm_user_owns_list($db, 12, 7)) . "\n";
echo 'missing_list=' . mcm_guard_bool(mcm_user_owns_list($db, 99, 7)) . "\n";
echo 'injected_id=' . mcm_guard_bool(mcm_user_owns_list($db, '11 OR 1=1', 7)) . "\n";
echo 'zero_id=' . mcm_guard_bool(mcm_user_owns_list($db, 0, 7)) . "\n";
echo 'empty_id=' . mcm_guard_bool(mcm_user_owns_list($db, '', 7)) . "\n";
echo 'no_user=' . mcm_guard_bool(mcm_user_owns_list($db, 11, null)) . "\n";
echo 'zero_user=' . mcm_guard_bool(mcm_user_owns_list($db, 11, 0)) . "\n";
