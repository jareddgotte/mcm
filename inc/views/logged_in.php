<?php

// I primarily use sessions to reduce the amount of calls I make to TMDb, as well as maintaining a session
// with TMDb so I can move movies from one list to another
//session_start();

if (!isset($_SESSION['db_lists'])) $_SESSION['db_lists'] = $db_lists;
//if (!isset($_SESSION['session'])) $_SESSION['session'] = TMDB_SESSION_ID;

// In a perfect world, this call is only made once per session
if (!isset($_SESSION['tmdb_obj'])) {
	$_SESSION['tmdb_obj'] = new TMDb(TMDB_API_KEY);
}

// This token will only be set in the login page
if (isset($_SESSION['token'])) {
	if (!isset($_SESSION['session'])) { // Make sure we don't request multiple sessions
		$session = $_SESSION['tmdb_obj']->getAuthSession($_SESSION['token']['request_token']);
		if (isset($session['status_code'])) {
			if ($session['status_code'] == 17) { // 17 means "Session denied"
				$_SESSION['logged_in'] = FALSE; // This variable lets the functions below know if we're logged in or not
			}
		}
		else { // If I authorized properly, the following happens
			$_SESSION['session'] = $session['session_id'];
			$_SESSION['logged_in'] = TRUE;
		}
	}
	else $_SESSION['logged_in'] = TRUE;
}
else $_SESSION['logged_in'] = FALSE;

// Get the tmdb config so we can pass it onto the TMDbDisplay class for images
if (!isset($_SESSION['tmdb_config'])) {
	$_SESSION['tmdb_config'] = $_SESSION['tmdb_obj']->getConfiguration();
}
$base_url = $_SESSION['tmdb_config']['images']['base_url'];
$poster_size =  $_SESSION['tmdb_config']['images']['poster_sizes'][2];

// Create our list arrays to pass onto the TMDbDisplay class
try {
	$db_connection = new PDO('mysql:host='. DB_HOST .';dbname='. DB_NAME, DB_USER, DB_PASS);
} catch (PDOException $e) {
	$db_connection = false;
	$errors[] = 'Database error' . $e->getMessage();
}

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
$db_var = json_encode($db_var);
//var_dump($movie_lists);


/*$WTSList = $_SESSION['tmdb_obj']->getList(WTS_LIST_KEY);
$WTSListItemsJSON = json_encode($WTSList['items']);

$HNSList = $_SESSION['tmdb_obj']->getList(HNS_LIST_KEY);
$HNSListItemsJSON = json_encode($HNSList['items']);

$SeenList = $_SESSION['tmdb_obj']->getList(SEEN_LIST_KEY);
$SeenListItemsJSON = json_encode($SeenList['items']);*/


// I use the following code block to get a list of all of the movies I have so I can compare them with my own
//$merged = array_merge($WTSList['items'], $HNSList['items'], $SeenList['items']);
/*function cmp ($a, $b) {
	$al = strtolower($a['title']);
	$bl = strtolower($b['title']);
	if ($al == $bl) {
		return 0;
	}
	return ($al < $bl) ? -1 : 1;
}
foreach ($merged as $k => $v) {
	$res = $v['title'];
	if (substr($v['title'], 0, 4) == 'The ')
		$res = substr($v['title'], 4) . ', The';
	$merged[$k]['title'] = $res;
}
usort($merged, "cmp");
foreach ($merged as $k => $v) {
	printf("%s\n", $v['title']);
}*/

// include html header and display php-login message/error
$title = 'My Collection';
$pre_styles = array('themes/jquery-ui-1.10.3/smoothness/jquery-ui.sortable.min');
$post_styles = array('tabdrop', 'typeahead.js-bootstrap', 'mc');
$pre_scripts = array('libs/jquery-ui-1.10.3.sortable.min', 'jquery.ui.touch-punch.min');
$post_scripts = array('bootstrap-tabdrop', 'jquery.lazyload.min', 'libs/hogan-2.0.0', 'typeahead.min', 'mc');
$script = "
console.log('" . serialize($_SESSION) . "'); // debug my session variable
//console.log('" . ""/*count($merged)*/ . "'); // debug how many movies I have

var db = " . $db_var . "
var base_url = '" . $base_url . "'
var poster_size_big = '" . $poster_size . "'
var poster_size_small = '" . $_SESSION['tmdb_config']['images']['poster_sizes'][0] . "'

// Variables to record whether or not we've loaded the table yet or not.  This is to prevent multiple loadings of each table if we keep going back and forth between tabs
var currentList = db[0].list_id
var currentListPos = listPos(currentList)
var currentSort = checkSortOrder('sort') // = 'name'
var currentOrder = checkSortOrder('order') // = 'asc'
";
include('header.php');

// if you need users's information, just put them into the $_SESSION variable and output them here

//echo $phplogin_lang['You are logged in as'] . $_SESSION['user_name'] ."<br />\n";
//echo $login->user_gravatar_image_url;
//echo $phplogin_lang['Profile picture'] .'<br/>'. $login->user_gravatar_image_tag;

if (isset($errors)) var_dump($errors);

$list_tabs = '';
$list_containers = '';
foreach($movie_lists as $v) {
	$list_tabs .= sprintf("<li data-listid=\"%s\"><a href=\"#%s\" data-toggle=\"pill\">%s</a></li>\n", $v[0], $v[0], $v[1]);
	$list_containers .= sprintf("<div class=\"tab-pane\" id=\"%s\"></div>\n", $v[0]);
}


?>

	<ul class="nav nav-pills" id="list-tabs">
		<?php echo $list_tabs; ?>
	</ul>
	<div id="main-alerts"></div>
	<div class="row" id="list-control">
		<div class="col-xs-12 col-sm-4"><input type="text" class="form-control" id="add_movie" placeholder="Add a Movie"></div>
		<div class="col-xs-12 col-sm-4"><input type="text" class="form-control" id="search_collection" placeholder="Search My Collection"></div>
		<div class="col-xs-6 col-sm-2">
			<div class="btn-group btn-block">
				<button type="button" class="btn btn-default btn-block dropdown-toggle" data-toggle="dropdown">View <span class="caret"></span></button>
				<ul class="dropdown-menu btn-block pull-right" role="menu" id="sort-order">
					<li class="dropdown-header" role="presentation">Sort by</li>
					<li><a class="sort" href="#name" id="name">Name</a></li>
					<li><a class="sort" href="#date" id="date">Release Date</a></li>
					<li class="divider" role="presentation"></li>
					<li class="dropdown-header" role="presentation">Order by</li>
					<li><a class="order" href="#asc" id="asc">Ascending</a></li>
					<li><a class="order" href="#desc" id="desc">Descending</a></li>
				</ul>
			</div>
		</div><!-- /.col-**-* -->
		<div class="col-xs-6 col-sm-2">
			<div class="btn-group btn-block" id="list-options">
				<button type="button" class="btn btn-default btn-block dropdown-toggle" data-toggle="dropdown">Options <span class="caret"></span></button>
				<ul class="dropdown-menu btn-block pull-right" role="menu">
					<li class="disabled"><a href="#rename">Rename List</a></li>
					<li><a href="#import">Import List</a></li>
					<li class="disabled alert-danger"><a href="#delete">Delete List</a></li>
				</ul>
			</div>
		</div><!-- /.col-**-* -->
	</div>
	<div class="tab-content">
		<?php echo $list_containers; ?>
	</div>
	<div class="modal fade" id="dialog" tabindex="-1" role="dialog" aria-hidden="true">
	</div><!-- /.modal -->
	<div class="modal fade" id="import-dialog" tabindex="-1" role="dialog" aria-hidden="true">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
					<h4 class="modal-title">Import TMDb List</h4>
				</div>
				<div class="modal-body">
					<div id="import-alerts"></div>
					<p>Please enter the TMDb List ID you wish to import.</p>
					<input type="text" class="form-control" id="import-tmdb_list_id" placeholder="e.g. 5212934a760ee36af148407d" value=''>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
					<button type="button" class="btn btn-primary" id="import-submit">Import</button>
				</div>
			</div><!-- /.modal-content -->
		</div><!-- /.modal-dialog -->
	</div><!-- /.modal -->
<?php
// include html footer
include('footer.php');