<?php

// Stands in for an authentication transition: session data is written and the
// session identifier is renewed, exactly as inc/classes/Login.php does after a
// successful login, a login from a remember-me cookie, and a password change.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/_report.php');

$before = session_id();

$_SESSION['user_id']        = 7;
$_SESSION['user_name']      = 'transitioning-user';
$_SESSION['user_email']     = 'transitioning-user@example.test';
$_SESSION['user_logged_in'] = 1;

$renewed = mcm_session_regenerate_id();

header('Content-Type: text/plain; charset=utf-8');
echo 'session_id_before=' . $before . "\n";
echo 'regenerated=' . mcm_probe_flag($renewed) . "\n";

mcm_probe_report();
