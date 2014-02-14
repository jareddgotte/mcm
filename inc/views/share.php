<?php

if (!isset($_SESSION['db_lists'])) $_SESSION['db_lists'] = $db_lists;

// In a perfect world, this call is only made once per session
if (!isset($_SESSION['tmdb_obj'])) {
	$_SESSION['tmdb_obj'] = new TMDb(TMDB_API_KEY);
}

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
$query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
if ($query->execute() === FALSE) {
	$errorInfo = $query->errorInfo();
	$errors[] = 'Execute error: ' . $errorInfo[2];
}

$movie_lists = array();
while ($row = $query->fetch(PDO::FETCH_OBJ)) {
	//printf("%s %s %s\n", $row->list_rank, $row->movie_list_id, $row->share);
	if ($row->share) $movie_lists[(int)$row->list_rank] = array($row->movie_list_id, $row->list_name, $row->list_description);
}
ksort($movie_lists);
//var_dump($movie_lists);

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

// include html header and display php-login message/error
$title = 'My Collection';
$post_styles = array('tabdrop', 'typeahead.js-bootstrap', 'mc');
$post_scripts = array('bootstrap-tabdrop', 'jquery.lazyload.min', 'libs/handlebars.min', 'typeahead.bundle.min', 'share'); // , 'jquery.sortable'
$script = "
//console.log('" . ""/*serialize($_SESSION)*/ . "'); // debug my session variable
//console.log('" . ""/*count($merged)*/ . "'); // debug how many movies I have

var db = " . $db_var . "
//console.log(db)
var base_url = '" . $base_url . "'
var poster_size_big = '" . $poster_size . "'
var poster_size_small = '" . $_SESSION['tmdb_config']['images']['poster_sizes'][0] . "'

// Variables to record whether or not we've loaded the table yet or not.  This is to prevent multiple loadings of each table if we keep going back and forth between tabs
//console.log(db.length)
if (db.length > 0) {
	var currentList = db[0].list_id
	var currentListPos = listPos(currentList)
}
var currentSort = checkSortOrder('sort') // = 'name'
var currentOrder = checkSortOrder('order') // = 'asc'

$(function() {
	//$('.navbar').append('<div id=\"reso\"style=\"float: right; padding: 2px\"></div>')
	$('.tab-pane img:first-child').bind('transitionend webkitTransitionEnd oTransitionEnd', function(e) {
		//console.log(e.originalEvent.propertyName)
		if (e.originalEvent.propertyName === 'width') {
			$(window).trigger('scroll')
		}
	})
	$(window).on('resize', function () {
		var cw = $('.container').outerWidth()
		var m = 5
		var w = 195
		var n = Math.round((cw+2*m-30)/(2*m+w))
		
		for (var i = 0; i <= 5; i++) {
			m += (i % 2 === 0)? i : -i
			w = (cw+2*m-30)/n-2*m
			if (w % 1 === 0) {
				break
			}
		}
		w = Math.floor(w)
		h = Math.round((w-10)*278/185+10)
		//$('#reso').html('n:' + n + ' m:' + m + ' w:' + w + ' h:' + h + ' ' + cw)
		$('.tab-pane img').css('width', w + 'px').css('height', h + 'px').css('margin', m + 'px')
		$('.tab-pane .posters').css('margin', '0 -' + m + 'px')
	})
	setTimeout(function() { $(window).trigger('resize') }, 1)
})
";

$title = 'Share';
$sharing = true;
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
		<div class="col-xs-12 col-sm-8"><input type="text" class="form-control" id="search_collection" placeholder="Search My Collection"></div>
		<div class="col-xs-12 col-sm-4">
			<div class="btn-group btn-block">
				<button type="button" class="btn btn-default btn-block dropdown-toggle" data-toggle="dropdown">View <span class="caret"></span></button>
				<ul class="dropdown-menu btn-block pull-right" id="sort-order">
					<li class="dropdown-header">Sort by</li>
					<li><a class="sort" href="#name" id="name">Name</a></li>
					<li><a class="sort" href="#date" id="date">Release Date</a></li>
					<li class="divider"></li>
					<li class="dropdown-header">Order by</li>
					<li><a class="order" href="#asc" id="asc">Ascending</a></li>
					<li><a class="order" href="#desc" id="desc">Descending</a></li>
				</ul>
			</div>
		</div><!-- /.col-**-* -->
	</div>
	<div class="tab-content" id="list-containers">
		<?php echo $list_containers; ?>
	</div>
	<div class="modal fade" id="dialog" tabindex="-1" aria-hidden="true">
	</div><!-- /.modal -->
<?php
// include html footer
include('footer.php');
