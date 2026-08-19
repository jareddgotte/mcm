<?php

//$time1 = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];

require_once(__DIR__ . '/inc/bootstrap.php');
require_once('inc/php-login.php');
require_once(__DIR__ . '/inc/tmdb_proxy.php');
require_once(__DIR__ . '/inc/dialog_trailers.php');


// The wrapper carries the TMDb credential, so it is built for this request and
// thrown away with it. It used to be kept in $_SESSION, which put the key in
// the session store on disk for every visitor who ever opened a movie.
$tmdb = new TMDb(TMDB_API_KEY);

$movie_id = (isset($_POST['id'])) ? $_POST['id'] : ((isset($_GET['id'])) ? $_GET['id'] : '');

//$timeconstruct = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];

// Get the tmdb config so we can pass it onto the TMDbDisplay class for images
if (!isset($_SESSION['tmdb_config'])) {
	$_SESSION['tmdb_config'] = $tmdb->getConfiguration();
}
$base_url = $_SESSION['tmdb_config']['images']['base_url'];
$poster_size =  $_SESSION['tmdb_config']['images']['poster_sizes'][2];

$movie = $tmdb->getMovie($movie_id);
$title = $movie['original_title'];
$genres = $movie['genres'];
$imdb = $movie['imdb_id'];
$overview = $movie['overview'];
$release_date = $movie['release_date'];
$runtime = $movie['runtime'];

// 'videos' is open to any session, so no login/ownership guard applies here -
// mcm_tmdb_plan()/mcm_tmdb_run() are the same seam mcm_tmdb_serve() uses, just
// called directly instead of over HTTP.
$videos_plan = mcm_tmdb_plan('videos', array('movie_id' => $movie_id));
$videos_result = (!empty($videos_plan['ok'])) ? mcm_tmdb_run($videos_plan) : array('ok' => false);
$video_rows = (!empty($videos_result['ok']) && isset($videos_result['data']['results']))
	? $videos_result['data']['results']
	: array();

$yt_trailers = mcm_dialog_usable_trailers($video_rows);

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
		// Everything below the format string comes from TMDb: the labels are text,
		// and 'key' is already the bare YouTube video id.
		$trailer_html .= sprintf($tmp, (int) $k, mcm_html($v['type']), mcm_html($v['name']), (int) $k, ($k == 0) ? ' in' : '', mcm_url($v['key']));
	}
	$trailer_html .= '</div>';
}
else $trailer_html = '<div class="alert alert-warning"><strong>No trailer available.</strong></div>';

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
