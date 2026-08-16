<?php

// The same failure, suppressed with "@": nothing is logged and the request
// carries on.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_seed.php');

$handle = @fopen(__DIR__ . '/suppressed_' . MCM_TEST_SEED . '.txt', 'r');

echo "request completed\n";
