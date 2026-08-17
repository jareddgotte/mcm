<?php

require_once(__DIR__ . '/inc/bootstrap.php');
require_once("inc/php-login.php");

$login = new Login();

if (isset($_GET['id'])) {
	$user_id = $_GET['id'];
	include("inc/views/share.php");
} else {
	// A share link with no list on it goes to the directory this page lives in,
	// as it always has. The path comes from the server's own view of which
	// script ran, and the host from the configuration, so the request cannot
	// choose where the visitor ends up.
	mcm_redirect(dirname($_SERVER['SCRIPT_NAME']));
}