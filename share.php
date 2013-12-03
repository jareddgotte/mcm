<?php

require_once("inc/php-login.php");

$login = new Login();

if (isset($_GET['id'])) {
	$user_id = $_GET['id'];
	include("inc/views/share.php");
} else {
	//echo $_SERVER['PHP_SELF'];
	$sub = explode('/', $_SERVER['PHP_SELF']);
	array_pop($sub);
	header('Location: http://'.$_SERVER['HTTP_HOST'].implode('/', $sub), true);
}