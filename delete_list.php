<?php

require_once(__DIR__ . '/inc/bootstrap.php');
require_once('inc/php-login.php');

// Kill the script if someone got here improperly
$movie_list_id = (isset($_POST['movie_list_id'])) ? $_POST['movie_list_id'] : ((isset($_GET['movie_list_id'])) ? $_GET['movie_list_id'] : '');

if ($movie_list_id === '') { echo 'Error: No movie list id given.'; exit(); }

//echo "trying to connect to db<br>\n";
$db_connection = mcm_db_or_fail('delete_list');

// delete list
//echo "deleting list<br>\n";
$query = $db_connection->prepare('DELETE FROM movie_lists WHERE movie_list_id = :movie_list_id');
$query->bindValue(':movie_list_id', $movie_list_id, PDO::PARAM_INT);
mcm_db_execute($query, 'delete_list: deleting the list');
//echo "done<br>\n";

// delete movies attached to list
//echo "deleting movies in list<br>\n";
$query = $db_connection->prepare('DELETE FROM movies WHERE movie_list_id = :movie_list_id');
$query->bindValue(':movie_list_id', $movie_list_id, PDO::PARAM_INT);
mcm_db_execute($query, 'delete_list: deleting the movies in the list');
//echo "done<br>\n";

// re-rank our lists
$query = $db_connection->prepare('CALL AdjustRanks(:user_id)');
$query->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
mcm_db_execute($query, 'delete_list: re-ranking the remaining lists');

// Update our db var
echo 'greatsuccess';

/*$query = $db_connection->prepare('SELECT * FROM movie_lists WHERE user_id = :user_id');
$query->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
mcm_db_execute($query, 'delete_list: listing the lists');

$movie_lists = array();
while ($row = $query->fetch(PDO::FETCH_OBJ)) {
	$movie_lists[$row->list_rank] = array($row->movie_list_id, $row->list_name, $row->list_description);
}

// Construct our javascript db var
$db_var = array();
foreach ($movie_lists as $v) {
	$query = $db_connection->prepare('SELECT b.tmdb_movie_id AS movie_id, b.tmdb_title AS title, b.tmdb_original_title AS original_title, b.tmdb_poster_path AS poster_path, b.tmdb_release_date AS release_date FROM movies a JOIN master_movie_list b ON a.tmdb_movie_id = b.tmdb_movie_id WHERE movie_list_id = :movie_list_id');
	$query->bindValue(':movie_list_id', $v[0], PDO::PARAM_INT);
	mcm_db_execute($query, 'delete_list: listing a list');
	$db_var[] = array('list_id' => $v[0], 'list_name' => $v[1], 'list_description' => $v[2], 'display_log' => 0, 'movie_details' => $query->fetchAll(PDO::FETCH_OBJ));
}
echo json_encode($db_var);
*/
