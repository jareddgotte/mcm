<?php

require_once(__DIR__ . '/inc/bootstrap.php');
require_once("inc/php-login.php");

$login = new Login();

if ($login->isUserLoggedIn() === true) {
	include("inc/views/logged_in.php");
} else {
	include("inc/views/login.php");
}
