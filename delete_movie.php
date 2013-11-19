<?php

require_once('inc/php-login.php');
session_start();
$errors = array();

// Kill the script if someone got here improperly
$movie_list_id = (isset($_POST['movie_list_id'])) ? $_POST['movie_list_id'] : ((isset($_GET['movie_list_id'])) ? $_GET['movie_list_id'] : '');
$tmdb_movie_id = (isset($_POST['tmdb_movie_id'])) ? $_POST['tmdb_movie_id'] : ((isset($_GET['tmdb_movie_id'])) ? $_GET['tmdb_movie_id'] : '');

if ($movie_list_id === '') { echo 'Error: No movie list id given.'; exit(); }
if ($tmdb_movie_id === '') { echo 'Error: No movie id given.'; exit(); }

//echo "trying to connect to db<br>\n";
try {
	$db_connection = new PDO('mysql:host='. DB_HOST .';dbname='. DB_NAME, DB_USER, DB_PASS);
} catch (PDOException $e) {
	$db_connection = false;
	$errors[] = 'Database error' . $e->getMessage();
}

// delete movie
//echo "deleting movie<br>\n";
$query = $db_connection->prepare('DELETE FROM movies WHERE movie_list_id = :movie_list_id AND tmdb_movie_id = :id');
$query->bindValue(':movie_list_id', $movie_list_id, PDO::PARAM_INT);
$query->bindValue(':id', $tmdb_movie_id, PDO::PARAM_INT);
if ($query->execute() === FALSE) {
	$errorInfo = $query->errorInfo();
	$errors[] = sprintf("Execute error: %s<br>\n", $errorInfo[2]);
}
//echo "done<br>\n";

if (isset($errors)) if (count($errors) > 0) var_dump($errors);
else {
	// Update our db var
	echo 'greatsuccess';
	$query = $db_connection->prepare('SELECT * FROM movie_lists WHERE user_id = :user_id');
	$query->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
	if ($query->execute() === FALSE) {
		$errorInfo = $query->errorInfo();
		$errors[] = 'Execute error: ' . $errorInfo[2];
	}
	
	$movie_lists = array();
	while ($row = $query->fetch(PDO::FETCH_OBJ)) {
		$movie_lists[$row->list_rank] = array($row->movie_list_id, $row->list_name, $row->list_description);
	}
	
	// Construct our javascript db var
	$db_var = array();
	foreach ($movie_lists as $v) {
		$query = $db_connection->prepare('SELECT b.tmdb_movie_id AS movie_id, b.tmdb_title AS title, b.tmdb_original_title AS original_title, b.tmdb_poster_path AS poster_path, b.tmdb_release_date AS release_date FROM movies a JOIN master_movie_list b ON a.tmdb_movie_id = b.tmdb_movie_id WHERE movie_list_id = :movie_list_id');
		$query->bindValue(':movie_list_id', $v[0], PDO::PARAM_INT);
		if ($query->execute() === FALSE) {
			$errorInfo = $query->errorInfo();
			$errors[] = 'Execute error: ' . $errorInfo[2];
		}
		$db_var[] = array('list_id' => $v[0], 'list_name' => $v[1], 'list_description' => $v[2], 'display_log' => 0, 'movie_details' => $query->fetchAll(PDO::FETCH_OBJ));
	}
	echo json_encode($db_var);
}
