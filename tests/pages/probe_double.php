<?php

// Loading the bootstrap a second time in one request has to be harmless. This
// is not a contrived case: every entry point loads the bootstrap and then loads
// inc/php-login.php, which loads the bootstrap again so that it stays usable on
// its own.
require_once(__DIR__ . '/inc/bootstrap.php');

$first = session_id();

require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/bootstrap.php');

require_once(__DIR__ . '/_report.php');

mcm_probe_report();

echo 'session_id_before_second_include=' . $first . "\n";
echo 'double_include_survived=yes' . "\n";
