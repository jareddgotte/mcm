<?php

// A warning: logged, but the request carries on exactly as it did before.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_seed.php');

$handle = fopen(__DIR__ . '/no_such_file_' . MCM_TEST_SEED . '.txt', 'r');

echo "request completed\n";
