<?php

require_once('inc/php-login.php');
session_start();
$errors = array();

// Kill the script if someone got here improperly
$list_name = (isset($_POST['list_name'])) ? $_POST['list_name'] : ((isset($_GET['list_name'])) ? $_GET['list_name'] : '');
$list_description = (isset($_POST['list_description'])) ? $_POST['list_description'] : ((isset($_GET['list_description'])) ? $_GET['list_description'] : '');
$list_rank = (isset($_POST['list_rank'])) ? $_POST['list_rank'] : ((isset($_GET['list_rank'])) ? $_GET['list_rank'] : '');

if ($list_name === '') { echo 'Error: No list name given.'; exit(); }
if ($list_rank === '') { echo 'Error: No list rank given.'; exit(); }
// Optional:
//if ($list_description === '') { echo 'Error: No list description given.'; exit(); }

//echo "trying to connect to db<br>\n";
try {
	$db_connection = new PDO('mysql:host='. DB_HOST .';dbname='. DB_NAME, DB_USER, DB_PASS);
} catch (PDOException $e) {
	$db_connection = false;
	$errors[] = 'Database error' . $e->getMessage();
}

//echo "inserting new list<br>\n";
$query = $db_connection->prepare('INSERT INTO movie_lists (user_id, list_name, list_description, list_rank) VALUES (:user_id, :list_name, :list_description, :list_rank)');
$query->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
$query->bindValue(':list_name', $list_name, PDO::PARAM_STR);
$query->bindValue(':list_description', $list_description, PDO::PARAM_STR);
$query->bindValue(':list_rank', $list_rank, PDO::PARAM_INT);
if ($query->execute() === FALSE) {
	$errorInfo = $query->errorInfo();
	$errors[] = sprintf("Execute error: %s<br>\n", $errorInfo[2]);
}
$query = $db_connection->prepare('SELECT movie_list_id FROM movie_lists WHERE user_id = :user_id AND list_rank = :list_rank');
$query->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
$query->bindValue(':list_rank', $list_rank, PDO::PARAM_INT);
if ($query->execute() === FALSE) {
	$errorInfo = $query->errorInfo();
	$errors[] = sprintf("Execute error: %s<br>\n", $errorInfo[2]);
}
$rows = $query->fetchAll(PDO::FETCH_OBJ);
if (count($rows) > 0) {
	$row = $rows[0];
	echo 'movie_list_id:' . $row->movie_list_id;
}
else echo '2'; // Did not find the list we just created
//echo "done<br>\n";

if (isset($errors)) if (count($errors) > 0) var_dump($errors);
/*else {
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
*/
