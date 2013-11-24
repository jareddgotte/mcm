<?php

require_once('inc/php-login.php');
session_start();
$errors = array();

// Kill the script if someone got here improperly
$movie_list_id = (isset($_POST['movie_list_id'])) ? $_POST['movie_list_id'] : ((isset($_GET['movie_list_id'])) ? $_GET['movie_list_id'] : '');
$list_name = (isset($_POST['list_name'])) ? $_POST['list_name'] : ((isset($_GET['list_name'])) ? $_GET['list_name'] : '');

if ($movie_list_id === '') { echo 'Error: No movie list id given.'; exit(); }
if ($list_name === '') { echo 'Error: No movie id given.'; exit(); }
// Optional:
//if ($tmdb_original_title === '') { echo 'Error: No movie original title given.'; exit(); }
//if ($tmdb_poster_path === '') { echo 'Error: No movie poster path given.'; exit(); }

//printf("c[%s] m[%s]\n", $current_list, $movie_id);
//echo "trying to connect to db<br>\n";
try {
	$db_connection = new PDO('mysql:host='. DB_HOST .';dbname='. DB_NAME, DB_USER, DB_PASS);
} catch (PDOException $e) {
	$db_connection = false;
	$errors[] = 'Database error' . $e->getMessage();
}

// rename list
//echo "renaming list<br>\n";
$query = $db_connection->prepare('UPDATE movie_lists SET list_name = :list_name WHERE movie_list_id = :movie_list_id');
$query->bindValue(':list_name', $list_name, PDO::PARAM_STR);
$query->bindValue(':movie_list_id', $movie_list_id, PDO::PARAM_INT);
if ($query->execute() === FALSE) {
	$errorInfo = $query->errorInfo();
	$errors[] = sprintf("Execute error: %s<br>\n", $errorInfo[2]);
}
//echo "done<br>\n";

if (isset($errors)) if (count($errors) > 0) var_dump($errors);
else {
	// Update our db var
	echo 'greatsuccess';
}
