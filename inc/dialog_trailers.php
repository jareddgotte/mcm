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
