<?php

// The transition attempted with no session open at all. Like the late attempt,
// this cannot renew anything; what matters is that it says so to the log and
// lets the request finish.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_report.php');

header('Content-Type: text/plain; charset=utf-8');

session_write_close();

$renewed = mcm_session_regenerate_id();

echo 'regenerated=' . mcm_probe_flag($renewed) . "\n";
echo "request_completed=yes\n";
