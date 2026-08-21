<?php

require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/tmdb_proxy.php');
require_once(__DIR__ . '/inc/php-login.php');

$login = new Login();

if ($login->isUserLoggedIn() === true) {
	include(__DIR__ . '/inc/views/logged_in.php');
} else {
	include(__DIR__ . '/inc/views/login.php');
}
