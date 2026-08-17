<?php

// A refusal, with the seeded secret as its private detail: the seed has to end
// up in the log and nowhere else, and nothing after the call may run.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');
require_once(__DIR__ . '/_seed.php');

$status = isset($_GET['status']) ? $_GET['status'] : 403;

mcm_json_error($status, 'refused for a reason only the log may carry: ' . MCM_TEST_SEED);

echo "this line is never reached\n";
