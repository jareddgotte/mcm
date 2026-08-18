<?php

require_once(__DIR__ . '/inc/bootstrap.php');
// A move reads from one list and writes to another, so both lists have to be
// this user's: a check on the source alone would let a movie be pushed into
// somebody else's collection, and a check on the destination alone would let
// one be pulled out of it.
require_once(__DIR__ . '/inc/guards.php');
require_once('inc/php-login.php');

// Nobody signed in has no lists to move anything between.
mcm_require_login();

$from_list = (isset($_POST['from_list'])) ? $_POST['from_list'] : ((isset($_GET['from_list'])) ? $_GET['from_list'] : '');
$to_list = (isset($_POST['to_list'])) ? $_POST['to_list'] : ((isset($_GET['to_list'])) ? $_GET['to_list'] : '');
$movie_id = (isset($_POST['movie_id'])) ? $_POST['movie_id'] : ((isset($_GET['movie_id'])) ? $_GET['movie_id'] : '');

//printf("f[%s] t[%s] m[%s]\n", $from_list, $to_list, $movie_id);
//echo "trying to connect to db<br>\n";
$db_connection = mcm_db_or_fail('move');

// Both ends, both before the first query. Each guard also replaces the old "no
// list id given" check for its end, and refuses an empty identifier, one that
// is not a positive integer and one belonging to somebody else identically.
$from_list = mcm_require_list_owner($db_connection, $from_list);
$to_list   = mcm_require_list_owner($db_connection, $to_list);

// Kill the script if someone got here improperly
if ($movie_id === '') { echo 'Error: No movie id given.'; exit(); }

//echo "selecting the movie id<br>\n";
$query = $db_connection->prepare('SELECT id FROM movies WHERE movie_list_id = :from_list AND tmdb_movie_id = :movie_id');
$query->bindValue(':from_list', $from_list, PDO::PARAM_INT);
$query->bindValue(':movie_id', $movie_id, PDO::PARAM_INT);
mcm_db_execute($query, 'move: finding the movie');
$rows = $query->fetchAll(PDO::FETCH_OBJ);
if (count($rows) > 0) {
	//echo "moving movie<br>\n";
	$row = $rows[0];
	//echo 'row id: ' + $row->id + "<br>\n";
	$query = $db_connection->prepare('UPDATE movies SET movie_list_id = :to_list WHERE id = :id');
	$query->bindValue(':to_list', $to_list, PDO::PARAM_INT);
	$query->bindValue(':id', $row->id, PDO::PARAM_INT);
	mcm_db_execute($query, 'move: moving the movie');
}
//echo "done<br>\n";
