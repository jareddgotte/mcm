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
$poster_size =  $_SESSION['tmdb_config']['images']['poster_sizes'][1];

// Create our list arrays to pass onto the TMDbDisplay class
$WTSList = $_SESSION['tmdb_obj']->getList(WTS_LIST_KEY);
$WTSListItemsJSON = json_encode($WTSList['items']);

$HNSList = $_SESSION['tmdb_obj']->getList(HNS_LIST_KEY);
$HNSListItemsJSON = json_encode($HNSList['items']);

$SeenList = $_SESSION['tmdb_obj']->getList(SEEN_LIST_KEY);
$SeenListItemsJSON = json_encode($SeenList['items']);

// I use the following code block to get a list of all of the movies I have so I can compare them with my own
$merged = array_merge($WTSList['items'], $HNSList['items'], $SeenList['items']);
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
$post_styles = array('tabdrop', 'mc');
$pre_scripts = array('libs/jquery-ui-1.10.3.sortable.min', 'jquery.ui.touch-punch.min');
$post_scripts = array('bootstrap-tabdrop', 'jquery.lazyload.min', 'mc');
$script = "
console.log('" . serialize($_SESSION) . "'); // debug my session variable
console.log('" . count($merged) . "'); // debug how many movies I have

var db = {
	'WTS' : { // Want to See
		'name' : 'Want to See',
		'id'   : '" . WTS_LIST_KEY . "', // WTS list ID from TMDb
		'dlog' : 0, // This variable keeps track of whether or not we've displayed this information or not, so I do not redisplay the table every time I switch tabs
		'JSON' : $WTSListItemsJSON // This JSON includes all of the movie information related to the respective list
	},
	'HNS' : { // Haven't Seen
		'name' : 'Haven\'t Seen',
		'id'   : '" . HNS_LIST_KEY . "',
		'dlog' : 0,
		'JSON' : $HNSListItemsJSON
	},
	'Seen' : { // Already Seen
		'name' : 'Already Seen',
		'id'   : '" . SEEN_LIST_KEY . "',
		'dlog' : 0,
		'JSON' : $SeenListItemsJSON
	}
}
";
include('header.php');

// if you need users's information, just put them into the $_SESSION variable and output them here

//echo $phplogin_lang['You are logged in as'] . $_SESSION['user_name'] ."<br />\n";
//echo $login->user_gravatar_image_url;
//echo $phplogin_lang['Profile picture'] .'<br/>'. $login->user_gravatar_image_tag;

?>

	<ul class="nav nav-pills" id="list-tabs">
		<li><a href="#WTS" data-toggle="pill">Want to See</a></li>
		<li><a href="#HNS" data-toggle="pill">Haven't Seen</a></li>
		<li><a href="#Seen" data-toggle="pill">Already Seen</a></li>
	</ul>
	<div class="row" id="list-control">
		<div class="col-xs-12 col-sm-4"><input type="text" class="form-control" id="add_movie" placeholder="Add a Movie" disabled></div>
		<div class="col-xs-12 col-sm-4"><input type="text" class="form-control" id="search_movie" placeholder="Search My Collection" disabled></div>
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
					<li class="disabled"><a href="#import">Import List</a></li>
					<li class="disabled alert-danger"><a href="#delete">Delete List</a></li>
				</ul>
			</div>
		</div><!-- /.col-**-* -->
	</div>
	<div class="tab-content">
		<div class="tab-pane" id="WTS"></div>
		<div class="tab-pane" id="HNS"></div>
		<div class="tab-pane" id="Seen"></div>
	</div>
	<div class="modal fade" id="dialog" tabindex="-1" role="dialog" aria-labelledby="movie-title" aria-hidden="true">

	</div><!-- /.modal -->
<?php
// include html footer
include('footer.php');
