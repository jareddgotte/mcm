<?php

//$time1 = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];

require_once('inc/php-login.php');
session_start();


if (!isset($_SESSION['tmdb_obj'])) {
	$_SESSION['tmdb_obj'] = new TMDb(TMDB_API_KEY);
}

$movie_id = (isset($_POST['id'])) ? $_POST['id'] : ((isset($_GET['id'])) ? $_GET['id'] : '');

//$timeconstruct = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];

// Get the tmdb config so we can pass it onto the TMDbDisplay class for images
if (!isset($_SESSION['tmdb_config'])) {
	$_SESSION['tmdb_config'] = $_SESSION['tmdb_obj']->getConfiguration();
}
$base_url = $_SESSION['tmdb_config']['images']['base_url'];
$poster_size =  $_SESSION['tmdb_config']['images']['poster_sizes'][2];

$movie = $_SESSION['tmdb_obj']->getMovie($movie_id);
$title = $movie['original_title'];
$genres = $movie['genres'];
$imdb = $movie['imdb_id'];
$overview = $movie['overview'];
$release_date = $movie['release_date'];
$runtime = $movie['runtime'];

function cmp ($a, $b) {
	$ranks = array("Standard", "HQ", "HD"); // HD > HQ > Standard
	$al = array_search($a['size'], $ranks);
	$bl = array_search($b['size'], $ranks);
	if ($al == $bl) {
		return 0;
	}
	return ($al < $bl) ? -1 : 1;
}

$trailers = $_SESSION['tmdb_obj']->getMovieTrailers($movie_id);
$yt_trailers = $trailers['youtube'];
usort($yt_trailers, "cmp");
$yt_trailers = array_reverse($yt_trailers);

if (count($yt_trailers) > 0) {
	$trailer_html = '<div class="panel-group" id="accordion">';
	foreach ($yt_trailers as $k => $v) {
		$tmp = '
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title">
						<a data-toggle="collapse" data-parent="#accordion" href="#collapse%s"><strong>%s</strong>, %s</a>
					</h3>
				</div>
				<div id="collapse%s" class="panel-collapse collapse%s">
					<div class="panel-body">
						<img class="trailer-scale" src="img/trailer-scale.png" alt="">
						<iframe width="100%%" height="100%%" src="//www.youtube.com/embed/%s?autoplay=0&rel=0" frameborder="0" allowfullscreen></iframe>
					</div>
				</div>
			</div>
		';
		$trailer_html .= sprintf($tmp, $k, $v['size'], $v['name'], $k, ($k == 0) ? ' in' : '',substr($v['source'], 0, (strpos($v['source'], '&') != FALSE) ? strpos($v['source'], '&') : strlen($v['source'])));
	}
	$trailer_html .= '</div>';
}
else $trailer_html = '<div class="alert alert-warning"><strong>No trailer available.</strong></div>';

$genress = '';
for ($i = 0; $i < count($genres); $i++) {
	$genress .= $genres[$i]['name'];
	if ($i + 1 < count($genres)) $genress .= ' | ';
}

echo '
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
				<h4 class="modal-title" id="movie-title">' . $title . ' <small class="hide" id="movie-id">' . $movie_id . '</small></h4>
			</div>
			<div class="modal-body">
';
$details = '
	<div class="row">
		<div class="col-xs-2" style="padding:0">
			<a href="http://www.imdb.com/title/%s/" target="_blank"><img class="img-responsive" id="imdb-icon" src="img/imdb-icon.png" alt="IMDb" /></a>
		</div>
		<div class="col-xs-10" style="padding-left:10px">
			<div class="row">
				<div class="col-xs-12">
					<div class="row">
						<div class="col-xs-3" style="padding-right:0"><b>Runtime</b></div>
						<div class="col-xs-3" style="padding-right:0"><b>Released</b></div>
						<div class="col-xs-6" style="padding-right:0"><b>Genres</b></div>
					</div>
					<div class="row">
						<div class="col-xs-3" style="padding-right:0">%s <small>mins</small></div>
						<div class="col-xs-3" style="padding-right:0"><abbr title="%s">%s</abbr></div>
						<div class="col-xs-6" style="padding-right:0">%s</div>
					</div>
				</div>
			</div>
		</div>
	</div>
';
printf($details, $imdb, $runtime, date_format(date_create($release_date), 'F j, Y'), substr($release_date, 0, 4), $genress);

echo $trailer_html;
echo '<span><a href="https://www.youtube.com/results?search_query=' . str_replace(' ', '+', $title) . '+' . substr($release_date, 0, 4) . '+Trailer" target="_blank">Search for ' . ((count($yt_trailers) > 0) ? 'more trailers' : 'a trailer') . ' on YouTube.</a></span>';
echo '
				<div class="panel panel-default" id="overview-content">
					<div class="panel-heading">
						<button class="close" aria-hidden="true" id="overview-content-close">&times;</button>
						<h3 class="panel-title">' . $title . '</h3>
					</div>
					<div class="panel-body">
						' . htmlentities($overview) . '
					</div>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-default pull-left" id="overview" type="button">Movie Overview</button>
				<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
				<div class="btn-group dropup">
					<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown">Options<span class="caret"></span></button>
					<ul class="dropdown-menu pull-right text-left" id="movie-options">
					</ul>
				</div>
			</div>
';

//echo "<br>".$_POST['id'];
//$totaltime = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];
//printf("<br>%s %s %s", $time1, $timeconstruct, $totaltime);
//echo "<pre>";var_dump($movie);echo "</pre>";
//echo "<pre>";var_dump($yt_trailers);echo "</pre>";
