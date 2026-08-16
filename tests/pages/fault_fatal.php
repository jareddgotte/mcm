<?php

// A true compile-time fatal: the required file does not parse, so the failure
// never reaches the error handler and only the shutdown handler can see it. The
// file name carries the seeded secret, so the message proves where detail went.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_seed.php');

require(__DIR__ . '/broken_' . MCM_TEST_SEED . '.php');

echo "this line is never reached\n";
