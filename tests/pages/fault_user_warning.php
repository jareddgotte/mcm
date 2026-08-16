<?php

// A user warning. Whether it is logged depends only on the configured
// error_reporting level, which is why two fixtures request this same page.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_seed.php');

trigger_error('filtered detail ' . MCM_TEST_SEED, E_USER_WARNING);

echo "request completed\n";
