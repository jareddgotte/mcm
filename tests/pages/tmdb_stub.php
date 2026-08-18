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
 */

$path  = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
$query = $_GET;

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

	case '/notjson':
		http_response_code(200);
		header('Content-Type: text/plain');
		echo 'this is not json';
		break;

	default:
		mcm_stub_json(array('status_message' => 'unknown stub scenario'), 404);
		break;
}
