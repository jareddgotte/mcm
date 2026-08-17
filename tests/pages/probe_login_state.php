<?php

// Builds a real Login object and reports what it decided.
//
// The signed-in views cannot stand in for this: inc/views/logged_in.php builds
// a TMDb client and calls the API over the network, which a test must not do.
// Everything up to the view is the application's own code - the same
// bootstrap, the same inc/php-login.php, the same Login constructor and the
// same queries - so a cookie login exercised here is the one the site performs.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/php-login.php');
require_once(__DIR__ . '/_report.php');

header('Content-Type: text/plain; charset=utf-8');

$session_id_before = session_id();

$login = new Login();

echo 'logged_in=' . mcm_probe_flag($login->isUserLoggedIn()) . "\n";
echo 'user_name=' . $login->getUsername() . "\n";
echo 'session_id_before=' . $session_id_before . "\n";
echo 'errors=' . implode(' | ', $login->errors) . "\n";
echo 'messages=' . implode(' | ', $login->messages) . "\n";
echo "request_completed=yes\n";

mcm_probe_report();
