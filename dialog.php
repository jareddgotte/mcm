<?php
	//$time1 = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];

	require_once('inc/config.php');
	require_once('inc/TMDb.inc'); // https://github.com/glamorous/TMDb-PHP-API
	session_start();

	
	if (!isset($_SESSION['tmdb_obj'])) {
		$_SESSION['tmdb_obj'] = new TMDb(TMDB_API_KEY);
	}

	//$timeconstruct = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];
	

	// Get the tmdb config so we can pass it onto the TMDbDisplay class for images
	if (!isset($_SESSION['tmdb_config'])) {
		$_SESSION['tmdb_config'] = $_SESSION['tmdb_obj']->getConfiguration();
	}
	$base_url = $_SESSION['tmdb_config']['images']['base_url'];
	$poster_size =  $_SESSION['tmdb_config']['images']['poster_sizes'][1];
	
	$movie = $_SESSION['tmdb_obj']->getMovie($_POST['id']);
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
	
	$trailers = $_SESSION['tmdb_obj']->getMovieTrailers($_POST['id']);
	$yt_trailers = $trailers['youtube'];
	usort($yt_trailers, "cmp");
	$yt_trailers = array_reverse($yt_trailers);

	if (count($yt_trailers) > 0) {
		$trailer_html = '<div id="accordion">';
		foreach ($yt_trailers as $v) {
			$trailer_html .= sprintf("<h3><strong>%s</strong>, %s</h3><div><iframe width=\"100%%\" height=\"100%%\" src=\"//www.youtube.com/embed/%s?autoplay=0&rel=0\" frameborder=\"0\" allowfullscreen></iframe></div>", $v['size'], $v['name'], substr($v['source'], 0, (strpos($v['source'], '&') != FALSE) ? strpos($v['source'], '&') : strlen($v['source'])));
		}
		$trailer_html .= '</div>';
	}
	else $trailer_html = "<div id=\"noTrailer\">No trailer available.</div>";
	
	$genress = '';
	for ($i = 0; $i < count($genres); $i++) {
		$genress .= $genres[$i]['name'];
		if ($i + 1 < count($genres)) $genress .= ' - ';
	}
		
	printf("<div id=\"details\" class=\"row\"><a href=\"http://www.imdb.com/title/%s/\" target=\"_blank\"><img src=\"img/imdb-icon.png\" /></a> %s mins | %s | %s</div><div id=\"overview\" class=\"row hidden\">%s</div>", $imdb, $runtime, $genress, $release_date, $overview );
	
	echo $trailer_html;
	echo "<br /><a class=\"trailer\" href=\"https://www.youtube.com/results?search_query=" . str_replace(" ", "+", "$title") . "+Trailer\" target=\"_blank\">Search for a trailer manually on YouTube.</a>";
	if ($_SESSION['logged_in'])
		echo "<div id=\"icons\"><img id=\"WTS\" src=\"img/wts-icon.png\" alt=\"Add to 'Want to See' list.\" /><img id=\"HNS\" src=\"img/hns-icon.png\" alt=\"Add to 'Haven't See' list.\" /><img id=\"Seen\" src=\"img/seen-icon.png\" alt=\"Add to 'Already Seen' list.\" /></div>";
	//echo "<br>".$_POST['id'];
	//$totaltime = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];
	//printf("<br>%s %s %s", $time1, $timeconstruct, $totaltime);
	//echo "<pre>";var_dump($movie);echo "</pre>";
	//echo "<pre>";var_dump($yt_trailers);echo "</pre>";
?>

