<?php

/**
 * Drives inc/tmdb_proxy.php's projectors against payloads TMDb would never
 * send, and reports what came out.
 *
 * The proxy groups in tests/cases.php answer "what does a real answer come back
 * as" by driving requests at a stub. This page answers the other half, which no
 * request can: what a projector does with a payload full of fields nobody named,
 * values of the wrong type, and collections longer than this site will repeat.
 * It needs no server and no database, so the list operation's projection is
 * covered here whether or not a developer has a database server to run.
 *
 * Each projection is printed as one line of JSON; the assertions live in
 * tests/cases.php, which reads the fields back out.
 */
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/tmdb_proxy.php');
require_once(__DIR__ . '/inc/dialog_trailers.php');

header('Content-Type: text/plain; charset=utf-8');

/** Print one projection as a line of JSON. */
function mcm_projection($name, $value)
{
	echo $name . '=' . json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
}

/** Fields no projection names, at every level a payload has one. */
function mcm_hostile_extras()
{
	return array(
		'note'            => 'upstream-only-marker',
		'adult'           => true,
		'backdrop_path'   => '/backdrop-upstream-only-marker.jpg',
		'vote_average'    => 8.4,
		'nested'          => array('deep' => array('deeper' => 'upstream-only-marker')),
		'status_message'  => 'upstream-only-marker',
		'api_key'         => 'upstream-only-marker',
		'authorization'   => 'Bearer upstream-only-marker',
	);
}

/* The five projections, each given more than it asked for. ----------------- */

mcm_projection('configuration', mcm_tmdb_project_configuration(array(
	'images' => array(
		'base_url'        => 'http://image.tmdb.org/t/p/',
		'secure_base_url' => 'https://image.tmdb.org/t/p/',
		'poster_sizes'    => array('w92', 'w154', 'original'),
		// Sizes this site will not repeat: not a string, not a bare word.
		'logo_sizes'      => array('w45'),
		'still_sizes'     => array('w92'),
	) + mcm_hostile_extras(),
	'change_keys' => array('adult', 'budget'),
) + mcm_hostile_extras()));

mcm_projection('configuration_hostile_sizes', mcm_tmdb_project_configuration(array(
	'images' => array(
		'base_url'     => 'http://image.tmdb.org/t/p/',
		'poster_sizes' => array('w92', '../../etc/passwd', array('w154'), 42, "w185\r\nX-Injected: 1", 'original'),
	),
)));

mcm_projection('configuration_empty', mcm_tmdb_project_configuration(array()));

mcm_projection('search', mcm_tmdb_project_search(array(
	'page'          => '2',
	'total_pages'   => 3,
	'total_results' => 42,
	'results'       => array(
		array(
			'id'             => 550,
			'title'          => 'Fight Club',
			'original_title' => 'Fight Club',
			'poster_path'    => '/poster.jpg',
			'release_date'   => '1999-10-15',
		) + mcm_hostile_extras(),
		// A row with no poster: the browser tells that apart by the null.
		array('id' => 551, 'title' => 'No Poster', 'original_title' => 'No Poster', 'poster_path' => null, 'release_date' => ''),
		// A row that is not a row at all.
		'upstream-only-marker',
	),
) + mcm_hostile_extras()));

mcm_projection('search_not_a_collection', mcm_tmdb_project_search(array(
	'page'    => 'one',
	'results' => 'upstream-only-marker',
) + mcm_hostile_extras()));

// One row past the cap, to prove the cap is the number this site says it is.
$mcm_many = array();
for ($mcm_row = 0; $mcm_row <= MCM_TMDB_MAX_ROWS; $mcm_row++) {
	$mcm_many[] = array('id' => $mcm_row + 1, 'title' => 'Row ' . $mcm_row, 'original_title' => '', 'poster_path' => null, 'release_date' => '');
}
mcm_projection('search_row_count', count(mcm_tmdb_project_search(array('results' => $mcm_many))['results']));

mcm_projection('movie', mcm_tmdb_project_movie(array(
	'id'             => 550,
	'title'          => 'Fight Club',
	'original_title' => 'Fight Club',
	'poster_path'    => '/poster.jpg',
	'release_date'   => '1999-10-15',
	'imdb_id'        => 'tt0137523',
	'overview'       => 'A ticking-time-bomb insomniac.',
	'runtime'        => '139',
	'genres'         => array(
		array('id' => 18, 'name' => 'Drama') + mcm_hostile_extras(),
		'upstream-only-marker',
	),
	'budget'               => 63000000,
	'production_companies' => array(array('id' => 508, 'name' => 'Regency')),
	'homepage'             => 'http://example.test/upstream-only-marker',
) + mcm_hostile_extras()));

mcm_projection('movie_empty', mcm_tmdb_project_movie(array()));

// A string longer than this site will repeat, so the cap can be read off the
// answer rather than taken on trust.
mcm_projection('movie_overview_length', strlen(mcm_tmdb_project_movie(array(
	'overview' => str_repeat('o', MCM_TMDB_MAX_TEXT_BYTES + 500),
))['overview']));

mcm_projection('videos', mcm_tmdb_project_videos(array(
	'id'      => 550,
	'results' => array(
		array(
			'id'       => '533ec654c3a36854480003eb',
			'key'      => 'BdJKm16Co6M',
			'name'     => 'Fight Club - Trailer',
			'site'     => 'YouTube',
			'type'     => 'Trailer',
			'size'     => 1080,
			'official' => true,
		) + mcm_hostile_extras(),
		// The old response's shape, which is exactly what must not come back:
		// no key, a word where the size is.
		array('name' => 'Old Trailer', 'source' => 'abc123', 'size' => 'HD', 'official' => 'no'),
	),
) + mcm_hostile_extras()));

mcm_projection('videos_empty', mcm_tmdb_project_videos(array('id' => 550, 'results' => array())));

mcm_projection('list', mcm_tmdb_project_list(array(
	'id'          => '5212934a760ee36af148407c',
	'name'        => 'A shared list',
	'description' => 'Films worth a second look.',
	'item_count'  => 2,
	'items'       => array(
		array(
			'id'             => 101,
			'title'          => 'Movie One',
			'original_title' => 'Movie One',
			'poster_path'    => '/one.jpg',
			'release_date'   => '2001-01-01',
		) + mcm_hostile_extras(),
		array(
			'id'             => 102,
			'title'          => 'Movie Two',
			'original_title' => 'Movie Two',
			'poster_path'    => null,
			'release_date'   => '2002-02-02',
		),
	),
	'created_by'     => 'upstream-only-marker',
	'favorite_count' => 3,
	'iso_639_1'      => 'en',
) + mcm_hostile_extras()));

mcm_projection('list_empty', mcm_tmdb_project_list(array()));

// A name that is markup, kept exactly as it arrived: escaping is the renderer's
// job and happens where the value lands, never here.
mcm_projection('list_markup_name', mcm_tmdb_project_list(array(
	'name'  => '<script>alert(1)</script>',
	'items' => array(),
)));

/*
 * The movie dialog's own selection over an already-projected videos answer
 * (issue #37): dialog.php never sees a row TMDb sent, only what
 * mcm_tmdb_project_videos() built, so these fixtures go through that
 * projector first, exactly as dialog.php does.
 */

mcm_projection('dialog_trailers', mcm_dialog_usable_trailers(mcm_tmdb_project_videos(array(
	'id'      => 550,
	'results' => array(
		// A YouTube teaser: usable, but ranked below any trailer.
		array('id' => '1', 'key' => 'teaser1', 'name' => 'Teaser', 'site' => 'YouTube', 'type' => 'Teaser', 'size' => 1080, 'official' => true),
		// The lower-resolution of two usable trailers.
		array('id' => '2', 'key' => 'trailer720', 'name' => 'Trailer 720p', 'site' => 'YouTube', 'type' => 'Trailer', 'size' => 720, 'official' => true),
		// The higher-resolution trailer: should sort ahead of both of the above.
		array('id' => '3', 'key' => 'trailer1080', 'name' => 'Trailer 1080p', 'site' => 'YouTube', 'type' => 'Trailer', 'size' => 1080, 'official' => true),
		// Not YouTube: dropped regardless of type.
		array('id' => '4', 'key' => 'vimeoclip', 'name' => 'Vimeo Clip', 'site' => 'Vimeo', 'type' => 'Trailer', 'size' => 1080, 'official' => true),
		// YouTube, but neither a trailer nor a teaser: dropped.
		array('id' => '5', 'key' => 'behind1', 'name' => 'Behind the Scenes', 'site' => 'YouTube', 'type' => 'Featurette', 'size' => 1080, 'official' => false),
		// The old response's shape: no key survives mcm_tmdb_project_videos(),
		// so this row is unusable no matter what mcm_dialog_usable_trailers()
		// does with it - the same row the projection fixture above proves
		// yields no key and no numeric size.
		array('name' => 'Old Trailer', 'source' => 'abc123', 'size' => 'HD', 'official' => 'no'),
	),
))['results']));

// No videos at all - what an unexpected or empty upstream answer projects to.
// The old code read $trailers['youtube'] straight off this shape and would
// have fataled on the missing key; this is the friendly empty state instead.
mcm_projection('dialog_trailers_empty', mcm_dialog_usable_trailers(mcm_tmdb_project_videos(array(
	'id'      => 550,
	'results' => array(),
))['results']));

// Videos exist, but none of them are a usable YouTube trailer or teaser.
mcm_projection('dialog_trailers_none_usable', mcm_dialog_usable_trailers(mcm_tmdb_project_videos(array(
	'id'      => 550,
	'results' => array(
		array('id' => '4', 'key' => 'vimeoclip', 'name' => 'Vimeo Clip', 'site' => 'Vimeo', 'type' => 'Trailer', 'size' => 1080, 'official' => true),
		array('id' => '5', 'key' => 'behind1', 'name' => 'Behind the Scenes', 'site' => 'YouTube', 'type' => 'Featurette', 'size' => 1080, 'official' => false),
	),
))['results']));
