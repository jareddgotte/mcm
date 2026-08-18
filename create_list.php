<?php

require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');
require_once('inc/php-login.php');

// Nobody creates a list on somebody else's behalf, and nobody creates one from a
// page this site did not hand out. All three run before anything is read, so a
// request that fails any of them never reaches a query at all.
mcm_require_post();
mcm_require_login();
mcm_require_csrf();
$user_id = mcm_current_user_id();

// Kill the script if someone got here improperly
$list_name = isset($_POST['list_name']) ? $_POST['list_name'] : '';
$list_description = isset($_POST['list_description']) ? $_POST['list_description'] : '';
$list_rank_submitted = isset($_POST['list_rank']) ? $_POST['list_rank'] : '';

// Bounded validation of the submitted name, before anything is stored. It only
// rejects; it never rewrites what was typed, and names already in the database
// are left exactly as they are.
$list_name_error = mcm_list_name_error($list_name);
if ($list_name_error !== '') { echo 'Error: ' . $list_name_error; exit(); }
// The rank is not something anybody types: the page sends the position the new
// list goes in, so a value that is not one is a malformed request rather than a
// mistake to explain. It gets the bounded refusal, and the value goes to the log.
$list_rank = mcm_list_rank($list_rank_submitted);
if ($list_rank === null) {
	mcm_json_error(400, 'create_list: refused a list rank that is not a position: ' . mcm_log_detail($list_rank_submitted));
}
// Optional:
//if ($list_description === '') { echo 'Error: No list description given.'; exit(); }

//echo "trying to connect to db<br>\n";
$db_connection = mcm_db_or_fail('create_list');

//echo "inserting new list<br>\n";
$query = $db_connection->prepare('INSERT INTO movie_lists (user_id, list_name, list_description, list_rank) VALUES (:user_id, :list_name, :list_description, :list_rank)');
$query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$query->bindValue(':list_name', $list_name, PDO::PARAM_STR);
$query->bindValue(':list_description', $list_description, PDO::PARAM_STR);
$query->bindValue(':list_rank', $list_rank, PDO::PARAM_INT);
mcm_db_execute($query, 'create_list: inserting the list');

// The identity of the row that was just inserted, from the insert itself. The
// read-back this replaces looked the list up by owner and rank, which is not a
// unique pair: a user whose ranks had drifted got back whichever of the
// matching rows the server happened to return first, and the page then attached
// every later change to that other list.
$movie_list_id = mcm_positive_int($db_connection->lastInsertId());
if ($movie_list_id === null) {
	// The insert succeeded and the driver still could not say what it created.
	// Nothing usable can be sent back, so this is a failure, not a refusal.
	mcm_log('Database error', 'create_list: the insert reported no identifier for the new list');
	mcm_fail();
}

echo 'movie_list_id:' . $movie_list_id;
//echo "done<br>\n";

/*// Update our db var
echo 'greatsuccess';
$query = $db_connection->prepare('SELECT * FROM movie_lists WHERE user_id = :user_id');
$query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
mcm_db_execute($query, 'create_list: listing the lists');

$movie_lists = array();
while ($row = $query->fetch(PDO::FETCH_OBJ)) {
	$movie_lists[$row->list_rank] = array($row->movie_list_id, $row->list_name, $row->list_description);
}

// Construct our javascript db var
$db_var = array();
foreach ($movie_lists as $v) {
	$query = $db_connection->prepare('SELECT b.tmdb_movie_id AS movie_id, b.tmdb_title AS title, b.tmdb_original_title AS original_title, b.tmdb_poster_path AS poster_path, b.tmdb_release_date AS release_date FROM movies a JOIN master_movie_list b ON a.tmdb_movie_id = b.tmdb_movie_id WHERE movie_list_id = :movie_list_id');
	$query->bindValue(':movie_list_id', $v[0], PDO::PARAM_INT);
	mcm_db_execute($query, 'create_list: listing a list');
	$db_var[] = array('list_id' => $v[0], 'list_name' => $v[1], 'list_description' => $v[2], 'display_log' => 0, 'movie_details' => $query->fetchAll(PDO::FETCH_OBJ));
}
echo json_encode($db_var);
*/
