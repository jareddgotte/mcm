<?php

// import TMDb list into my db

require_once(__DIR__ . '/inc/bootstrap.php');
// An import writes a whole list's worth of rows, so who is asking and whose
// list they are writing into are settled with the shared guards before any of
// it - and before the request goes out to TMDb, which is work nobody should be
// able to make this site do without an account.
require_once(__DIR__ . '/inc/guards.php');
require_once('inc/php-login.php');

// Nobody signed in has no list to import into.
mcm_require_login();

$movie_list_id = (isset($_POST['movie_list_id'])) ? $_POST['movie_list_id'] : ((isset($_GET['movie_list_id'])) ? $_GET['movie_list_id'] : '');
//$movie_list_id = 1;
// Be sure to handle whether it's an id OR a url with the id in it
$tmdb_list_id = (isset($_POST['tmdb_list_id'])) ? $_POST['tmdb_list_id'] : ((isset($_GET['tmdb_list_id'])) ? $_GET['tmdb_list_id'] : '');
//$tmdb_list_id = "5212934a760ee36af148407c"; // debug
//The following may be used when "creating list from import"
//$list_name = (isset($_POST['name'])) ? $_POST['name'] : ((isset($_GET['name'])) ? $_GET['name'] : '');
//$list_name = 'test list';

//echo "trying to connect to db<br>\n";
// The connection is opened here rather than after the import so that ownership
// can be settled first. The guard replaces the old "no movie list id given"
// check, and refuses an empty identifier, one that is not a positive integer
// and somebody else's list identically.
$db_connection = mcm_db_or_fail('import_list');
$movie_list_id = mcm_require_list_owner($db_connection, $movie_list_id);
$user_id       = mcm_current_user_id();

if ($tmdb_list_id === '') { echo 'Error: No import list id given.'; exit(); }

if (!isset($_SESSION['tmdb_obj'])) {
	$_SESSION['tmdb_obj'] = new TMDb(TMDB_API_KEY);
}

//echo "importing list<br>\n";
$ImportList = $_SESSION['tmdb_obj']->getList($tmdb_list_id);
if (isset($ImportList['status_code'])) {
	// Both values come straight from TMDb, so neither is rendered as markup.
	printf("Error: Status code: %s | Message: %s\n", mcm_html($ImportList['status_code']), mcm_html($ImportList['status_message']));
	//var_dump($ImportList);
	exit();
}

//echo "iterating through imported list<br>\n";
foreach ($ImportList['items'] as $v) {
	// check if movie is already added to master list
	//echo "checking if movie is already added to master list<br>\n";
	$query = $db_connection->prepare('SELECT * FROM master_movie_list WHERE tmdb_movie_id = :id');
	$query->bindValue(':id', $v['id'], PDO::PARAM_INT);
	mcm_db_execute($query, 'import_list: looking a movie up in the master list');
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
			mcm_db_execute($query, 'import_list: updating the master list');
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
		mcm_db_execute($query, 'import_list: inserting into the master list');
	}
	// check if movie is already added to this list or other lists of user
	//echo "checking if movie is already added to user's lists<br>\n";
	// Scoped to this user, as add_movie.php has always scoped it. Without the
	// owner in the WHERE clause the check asked whether anybody at all had the
	// movie, so an import silently dropped every film another account happened
	// to own.
	$query = $db_connection->prepare('SELECT * FROM movies a JOIN movie_lists b ON a.movie_list_id = b.movie_list_id WHERE tmdb_movie_id = :tmdb_movie_id AND user_id = :user_id');
	$query->bindValue(':tmdb_movie_id', $v['id'], PDO::PARAM_INT);
	$query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
	mcm_db_execute($query, "import_list: checking the user's lists for the movie");
	$rows = $query->fetchAll(PDO::FETCH_OBJ);
	//   if it isn't, add it
	if (count($rows) === 0) {
		//echo "it isn't so we're adding it<br>\n";
		$query = $db_connection->prepare('INSERT INTO movies (movie_list_id, tmdb_movie_id) VALUES (:movie_list_id, :tmdb_movie_id)');
		$query->bindValue(':movie_list_id', $movie_list_id, PDO::PARAM_INT);
		$query->bindValue(':tmdb_movie_id', $v['id'], PDO::PARAM_INT);
		mcm_db_execute($query, "import_list: adding a movie to the user's list");
	}
}
//echo "done<br>\n";

// Update our db var
echo 'greatsuccess';
$query = $db_connection->prepare('SELECT * FROM movie_lists WHERE user_id = :user_id');
$query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
mcm_db_execute($query, 'import_list: listing the lists');

$movie_lists = array();
while ($row = $query->fetch(PDO::FETCH_OBJ)) {
	$movie_lists[$row->list_rank] = array($row->movie_list_id, $row->list_name, $row->list_description);
}

// Construct our javascript db var
$db_var = array();
foreach ($movie_lists as $v) {
	$query = $db_connection->prepare('SELECT b.tmdb_movie_id AS movie_id, b.tmdb_title AS title, b.tmdb_original_title AS original_title, b.tmdb_poster_path AS poster_path, b.tmdb_release_date AS release_date FROM movies a JOIN master_movie_list b ON a.tmdb_movie_id = b.tmdb_movie_id WHERE movie_list_id = :movie_list_id');
	$query->bindValue(':movie_list_id', $v[0], PDO::PARAM_INT);
	mcm_db_execute($query, 'import_list: listing a list');
	$db_var[] = array('list_id' => $v[0], 'list_name' => $v[1], 'list_description' => $v[2], 'display_log' => 0, 'movie_details' => $query->fetchAll(PDO::FETCH_OBJ));
}
// This response is served as HTML by default, so a list name or title with
// markup in it must not survive as markup on the way out.
echo mcm_js($db_var);
