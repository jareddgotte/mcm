<?php

require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');
require_once('inc/php-login.php');

// Renaming somebody else's list is not a thing this endpoint does. Signed in
// first, so an anonymous request is refused before anything is read.
mcm_require_login();
$user_id = mcm_current_user_id();

// Kill the script if someone got here improperly
$movie_list_id = (isset($_POST['movie_list_id'])) ? $_POST['movie_list_id'] : ((isset($_GET['movie_list_id'])) ? $_GET['movie_list_id'] : '');
$list_name = (isset($_POST['list_name'])) ? $_POST['list_name'] : ((isset($_GET['list_name'])) ? $_GET['list_name'] : '');

// Bounded validation of the submitted name, before anything is stored. It only
// rejects; it never rewrites what was typed, and the name already on this list
// is left exactly as it is unless a valid new one replaces it.
$list_name_error = mcm_list_name_error($list_name);
if ($list_name_error !== '') { echo 'Error: ' . $list_name_error; exit(); }
// Optional:
//if ($tmdb_original_title === '') { echo 'Error: No movie original title given.'; exit(); }
//if ($tmdb_poster_path === '') { echo 'Error: No movie poster path given.'; exit(); }

//printf("c[%s] m[%s]\n", $current_list, $movie_id);
//echo "trying to connect to db<br>\n";
$db_connection = mcm_db_or_fail('rename_list');

// Whose list this is. A missing list, an identifier that is not one and another
// user's list are all the same refusal, so the answer never says which.
$movie_list_id = mcm_require_list_owner($db_connection, $movie_list_id);

// rename list
//echo "renaming list<br>\n";
// The owner is in the WHERE clause as well as in the guard above: the guard is
// what refuses the request, and this is what makes the statement itself unable
// to touch a row belonging to anybody else.
$query = $db_connection->prepare('UPDATE movie_lists SET list_name = :list_name WHERE movie_list_id = :movie_list_id AND user_id = :user_id');
$query->bindValue(':list_name', $list_name, PDO::PARAM_STR);
$query->bindValue(':movie_list_id', $movie_list_id, PDO::PARAM_INT);
$query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
mcm_db_execute($query, 'rename_list: renaming a list');
//echo "done<br>\n";

// Update our db var
echo 'greatsuccess';
