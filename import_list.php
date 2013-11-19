<?php

// import TMDb list into my db

require_once('inc/php-login.php');
session_start();
$errors = array();

$movie_list_id = (isset($_POST['movie_list_id'])) ? $_POST['movie_list_id'] : ((isset($_GET['movie_list_id'])) ? $_GET['movie_list_id'] : '');
//$movie_list_id = 1;
// Be sure to handle whether it's an id OR a url with the id in it
$tmdb_list_id = (isset($_POST['tmdb_list_id'])) ? $_POST['tmdb_list_id'] : ((isset($_GET['tmdb_list_id'])) ? $_GET['tmdb_list_id'] : '');
//$tmdb_list_id = "5212934a760ee36af148407c"; // debug
//The following may be used when "creating list from import"
//$list_name = (isset($_POST['name'])) ? $_POST['name'] : ((isset($_GET['name'])) ? $_GET['name'] : '');
//$list_name = 'test list';
if ($movie_list_id === '') { echo 'Error: No movie list id given.'; exit(); }
if ($tmdb_list_id === '') { echo 'Error: No import list id given.'; exit(); }

if (!isset($_SESSION['tmdb_obj'])) {
	$_SESSION['tmdb_obj'] = new TMDb(TMDB_API_KEY);
}

//echo "importing list<br>\n";
$ImportList = $_SESSION['tmdb_obj']->getList($tmdb_list_id);
if (isset($ImportList['status_code'])) {
	printf("Error: Status code: %s | Message: %s\n", $ImportList['status_code'], $ImportList['status_message']);
	//var_dump($ImportList);
	exit();
}

//echo "trying to connect to db<br>\n";
try {
	$db_connection = new PDO('mysql:host='. DB_HOST .';dbname='. DB_NAME, DB_USER, DB_PASS);
} catch (PDOException $e) {
	$db_connection = false;
	$errors[] = 'Database error' . $e->getMessage();
}

//echo "iterating through imported list<br>\n";
foreach ($ImportList['items'] as $v) {
	// check if movie is already added to master list
	//echo "checking if movie is already added to master list<br>\n";
	$query = $db_connection->prepare('SELECT * FROM master_movie_list WHERE tmdb_movie_id = :id');
	$query->bindValue(':id', $v['id'], PDO::PARAM_INT);
	if ($query->execute() === FALSE) {
		$errorInfo = $query->errorInfo();
		$errors[] = sprintf("Execute error: %s<br>\n", $errorInfo[2]);
	}
	$rows = $query->fetchAll(PDO::FETCH_OBJ);
	$update = false;
	//   if it is, update movie details
	if (count($rows) > 0) {
		//echo "it has already been added so I'm updating the details<br>\n";
		$row = $rows[0];
		if ($v['title'] !== $row->tmdb_title) $update = true;
		if ($v['original_title'] !== $row->tmdb_original_title) $update = true;
		if ($v['poster_path'] !== $row->tmdb_poster_path) $update = true;
		if ($v['release_date'] !== $row->tmdb_release_date) $update = true;
		if ($update === true) {
			$query = $db_connection->prepare('UPDATE master_movie_list SET tmdb_title = :title, tmdb_original_title = :original_title, tmdb_poster_path = :poster_path, tmdb_release_date = :release_date WHERE tmdb_movie_id = :id');
			$query->bindValue(':title', $v['title'], PDO::PARAM_STR);
			$query->bindValue(':original_title', $v['original_title'], PDO::PARAM_STR);
			$query->bindValue(':poster_path', $v['poster_path'], PDO::PARAM_STR);
			$query->bindValue(':release_date', $v['release_date'], PDO::PARAM_STR);
			$query->bindValue(':id', $v['id'], PDO::PARAM_INT);
			if ($query->execute() === FALSE) {
				$errorInfo = $query->errorInfo();
				$errors[] = sprintf("Execute error: %s<br>\n", $errorInfo[2]);
			}
		}
	}
	//   otherwise, add it
	else {
		//echo "it hasn't been added so I'm inserting the new data<br>\n";
		$query = $db_connection->prepare('INSERT INTO master_movie_list (tmdb_movie_id, tmdb_title, tmdb_original_title, tmdb_poster_path, tmdb_release_date) VALUES (:id, :title, :original_title, :poster_path, :release_date)');
		$query->bindValue(':id', $v['id'], PDO::PARAM_INT);
		$query->bindValue(':title', $v['title'], PDO::PARAM_STR);
		$query->bindValue(':original_title', $v['original_title'], PDO::PARAM_STR);
		$query->bindValue(':poster_path', $v['poster_path'], PDO::PARAM_STR);
		$query->bindValue(':release_date', $v['release_date'], PDO::PARAM_STR);
		if ($query->execute() === FALSE) {
			$errorInfo = $query->errorInfo();
			$errors[] = sprintf("Execute error: %s<br>\n", $errorInfo[2]);
		}
	}
	// check if movie is already added to this list or other lists of user
	//echo "checking if movie is already added to user's lists<br>\n";
	$query = $db_connection->prepare('SELECT * FROM movies a JOIN movie_lists b ON a.movie_list_id = b.movie_list_id WHERE tmdb_movie_id = :tmdb_movie_id');
	$query->bindValue(':tmdb_movie_id', $v['id'], PDO::PARAM_INT);
	if ($query->execute() === FALSE) {
		$errorInfo = $query->errorInfo();
		$errors[] = sprintf("Execute error: %s<br>\n", $errorInfo[2]);
	}
	$rows = $query->fetchAll(PDO::FETCH_OBJ);
	//   if it isn't, add it
	if (count($rows) === 0) {
		//echo "it isn't so we're adding it<br>\n";
		$query = $db_connection->prepare('INSERT INTO movies (movie_list_id, tmdb_movie_id) VALUES (:movie_list_id, :tmdb_movie_id)');
		$query->bindValue(':movie_list_id', $movie_list_id, PDO::PARAM_STR);
		$query->bindValue(':tmdb_movie_id', $v['id'], PDO::PARAM_INT);
		if ($query->execute() === FALSE) {
			$errorInfo = $query->errorInfo();
			$errors[] = sprintf("Execute error: %s", $errorInfo[2]);
		}
	}
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
