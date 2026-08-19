<?php

/**
 * Which of a movie's proxied videos (inc/tmdb_proxy.php's 'videos' operation,
 * one row per array with 'site', 'type', 'key' and 'size') the trailer dialog
 * treats as usable, and in what order.
 *
 * A pure function on purpose: dialog.php feeds it whatever
 * mcm_tmdb_project_videos() produced, including an empty list on a refusal or
 * an upstream outage, and it is exercised directly by the suite with
 * hand-built rows - malformed, non-YouTube, non-trailer - without a server.
 *
 * @param array $video_rows as mcm_tmdb_project_videos()['results']
 * @return array usable rows, trailers before teasers and larger 'size' first
 */
function mcm_dialog_usable_trailers(array $video_rows)
{
	$usable = array_values(array_filter($video_rows, function ($video) {
		return is_array($video)
			&& isset($video['site'], $video['type'], $video['key'])
			&& $video['site'] === 'YouTube'
			&& ($video['type'] === 'Trailer' || $video['type'] === 'Teaser')
			&& is_string($video['key']) && $video['key'] !== '';
	}));

	// usort() is stable as of PHP 8, so two rows equal on both keys keep the
	// order mcm_tmdb_project_videos() returned them in.
	usort($usable, function ($a, $b) {
		$type_rank = ($a['type'] === 'Trailer' ? 0 : 1) <=> ($b['type'] === 'Trailer' ? 0 : 1);
		if ($type_rank !== 0) {
			return $type_rank;
		}
		$a_size = (isset($a['size']) && is_int($a['size'])) ? $a['size'] : -1;
		$b_size = (isset($b['size']) && is_int($b['size'])) ? $b['size'] : -1;
		return $b_size <=> $a_size;
	});

	return $usable;
}

/**
 * The trailer dialog's markup for one movie: an accordion of usable trailers,
 * or the friendly empty state.
 *
 * A pure function of mcm_dialog_usable_trailers()'s own output, so dialog.php
 * only has to call the two of them in sequence and the suite can drive this
 * one directly with hand-built rows - including a hostile name and key - to
 * prove the exact panel, iframe and empty-state markup without a server or a
 * browser.
 *
 * @param array $usable_trailers as mcm_dialog_usable_trailers() returns
 * @return string HTML, an accordion of panels or the "No trailer" alert
 */
function mcm_dialog_trailer_html(array $usable_trailers)
{
	if (count($usable_trailers) === 0) {
		return '<div class="alert alert-warning"><strong>No trailer available.</strong></div>';
	}

	$html = '<div class="panel-group" id="accordion">';
	foreach ($usable_trailers as $k => $v) {
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
		$html .= sprintf($tmp, (int) $k, mcm_html($v['type']), mcm_html($v['name']), (int) $k, ($k == 0) ? ' in' : '', mcm_url($v['key']));
	}
	$html .= '</div>';

	return $html;
}
