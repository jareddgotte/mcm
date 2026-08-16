<?php

// The same probe, for a request the web server terminated TLS for. Real TLS is
// out of scope, so the one signal the bootstrap trusts is set here instead: the
// HTTPS server variable, exactly as the web server would have set it.
$_SERVER['HTTPS'] = 'on';

require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_report.php');

mcm_probe_report();
