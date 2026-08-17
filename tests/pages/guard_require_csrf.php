<?php

// mcm_require_csrf(): anything past the guard means it allowed the request.
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');

mcm_require_csrf();

echo "reached\n";
