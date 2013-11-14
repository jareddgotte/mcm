<?php
	require_once('inc/config.php');
	require_once('inc/TMDb.inc'); // https://github.com/glamorous/TMDb-PHP-API
	session_start();

	
	if (!isset($_SESSION['tmdb_obj'])) {
		$_SESSION['tmdb_obj'] = new TMDb(TMDB_API_KEY);
	}

	$_SESSION['token'] = $_SESSION['tmdb_obj']->getAuthToken();
	
	header("Location: " . $_SESSION['token']['Authentication-Callback']);
	
?>