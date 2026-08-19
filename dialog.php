<?php

//$time1 = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];

require_once(__DIR__ . '/inc/bootstrap.php');
require_once('inc/php-login.php');
require_once(__DIR__ . '/inc/tmdb_proxy.php');
require_once(__DIR__ . '/inc/dialog_trailers.php');


$movie_id = (isset($_POST['id'])) ? $_POST['id'] : ((isset($_GET['id'])) ? $_GET['id'] : '');

//$timeconstruct = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];

// 'configuration', 'movie' and 'videos' are all open to any session, so no
// login/ownership guard applies here - mcm_tmdb_plan()/mcm_tmdb_run() are the
// same seam mcm_tmdb_serve() uses, just called directly instead of over HTTP.
// A refusal or an upstream failure degrades to the projector's own empty
// shape rather than ending the request, the way the old wrapper's calls never
// threw either.
if (!isset($_SESSION['tmdb_config'])) {
	$config_plan = mcm_tmdb_plan('configuration', array());
	$config_result = (!empty($config_plan['ok'])) ? mcm_tmdb_run($config_plan) : array('ok' => false);
	$_SESSION['tmdb_config'] = (!empty($config_result['ok'])) ? $config_result['data'] : mcm_tmdb_project_configuration(array());
}
$base_url = $_SESSION['tmdb_config']['images']['base_url'];
$poster_size =  $_SESSION['tmdb_config']['images']['poster_sizes'][2];

$movie_plan = mcm_tmdb_plan('movie', array('movie_id' => $movie_id));
$movie_result = (!empty($movie_plan['ok'])) ? mcm_tmdb_run($movie_plan) : array('ok' => false);
$movie = (!empty($movie_result['ok'])) ? $movie_result['data'] : mcm_tmdb_project_movie(array());
$title = $movie['original_title'];
$genres = $movie['genres'];
$imdb = $movie['imdb_id'];
$overview = $movie['overview'];
$release_date = $movie['release_date'];
$runtime = $movie['runtime'];

$videos_plan = mcm_tmdb_plan('videos', array('movie_id' => $movie_id));
$videos_result = (!empty($videos_plan['ok'])) ? mcm_tmdb_run($videos_plan) : array('ok' => false);
$video_rows = (!empty($videos_result['ok']) && isset($videos_result['data']['results']))
	? $videos_result['data']['results']
	: array();

$yt_trailers = mcm_dialog_usable_trailers($video_rows);
$trailer_html = mcm_dialog_trailer_html($yt_trailers);

// $genress is markup once it leaves this loop, so each name is escaped as it
// goes in and the separator stays a literal separator.
$genress = '';
for ($i = 0; $i < count($genres); $i++) {
	$genress .= mcm_html($genres[$i]['name']);
	if ($i + 1 < count($genres)) $genress .= ' | ';
}

printf('
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
				<h4 class="modal-title" id="movie-title">%s <small class="hide" id="movie-id">%s</small></h4>
			</div>
			<div class="modal-body">
', mcm_html($title), mcm_html($movie_id));
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
// The IMDb id is one path segment of the link; the rest is text, and $genress
// was escaped as it was assembled.
printf($details, mcm_url($imdb), mcm_html($runtime), mcm_html(date_format(date_create($release_date), 'F j, Y')), mcm_html(substr($release_date, 0, 4)), $genress);

echo $trailer_html;
// The title is a query-string value here rather than text, so it is encoded for
// a URL instead of for HTML.
printf(
	'<span><a href="https://www.youtube.com/results?search_query=%s+%s+Trailer" target="_blank">Search for %s on YouTube.</a></span>',
	mcm_url($title),
	mcm_url(substr($release_date, 0, 4)),
	(count($yt_trailers) > 0) ? 'more trailers' : 'a trailer'
);
printf('
				<div class="panel panel-default" id="overview-content">
					<div class="panel-heading">
						<button class="close" aria-hidden="true" id="overview-content-close">&times;</button>
						<h3 class="panel-title">%s</h3>
					</div>
					<div class="panel-body">
						%s
					</div>
				</div>', mcm_html($title), mcm_html($overview));
echo '
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
