<?php

// mcm_require_list_owner(): anything past the guard means it allowed the
// request, and the identifier it handed back is the validated one.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');
require_once(__DIR__ . '/_guard_db.php');

$db = mcm_guard_test_db();
if ($db === null) {
	echo "sqlite=missing\n";
	return;
}

$movie_list_id = isset($_POST['movie_list_id']) ? $_POST['movie_list_id'] : '';
$list_id       = mcm_require_list_owner($db, $movie_list_id);

echo 'reached list=' . $list_id . "\n";
