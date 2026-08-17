<?php

// A level the request cannot carry on from, raised through the error handler.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_seed.php');

trigger_error('unrecoverable state ' . MCM_TEST_SEED, E_USER_ERROR);

echo "this line is never reached\n";
