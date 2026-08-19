<?php

// Drives the proxy's execution half, mcm_tmdb_execute(), and its policy half,
// mcm_tmdb_resolve(), from a page with nobody signed in behind it. The two are
// separated by exactly one thing - what a refusal does - and this is where that
// is observed rather than read.
//
// MCM_TMDB_EXECUTE_MODE picks which half runs:
//
//   execute  every way mcm_tmdb_execute() can fail, each reported as a value,
//            and "reached_end=yes" last, which only prints if nothing exited
//   resolve  mcm_tmdb_resolve() for the same operation and the same absent
//            session, which must end the request before "reached_end" prints
require_once(__DIR__ . '/inc/bootstrap.php');
require_once(__DIR__ . '/inc/guards.php');
require_once(__DIR__ . '/inc/tmdb_proxy.php');

header('Content-Type: text/plain; charset=utf-8');

/** Report one result of either half. */
function mcm_execute_report($name, array $result)
{
	echo $name . '_ok=' . (empty($result['ok']) ? 'no' : 'yes') . "\n";
	echo $name . '_category=' . (isset($result['category']) ? $result['category'] : '') . "\n";
	echo $name . '_status=' . (isset($result['status']) ? $result['status'] : '') . "\n";
	echo $name . '_reason=' . (isset($result['reason']) && $result['reason'] !== '' ? 'present' : 'absent') . "\n";
	echo $name . '_title=' . (isset($result['data']['title']) ? $result['data']['title'] : '') . "\n";
}

// Nobody is signed in behind this page, which is the point of both modes.
echo 'signed_in=' . (mcm_is_logged_in() ? 'yes' : 'no') . "\n";

$mode = getenv('MCM_TMDB_EXECUTE_MODE');
if ($mode === 'resolve') {
	// search's caller policy is "user". The policy half has to refuse this and
	// end the request, so nothing below it prints.
	$result = mcm_tmdb_resolve('search', array('query' => 'fight club'));
	mcm_execute_report('resolve_search', $result);
	echo "reached_end=yes\n";
	exit();
}

// The same operation, the same absent session, through the execution half:
// served, because this half asks no policy question at all. A page reaches it
// only by having asked its own.
mcm_execute_report('search', mcm_tmdb_execute('search', array('query' => 'fight club')));

// An ordinary answer.
mcm_execute_report('movie', mcm_tmdb_execute('movie', array('movie_id' => 550)));

// Every way a plan can be refused, each one a value rather than an ending.
mcm_execute_report('no_such_operation', mcm_tmdb_execute('nonsense', array()));
mcm_execute_report('unknown_field', mcm_tmdb_execute('movie', array('movie_id' => 550, 'api_key' => 'x')));
mcm_execute_report('bad_value', mcm_tmdb_execute('movie', array('movie_id' => 'not-a-number')));
mcm_execute_report('missing_value', mcm_tmdb_execute('movie', array()));

// And an upstream failure, which was already a value on both halves.
mcm_execute_report('upstream', mcm_tmdb_execute('movie', array('movie_id' => 404)));

// Only prints if not one of the above ended the request.
echo "reached_end=yes\n";
