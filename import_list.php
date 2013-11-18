<?php

// import TMDb list into my db

require_once('inc/php-login.php');
session_start();

$api_key = "1c36628b5c5648a1e1079924b98c0925";
$list_id = "5212934a760ee36af148407c";

$tmdb = new TMDb($api_key);

$WTSList = $tmdb->getList($list_id);

//foreach ($WTSList as $k => $v) printf("%s : %s\n", var_dump($k), var_dump($v));
//var_dump($WTSList);


try {
	$db_connection = new PDO('mysql:host=localhost;dbname=jaredgot_mcm', 'jaredgot_mcm', 'l0cxs5U1f0NhPkJRRkOI');
} catch (PDOException $e) {
	$db_connection = false;
	$errors[] = 'Database error' . $e->getMessage();
}

if ($db_connection != false) {
	var_dump($db_connection);
	$query_user = $db_connection->prepare('SELECT * FROM users');
	//$query_user->bindValue(':user_name', $user_name, PDO::PARAM_STR);
	$query_user->execute();
	// get result row (as an object)
	var_dump($query_user->fetchObject());
}
else var_dump($errors);
