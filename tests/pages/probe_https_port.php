<?php

// The second HTTPS signal the bootstrap trusts: the request arrived on the
// standard TLS port.
$_SERVER['SERVER_PORT'] = 443;

require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_report.php');

mcm_probe_report();
