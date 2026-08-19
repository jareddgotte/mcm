<?php

// import TMDb list into my db

require_once(__DIR__ . '/inc/bootstrap.php');
// An import writes a whole list's worth of rows, so how the request arrived, who
// is asking, whether they meant to ask and whose list they are writing into are
// all settled with the shared guards before any of it - and before the request
// goes out to TMDb, which is work nobody should be able to make this site do
// without an account.
require_once(__DIR__ . '/inc/guards.php');
// The list itself is read through the proxy's own "list" operation rather than
// through the vendored wrapper: one place decides what this site may ask TMDb
// for, one place holds the credential, and one place says which fields of an
// answer are repeated. mcm_tmdb_resolve() is that policy, called here in this
// process rather than over an HTTP request to this site's own server.
require_once(__DIR__ . '/inc/tmdb_proxy.php');

// A POST from a signed-in visitor, carrying this session's own token. All three
// are settled before the connection is opened, and so long before TMDb is asked
// for anything.
mcm_require_post();
mcm_require_login();
mcm_require_csrf();

$movie_list_id = isset($_POST['movie_list_id']) ? $_POST['movie_list_id'] : '';
$tmdb_list_id  = isset($_POST['tmdb_list_id']) ? $_POST['tmdb_list_id'] : '';

// The TMDb list identifier is settled here, before the connection and before
// the proxy is called at all, because it is the one value on this page somebody
// typed: an answer it can act on belongs to whoever typed it, the way
// mcm_list_name_error() answers a bad list name, rather than being one of the
// bounded refusals a page's own computed value gets. Both shapes TMDb has ever
// issued are accepted, and mcm_tmdb_list_identifier() is what says so - the
// same check the operation itself will make of the value passed to it.
if (!is_string($tmdb_list_id) || trim($tmdb_list_id) === '') {
	echo 'Error: No import list id given.';
	exit();
}
$tmdb_list_id = mcm_tmdb_list_identifier($tmdb_list_id);
if ($tmdb_list_id === null) {
	echo 'Error: That is not a TMDb list id.';
	exit();
}

//echo "trying to connect to db<br>\n";
// The connection is opened here rather than after the import so that ownership
// can be settled first. The guard replaces the old "no movie list id given"
// check, and refuses an empty identifier, one that is not a positive integer
// and somebody else's list identically.
$db_connection = mcm_db_or_fail('import_list');
$movie_list_id = mcm_require_list_owner($db_connection, $movie_list_id);
$user_id       = mcm_current_user_id();

//echo "importing list<br>\n";
// The proxy's list operation, over the connection this page already holds. It
// asks its own questions again - a signed-in caller, values it accepts, and the
// owner of the local list named - because the caller policy is the operation's
// and not something this page may assert on its behalf. What comes back is the
// projection and nothing else: the five fields below, rebuilt field by field,
// with no upstream body, URL or credential anywhere in it.
$imported = mcm_tmdb_resolve('list', array(
	'list_id'       => $tmdb_list_id,
	'movie_list_id' => $movie_list_id,
), $db_connection);

if (empty($imported['ok'])) {
	// Why it failed is the log's, not the client's: mcm_tmdb_resolve() has
	// already categorised it, and the visitor gets this site's one generic
	// message like every other failure here.
	mcm_log('TMDb import', 'the list could not be read: ' . mcm_log_detail($imported['category']));
	mcm_fail();
}

//echo "iterating through imported list<br>\n";
foreach ($imported['data']['items'] as $v) {
	// A row TMDb sent without a usable identifier is not a film this site can
	// store: tmdb_movie_id is the key every table below joins on, so an item
	// without one is skipped rather than written as a nameless zero.
	if ($v['id'] === null) {
		continue;
	}
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
