<?php

// mcm_require_login(): anything past the guard means it allowed the request.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');

mcm_require_login();

echo 'reached user=' . mcm_current_user_name() . "\n";
