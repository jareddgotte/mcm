<?php

/**
 * A stand-in for TMDb, served out of the fixture's document root.
 *
 * This is not part of the application and deliberately does not load the
 * bootstrap: it plays the far end of a request, not this site. The suite points
 * MCM_TMDB_BASE_URL at it over the loopback interface, so every case about the
 * client's transport - what it sends, how long it waits, how much it reads,
 * what it does with a redirect - runs against a real socket without a network,
 * a real credential, or TMDb.
 *
 * The scenario is the path after this script's name, so the client's own path
 * building is what selects it:
 *
 *   /echo         200, reporting what the request actually carried
 *   /configuration 200 with a payload that quotes nothing from the request
 *   /slow         sleeps ?delay= seconds, then 200
 *   /large        200 with a body of ?bytes= bytes
 *   /redirect     302 pointing at /echo
 *   /status       ?code=, with a body holding ?marker=
 *   /notjson      200 whose body is not JSON
 *
 * The proxy in inc/tmdb_proxy.php builds its own paths, so the scenarios it
 * reaches are named the way TMDb names them:
 *
 *   /search/movie      a page of results
 *   /movie/<id>        one movie; ids 404, 429 and 500 answer with that status,
 *                      and 599 sleeps past any timeout a case would set
 *   /movie/<id>/videos that movie's videos
 *   /list/<id>         one list
 *
 * Every one of those payloads carries fields the proxy's projection does not
 * name, marked with 'upstream-only-marker', so a case can prove they stop here.
 *
 * Every request is also appended to tmdb_stub_requests.log beside this script,
 * one "METHOD path" line each. That file is how a case counts what really went
 * out - which is the only way to tell a cache hit from a cache miss, and the
 * only way to prove a refused request cost no outbound call.
 */

$path  = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
$query = $_GET;

@file_put_contents(
	__DIR__ . '/tmdb_stub_requests.log',
	(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '?') . ' '
		. (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '?') . "\n",
	FILE_APPEND
);

/** One movie, as a search result or a list item: the projected fields, and more besides. */
function mcm_stub_movie_summary($id, $title)
{
	return array(
		'id'             => $id,
		'title'          => $title,
		'original_title' => $title,
		'poster_path'    => '/poster' . $id . '.jpg',
		'release_date'   => '1999-10-15',
		// None of these is in any projection.
		'adult'          => false,
		'backdrop_path'  => '/backdrop-upstream-only-marker.jpg',
		'vote_average'   => 8.4,
		'video'          => false,
		'genre_ids'      => array(18, 53),
		'note'           => 'upstream-only-marker',
	);
}

/** Send a JSON body, and nothing a caller did not ask for. */
function mcm_stub_json(array $payload, $status = 200)
{
	http_response_code($status);
	header('Content-Type: application/json');
	echo json_encode($payload);
}

switch ($path) {
	case '/echo':
		mcm_stub_json(array(
			// What the client sent, reported back so a case can assert on it.
			'authorization' => isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '',
			'accept'        => isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '',
			'method'        => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
			'uri'           => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
			'query'         => $query,
			// Something recognisable, so a case can prove a body was decoded
			// and, separately, that it was cached where it says it was.
			'marker'        => 'stub-payload-marker',
		));
		break;

	case '/configuration':
		// Shaped like TMDb's own configuration response, and carrying nothing
		// from the request that asked for it.
		mcm_stub_json(array(
			'images' => array(
				'base_url'     => 'http://image.tmdb.org/t/p/',
				'poster_sizes' => array('w92', 'w154', 'w185'),
			),
			'marker' => 'stub-payload-marker',
		));
		break;

	case '/slow':
		$delay = isset($query['delay']) ? (float) $query['delay'] : 1.0;
		usleep((int) ($delay * 1000000));
		mcm_stub_json(array('marker' => 'stub-payload-marker'));
		break;

	case '/large':
		$bytes = isset($query['bytes']) ? (int) $query['bytes'] : 65536;
		http_response_code(200);
		header('Content-Type: application/json');
		// Written in chunks so the client meets the cap on an arriving body
		// rather than on one PHP happened to hand over whole.
		echo '{"padding":"';
		for ($written = 0; $written < $bytes; $written += 1024) {
			echo str_repeat('A', 1024);
			flush();
		}
		echo '"}';
		break;

	case '/redirect':
		http_response_code(302);
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '/echo');
		echo 'redirected';
		break;

	case '/status':
		$code   = isset($query['code']) ? (int) $query['code'] : 500;
		$marker = isset($query['marker']) ? (string) $query['marker'] : 'upstream-body-marker';
		http_response_code($code);
		header('Content-Type: application/json');
		echo json_encode(array('status_code' => 34, 'status_message' => $marker));
		break;

	case '/search/movie':
		mcm_stub_json(array(
			'page'          => isset($query['page']) ? (int) $query['page'] : 1,
			'total_pages'   => 3,
			'total_results' => 42,
			'results'       => array(
				mcm_stub_movie_summary(550, 'Fight Club'),
				mcm_stub_movie_summary(551, 'Fight Club II'),
			),
			// Neither of these is in the projection.
			'dates'  => array('minimum' => '1999-01-01'),
			'marker' => 'stub-payload-marker',
		));
		break;

	case '/notjson':
		http_response_code(200);
		header('Content-Type: text/plain');
		echo 'this is not json';
		break;

	default:
		if (preg_match('#^/movie/([0-9]+)$#', $path, $matches) === 1) {
			$id = (int) $matches[1];
			// A handful of identifiers stand for what an endpoint does rather
			// than for a film, so a case can reach an upstream status - or a
			// timeout - through the proxy's own fixed paths.
			if ($id === 404 || $id === 429 || $id === 500) {
				http_response_code($id);
				header('Content-Type: application/json');
				echo json_encode(array('status_code' => 34, 'status_message' => 'upstream-body-marker'));
				break;
			}
			if ($id === 599) {
				usleep(1500000);
			}

			$movie = mcm_stub_movie_summary($id, 'Fight Club');
			mcm_stub_json($movie + array(
				'imdb_id'  => 'tt0137523',
				'overview' => 'A ticking-time-bomb insomniac.',
				'runtime'  => 139,
				'genres'   => array(
					array('id' => 18, 'name' => 'Drama', 'note' => 'upstream-only-marker'),
				),
				// None of these is in the projection.
				'budget'                 => 63000000,
				'belongs_to_collection'  => null,
				'production_companies'   => array(array('id' => 508, 'name' => 'Regency')),
				'homepage'               => 'http://www.foxmovies.com/upstream-only-marker',
				'marker'                 => 'stub-payload-marker',
			));
			break;
		}

		if (preg_match('#^/movie/([0-9]+)/videos$#', $path, $matches) === 1) {
			mcm_stub_json(array(
				'id'      => (int) $matches[1],
				'results' => array(
					array(
						'id'        => '533ec654c3a36854480003eb',
						'key'       => 'BdJKm16Co6M',
						'name'      => 'Fight Club - Trailer',
						'site'      => 'YouTube',
						'size'      => 1080,
						'type'      => 'Trailer',
						'official'  => true,
						// Neither of these is in the projection.
						'iso_639_1'    => 'en',
						'published_at' => '2014-04-04T00:00:00.000Z',
						'note'         => 'upstream-only-marker',
					),
				),
				'marker' => 'stub-payload-marker',
			));
			break;
		}

		if (preg_match('#^/list/([0-9a-f]{24}|[0-9]+)$#', $path, $matches) === 1) {
			mcm_stub_json(array(
				'id'          => $matches[1],
				'name'        => 'A shared list',
				'description' => 'Films worth a second look.',
				'item_count'  => 2,
				'items'       => array(
					mcm_stub_movie_summary(101, 'Movie One'),
					mcm_stub_movie_summary(102, 'Movie Two'),
				),
				// None of these is in the projection.
				'created_by'   => 'somebody',
				'iso_639_1'    => 'en',
				'favorite_count' => 3,
				'marker'       => 'stub-payload-marker',
			));
			break;
		}

		mcm_stub_json(array('status_message' => 'unknown stub scenario'), 404);
		break;
}
