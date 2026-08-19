<?php

require_once(__DIR__ . '/inc/bootstrap.php');
// Four questions have to be answered before this page writes anything: is this
// a POST, is anybody signed in, did the request come from a page this site
// handed out, and is the list they named theirs. All four are asked with the
// shared guards, so a refusal here is the same refusal every other endpoint
// gives, and every one of them is given before any query runs.
require_once(__DIR__ . '/inc/guards.php');
// The proxy's policy, used from inside this process rather than over the wire:
// the operation is named, its values are validated, the request is made by the
// one client that holds the credential, and the answer is projected before this
// page sees it. mcm_tmdb_resolve() is that whole path in one call.
require_once(__DIR__ . '/inc/tmdb_proxy.php');
require_once('inc/php-login.php');

// A POST from a signed-in visitor, carrying this session's own token. Nobody
// signed in has a collection to add to, and a page this site did not hand out
// does not get to add to anybody's.
mcm_require_post();
mcm_require_login();
mcm_require_csrf();

// The whole of what a request gets to decide: which list, and which film. What
// the film is called, what it looks like and when it came out are this server's
// to look up. A request that carries a title, a poster path or a release date
// is describing a film it does not get to describe, and those fields are read
// nowhere below, so there is no path by which one of them could be stored.
$movie_list_id           = isset($_POST['movie_list_id']) ? $_POST['movie_list_id'] : '';
$tmdb_movie_id_submitted = isset($_POST['tmdb_movie_id']) ? $_POST['tmdb_movie_id'] : '';

$db_connection = mcm_db_or_fail('add_movie');

// Which list this is settles here, and it settles before the first query and
// before the first outbound request. master_movie_list is shared between every
// account and is written further down, so a refusal that came any later would
// already have changed data - and a refusal that came after the lookup would
// have spent one of this site's TMDb requests on somebody who was not allowed
// to ask. The guard also replaces the old "no movie list id given" check: it
// refuses an empty identifier, an identifier that is not a positive integer and
// somebody else's list identically.
$movie_list_id = mcm_require_list_owner($db_connection, $movie_list_id);
$user_id       = mcm_current_user_id();

// Kill the script if someone got here improperly
if ($tmdb_movie_id_submitted === '') { echo 'Error: No movie id given.'; exit(); }

// The identifier is a value the page itself computed, so a malformed one is a
// bounded refusal with the reason in the log, exactly as a malformed list
// position is. It is settled before it can become a path segment, and the
// submitted value is kept in a variable of its own so that the log line names
// it without this file rendering a superglobal.
$tmdb_movie_id = mcm_positive_int($tmdb_movie_id_submitted);
if ($tmdb_movie_id === null) {
	mcm_json_error(400, 'add_movie: refused a movie id that is not a positive integer: ' . mcm_log_detail($tmdb_movie_id_submitted));
}

// Now, and only now, ask what this film is. The four fields below are the movie
// operation's own projection - the same four tmdb.php would answer a browser
// with - and they are the only description of this film that is ever stored.
$resolved = mcm_tmdb_resolve('movie', array('movie_id' => $tmdb_movie_id));
if (empty($resolved['ok'])) {
	// The category, and the proxy's own bounded reason where there is one. An
	// upstream body, a URL or a credential has no business in this site's log
	// either, and none of them is ever in a category or a reason.
	mcm_log('add_movie', 'the movie database could not describe movie ' . $tmdb_movie_id . ': '
		. (isset($resolved['category']) ? $resolved['category'] : 'unavailable')
		. (isset($resolved['reason']) ? ' (' . $resolved['reason'] . ')' : ''));
	echo '3'; // could not be looked up
	exit();
}

$movie = $resolved['data'];
// Cut to the width of the columns that hold them, by the character. Nothing
// upstream promises to stay inside a varchar(255).
$tmdb_title          = mcm_column_text(isset($movie['title']) ? $movie['title'] : '', 255);
$tmdb_original_title = mcm_column_text(isset($movie['original_title']) ? $movie['original_title'] : '', 255);
// The poster path stays null where TMDb has none: the browser tells "no poster"
// from "a poster" by exactly that, and always has.
$tmdb_poster_path    = (isset($movie['poster_path']) && $movie['poster_path'] !== null) ? mcm_column_text($movie['poster_path'], 255) : null;
$tmdb_release_date   = isset($movie['release_date']) ? $movie['release_date'] : '';

// tmdb_release_date is a date column and tmdb_title is not nullable, so a film
// the movie database cannot name or date is one this site cannot store. That
// was true before this page looked the film up itself; the difference is only
// that the answer now comes from the lookup rather than from the request.
if ($tmdb_title === '' || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $tmdb_release_date) !== 1) {
	mcm_log('add_movie', 'the movie database gave movie ' . $tmdb_movie_id . ' no usable title or release date');
	echo '3'; // could not be looked up
	exit();
}

// check if movie is already added to master list
//echo "checking if movie is already added to master list<br>\n";
$query = $db_connection->prepare('SELECT * FROM master_movie_list WHERE tmdb_movie_id = :id');
$query->bindValue(':id', $tmdb_movie_id, PDO::PARAM_INT);
mcm_db_execute($query, 'add_movie: looking the movie up in the master list');
$rows = $query->fetchAll(PDO::FETCH_OBJ);
$update = false;
//   if it is, update movie details
if (count($rows) > 0) {
	//echo "it has already been added so I'm updating the details<br>\n";
	$row = $rows[0];
	if ($tmdb_title !== $row->tmdb_title) $update = true;
	if ($tmdb_original_title !== $row->tmdb_original_title) $update = true;
	if ($tmdb_poster_path !== $row->tmdb_poster_path) $update = true;
	if ($tmdb_release_date !== $row->tmdb_release_date) $update = true;
	if ($update === true) {
		$query = $db_connection->prepare('UPDATE master_movie_list SET tmdb_title = :title, tmdb_original_title = :original_title, tmdb_poster_path = :poster_path, tmdb_release_date = :release_date WHERE tmdb_movie_id = :id');
		$query->bindValue(':title', $tmdb_title, PDO::PARAM_STR);
		$query->bindValue(':original_title', $tmdb_original_title, PDO::PARAM_STR);
		$query->bindValue(':poster_path', $tmdb_poster_path, PDO::PARAM_STR);
		$query->bindValue(':release_date', $tmdb_release_date, PDO::PARAM_STR);
		$query->bindValue(':id', $tmdb_movie_id, PDO::PARAM_INT);
		mcm_db_execute($query, 'add_movie: updating the master list');
	}
}
//   otherwise, add it
else {
	//echo "it hasn't been added so I'm inserting the new data<br>\n";
	$query = $db_connection->prepare('INSERT INTO master_movie_list (tmdb_movie_id, tmdb_title, tmdb_original_title, tmdb_poster_path, tmdb_release_date) VALUES (:id, :title, :original_title, :poster_path, :release_date)');
	$query->bindValue(':id', $tmdb_movie_id, PDO::PARAM_INT);
	$query->bindValue(':title', $tmdb_title, PDO::PARAM_STR);
	$query->bindValue(':original_title', $tmdb_original_title, PDO::PARAM_STR);
	$query->bindValue(':poster_path', $tmdb_poster_path, PDO::PARAM_STR);
	$query->bindValue(':release_date', $tmdb_release_date, PDO::PARAM_STR);
	mcm_db_execute($query, 'add_movie: inserting into the master list');
}
// check if movie is already added to this list or other lists of user
//echo "checking if movie is already added to user's lists<br>\n";
$query = $db_connection->prepare('SELECT * FROM movies a JOIN movie_lists b ON a.movie_list_id = b.movie_list_id WHERE tmdb_movie_id = :tmdb_movie_id AND user_id = :user_id');
$query->bindValue(':tmdb_movie_id', $tmdb_movie_id, PDO::PARAM_INT);
// The duplicate is only a duplicate when this user already has the movie. The
// owner comes from the guards rather than straight out of the session, so the
// value queried with is the one ownership was decided on.
$query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
mcm_db_execute($query, "add_movie: checking the user's lists for the movie");
$rows = $query->fetchAll(PDO::FETCH_OBJ);

//   if it isn't, add it
if (count($rows) === 0) {
	//echo "it isn't so we're adding it<br>\n";
	$query = $db_connection->prepare('INSERT INTO movies (movie_list_id, tmdb_movie_id) VALUES (:movie_list_id, :tmdb_movie_id)');
	$query->bindValue(':movie_list_id', $movie_list_id, PDO::PARAM_INT);
	$query->bindValue(':tmdb_movie_id', $tmdb_movie_id, PDO::PARAM_INT);
	mcm_db_execute($query, "add_movie: adding the movie to the user's list");
	echo '1'; // inserted
	exit();
}
echo '2'; // dusplicate discovered
//echo "done<br>\n";

/*// Update our db var
echo 'greatsuccess';
$query = $db_connection->prepare('SELECT * FROM movie_lists WHERE user_id = :user_id');
$query->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
mcm_db_execute($query, 'add_movie: listing the lists');

$movie_lists = array();
while ($row = $query->fetch(PDO::FETCH_OBJ)) {
	$movie_lists[$row->list_rank] = array($row->movie_list_id, $row->list_name, $row->list_description);
}

// Construct our javascript db var
$db_var = array();
foreach ($movie_lists as $v) {
	$query = $db_connection->prepare('SELECT b.tmdb_movie_id AS movie_id, b.tmdb_title AS title, b.tmdb_original_title AS original_title, b.tmdb_poster_path AS poster_path, b.tmdb_release_date AS release_date FROM movies a JOIN master_movie_list b ON a.tmdb_movie_id = b.tmdb_movie_id WHERE movie_list_id = :movie_list_id');
	$query->bindValue(':movie_list_id', $v[0], PDO::PARAM_INT);
	mcm_db_execute($query, 'add_movie: listing a list');
	$db_var[] = array('list_id' => $v[0], 'list_name' => $v[1], 'list_description' => $v[2], 'display_log' => 0, 'movie_details' => $query->fetchAll(PDO::FETCH_OBJ));
}
echo json_encode($db_var);
*/
