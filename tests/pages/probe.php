<?php

// Stand-in for a public entry point: it loads the shared bootstrap first and
// then reports what the bootstrap decided.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_report.php');

mcm_probe_report();
