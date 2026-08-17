<?php

// What the guards make of the session they were handed: is anybody signed in,
// and if so, who.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');
require_once(__DIR__ . '/_guard_db.php');

if (!headers_sent()) {
	header('Content-Type: text/plain; charset=utf-8');
}

echo 'logged_in=' . mcm_guard_bool(mcm_is_logged_in()) . "\n";
echo 'user_id=' . var_export(mcm_current_user_id(), true) . "\n";
echo 'user_name=' . var_export(mcm_current_user_name(), true) . "\n";
