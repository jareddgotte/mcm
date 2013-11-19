<?php

require_once('inc/php-login.php');
//session_start();
$errors = array();

// Kill the script if someone got here improperly
$from_list = (isset($_POST['from_list'])) ? $_POST['from_list'] : ((isset($_GET['from_list'])) ? $_GET['from_list'] : '');
$to_list = (isset($_POST['to_list'])) ? $_POST['to_list'] : ((isset($_GET['to_list'])) ? $_GET['to_list'] : '');
$movie_id = (isset($_POST['movie_id'])) ? $_POST['movie_id'] : ((isset($_GET['movie_id'])) ? $_GET['movie_id'] : '');

if ($from_list === '') { echo 'Error: No from list id given.'; exit(); }
if ($to_list === '') { echo 'Error: No to list id given.'; exit(); }
if ($movie_id === '') { echo 'Error: No movie id given.'; exit(); }

//printf("f[%s] t[%s] m[%s]\n", $from_list, $to_list, $movie_id);
//echo "trying to connect to db<br>\n";
try {
	$db_connection = new PDO('mysql:host='. DB_HOST .';dbname='. DB_NAME, DB_USER, DB_PASS);
} catch (PDOException $e) {
	$db_connection = false;
	$errors[] = 'Database error' . $e->getMessage();
}

//echo "selecting the movie id<br>\n";
$query = $db_connection->prepare('SELECT id FROM movies WHERE movie_list_id = :from_list AND tmdb_movie_id = :movie_id');
$query->bindValue(':from_list', $from_list, PDO::PARAM_INT);
$query->bindValue(':movie_id', $movie_id, PDO::PARAM_INT);
if ($query->execute() === FALSE) {
	$errorInfo = $query->errorInfo();
	$errors[] = sprintf("Execute error: %s<br>\n", $errorInfo[2]);
}
$rows = $query->fetchAll(PDO::FETCH_OBJ);
if (count($rows) > 0) {
	//echo "moving movie<br>\n";
	$row = $rows[0];
	//echo 'row id: ' + $row->id + "<br>\n";
	$query = $db_connection->prepare('UPDATE movies SET movie_list_id = :to_list WHERE id = :id');
	$query->bindValue(':to_list', $to_list, PDO::PARAM_INT);
	$query->bindValue(':id', $row->id, PDO::PARAM_INT);
	if ($query->execute() === FALSE) {
		$errorInfo = $query->errorInfo();
		$errors[] = sprintf("Execute error: %s<br>\n", $errorInfo[2]);
	}
}
//echo "done<br>\n";

if (isset($errors)) if (count($errors) > 0) var_dump($errors);
