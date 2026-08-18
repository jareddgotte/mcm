<?php

require_once(__DIR__ . '/inc/bootstrap.php');
// A delete that only names a list identifier deletes from whichever list was
// named, so the questions the shared guards ask - was this a POST, who is this,
// did they mean to ask, and is the list theirs - are what stands between a
// request and somebody else's collection.
require_once(__DIR__ . '/inc/guards.php');
require_once('inc/php-login.php');

// A POST from a signed-in visitor, carrying this session's own token. A delete
// that arrived any other way is not one anybody asked for on purpose.
mcm_require_post();
mcm_require_login();
mcm_require_csrf();

$movie_list_id = isset($_POST['movie_list_id']) ? $_POST['movie_list_id'] : '';
$tmdb_movie_id = isset($_POST['tmdb_movie_id']) ? $_POST['tmdb_movie_id'] : '';

//echo "trying to connect to db<br>\n";
$db_connection = mcm_db_or_fail('delete_movie');

// The list has to be this user's before the delete is prepared, let alone run.
// The guard also replaces the old "no movie list id given" check: an empty
// identifier, an identifier that is not a positive integer and somebody else's
// list are all refused the same way.
$movie_list_id = mcm_require_list_owner($db_connection, $movie_list_id);

// Kill the script if someone got here improperly
if ($tmdb_movie_id === '') { echo 'Error: No movie id given.'; exit(); }

// delete movie
//echo "deleting movie<br>\n";
$query = $db_connection->prepare('DELETE FROM movies WHERE movie_list_id = :movie_list_id AND tmdb_movie_id = :id');
$query->bindValue(':movie_list_id', $movie_list_id, PDO::PARAM_INT);
$query->bindValue(':id', $tmdb_movie_id, PDO::PARAM_INT);
mcm_db_execute($query, 'delete_movie: deleting a movie');
//echo "done<br>\n";

// Update our db var
echo 'greatsuccess';
/*$query = $db_connection->prepare('SELECT * FROM movie_lists WHERE user_id = :user_id');
$query->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
mcm_db_execute($query, 'delete_movie: listing the lists');

$movie_lists = array();
while ($row = $query->fetch(PDO::FETCH_OBJ)) {
	$movie_lists[$row->list_rank] = array($row->movie_list_id, $row->list_name, $row->list_description);
}

// Construct our javascript db var
$db_var = array();
foreach ($movie_lists as $v) {
	$query = $db_connection->prepare('SELECT b.tmdb_movie_id AS movie_id, b.tmdb_title AS title, b.tmdb_original_title AS original_title, b.tmdb_poster_path AS poster_path, b.tmdb_release_date AS release_date FROM movies a JOIN master_movie_list b ON a.tmdb_movie_id = b.tmdb_movie_id WHERE movie_list_id = :movie_list_id');
	$query->bindValue(':movie_list_id', $v[0], PDO::PARAM_INT);
	mcm_db_execute($query, 'delete_movie: listing a list');
	$db_var[] = array('list_id' => $v[0], 'list_name' => $v[1], 'list_description' => $v[2], 'display_log' => 0, 'movie_details' => $query->fetchAll(PDO::FETCH_OBJ));
}
echo json_encode($db_var);
*/
