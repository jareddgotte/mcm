<?php

/**
 * The TMDb proxy: five read-only operations, and nothing else.
 *
 * inc/tmdb.php knows how to make one bounded request to TMDb. This file is what
 * decides which requests this site is willing to make at all, and what of an
 * answer it is willing to repeat. tmdb.php in the document root is the entry
 * point; everything it does is declared here, so the policy can be read - and
 * tested - without a request being made.
 *
 * Four rules shape it.
 *
 *   1. The operation is a name from a fixed list, never a URL. A caller says
 *      "movie" and a movie identifier; this file says which path that is, which
 *      query goes with it and which fields come back. A host, a path, a method,
 *      an extra query field, a credential or a transport option cannot be asked
 *      for, because there is no request field that carries one: a request that
 *      names a field this operation does not accept is refused rather than
 *      having the extra field ignored.
 *
 *   2. Every accepted value is validated and normalised before anything goes
 *      out. A refusal therefore costs no outbound request, exactly as a guard's
 *      refusal in inc/guards.php costs no query.
 *
 *   2a. Read-only is not the same as public. Each operation declares who may
 *      ask it, because this site's credential and this site's request budget
 *      are what answer it. The configuration, a movie and a movie's videos are
 *      open to any session: the public sharing page needs the first to build a
 *      poster URL and is not signed in. Search needs a signed-in caller, so the
 *      endpoint cannot be used as somebody else's free search API. A list needs
 *      a signed-in caller and the local list they mean to import into, and that
 *      list has to be theirs - checked before TMDb is asked for anything, so a
 *      refusal costs no request. The order is inc/guards.php's own: the session
 *      answers first and only then is a connection opened, and a refusal is the
 *      same 403 whether the list belongs to somebody else or to nobody.
 *
 *   3. An answer is rebuilt rather than forwarded. Each operation names the
 *      fields the existing MCM pages actually use, and the projector copies
 *      those and only those into a new array, one type at a time. A field TMDb
 *      adds tomorrow reaches nobody, and neither does an upstream error body.
 *
 *   4. A failure is a category and a fixed sentence - inc/tmdb.php's catalogue,
 *      answered with a status - and the reason goes to the log through
 *      mcm_log(). No upstream body, URL, cache path or credential is in a
 *      response, ever.
 *
 * The configuration operation is cached for a day, shared between every visitor
 * and every process, because it is the same answer for everybody and changes
 * about never. The cache holds the projection, not the upstream body, so even
 * the file on disk carries nothing that could not be served.
 *
 * Three things use it, by two doors. The add-a-movie type-ahead in js/mc.js asks
 * tmdb.php for the search operation over HTTP, which is what took the credential
 * out of the browser. import_list.php calls mcm_tmdb_resolve() in its own
 * process instead of making an HTTP request to this site's own server, so the
 * operation's questions - who is asking, what they asked for, whose list it is -
 * are asked of it exactly as they are asked of a browser. add_movie.php has
 * already asked all of those with the guards by the time it needs a film's
 * details, and calls mcm_tmdb_execute() for the execution half alone. The
 * trailer modal reads its videos through tmdb.php.
 */

// The client, the configuration and mcm_log() come from inc/tmdb.php and the
// bootstrap behind it; the bounded refusal bodies and the shared value checks
// come from inc/guards.php. Requiring both here is a no-op for an entry point
// that has already loaded them.
require_once(dirname(__FILE__) . '/tmdb.php');
require_once(dirname(__FILE__) . '/guards.php');

/** The request field naming the operation. */
define('MCM_TMDB_OPERATION_FIELD', 'operation');

/** How long a cached configuration answer may be served for: one day. */
define('MCM_TMDB_CONFIG_CACHE_SECONDS', 86400);

/** The largest numeric identifier this site will ask TMDb about. */
define('MCM_TMDB_MAX_IDENTIFIER', 2147483647);

/** The longest search phrase this site will forward, in bytes. */
define('MCM_TMDB_MAX_QUERY_BYTES', 200);

/** The last result page this site will ask for; TMDb serves no more than this. */
define('MCM_TMDB_MAX_PAGE', 500);

/** The longest single string any projection will repeat, in bytes. */
define('MCM_TMDB_MAX_TEXT_BYTES', 4096);

/** How many rows a projected collection may hold, per collection. */
define('MCM_TMDB_MAX_ROWS', 1000);

/** How large a cache file may be before it is treated as not worth keeping. */
define('MCM_TMDB_CACHE_MAX_BYTES', 65536);

/*
 * ---------------------------------------------------------------------------
 * The allowlist
 * ---------------------------------------------------------------------------
 */

/**
 * The operations this site exposes, and everything that is true of each.
 *
 * "accepts" is the whole of what a request may carry besides the operation
 * name: a field that is not listed here is refused, so no request field can
 * become a query parameter, a path segment or a transport setting. "required"
 * says which of them a request must carry. "caller" says who may ask it at all
 * - "any" session, a signed-in "user", or the "owner" of the local list the
 * request names. "plan" turns the validated values into the path and query one
 * request is made with, and "project" turns the answer into the fields this
 * site's own pages read.
 *
 * @return array operation => array
 */
function mcm_tmdb_operations()
{
	return array(
		// The image base URL and poster sizes every page needs to build a
		// poster URL. The one cached operation: it is the same answer for
		// everybody.
		'configuration' => array(
			'accepts'  => array(),
			'required' => array(),
			'caller'   => 'any',
			'plan'     => 'mcm_tmdb_plan_configuration',
			'project'  => 'mcm_tmdb_project_configuration',
			'cached'   => true,
		),
		// The add-a-movie type-ahead. Signed in: this site's credential and this
		// site's request budget are what answer a search.
		'search' => array(
			'accepts'  => array('query', 'page'),
			'required' => array('query'),
			'caller'   => 'user',
			'plan'     => 'mcm_tmdb_plan_search',
			'project'  => 'mcm_tmdb_project_search',
			'cached'   => false,
		),
		// The movie dialog's own details. Open to any session, because the
		// dialog opens from the public sharing page as well as from a
		// collection.
		'movie' => array(
			'accepts'  => array('movie_id'),
			'required' => array('movie_id'),
			'caller'   => 'any',
			'plan'     => 'mcm_tmdb_plan_movie',
			'project'  => 'mcm_tmdb_project_movie',
			'cached'   => false,
		),
		// The trailers in that dialog, from TMDb's current videos response, and
		// open to whoever the dialog is.
		'videos' => array(
			'accepts'  => array('movie_id'),
			'required' => array('movie_id'),
			'caller'   => 'any',
			'plan'     => 'mcm_tmdb_plan_videos',
			'project'  => 'mcm_tmdb_project_videos',
			'cached'   => false,
		),
		// List import. The request names the TMDb list to read and the local
		// list it is meant for, and the local one has to belong to whoever is
		// asking - settled before TMDb is asked anything. This is what
		// import_list.php reads a list through.
		'list' => array(
			'accepts'  => array('list_id', 'movie_list_id'),
			'required' => array('list_id', 'movie_list_id'),
			'caller'   => 'owner',
			'plan'     => 'mcm_tmdb_plan_list',
			'project'  => 'mcm_tmdb_project_list',
			'cached'   => false,
		),
	);
}

/**
 * Whether a name is one of the five operations.
 *
 * @param mixed $operation
 * @return bool
 */
function mcm_tmdb_operation_exists($operation)
{
	if (!is_string($operation)) {
		return false;
	}
	$operations = mcm_tmdb_operations();

	return isset($operations[$operation]);
}

/*
 * ---------------------------------------------------------------------------
 * The values a request may carry
 * ---------------------------------------------------------------------------
 *
 * One function per accepted field, each answering the same way: the normalised
 * value, or null when the request may not carry that. Null is the only failure
 * there is - the caller learns which field was refused, and never which of the
 * ways it could have been wrong it actually was.
 */

/**
 * A TMDb numeric identifier, or null.
 *
 * mcm_positive_int() is the shared check every identifier in this application
 * goes through; the upper bound is this file's, and it is there because an
 * identifier larger than TMDb hands out is a request nobody meant to make.
 *
 * @param mixed $value
 * @return int|null
 */
function mcm_tmdb_identifier($value)
{
	$number = mcm_positive_int($value);

	return ($number !== null && $number <= MCM_TMDB_MAX_IDENTIFIER) ? $number : null;
}

/**
 * A TMDb list identifier, or null.
 *
 * TMDb has issued two shapes of list identifier: the 24-character hexadecimal
 * one this site's own stored lists still use, and a plain number. Both are
 * accepted; anything else - a path, a URL, a traversal - is not, and the
 * accepted shapes are exactly the characters a path segment may hold.
 *
 * @param mixed $value
 * @return string|null
 */
function mcm_tmdb_list_identifier($value)
{
	if (is_int($value)) {
		$value = (string) $value;
	}
	if (!is_string($value)) {
		return null;
	}

	$value = trim($value);
	if (preg_match('/^[0-9a-f]{24}$/', $value) === 1) {
		return $value;
	}
	if (preg_match('/^[0-9]{1,12}$/', $value) === 1 && (int) $value > 0) {
		return $value;
	}

	return null;
}

/**
 * A search phrase, or null.
 *
 * Bounded in three ways, because this is the one value a person types and the
 * only one that reaches TMDb as text: it has to be valid UTF-8, it may hold no
 * control characters, and it is capped in bytes. The cap is on bytes rather
 * than characters because bytes are what a URL, a log line and a request budget
 * are actually made of.
 *
 * @param mixed $value
 * @return string|null
 */
function mcm_tmdb_search_query($value)
{
	if (!is_string($value)) {
		return null;
	}

	$value = trim($value);
	if ($value === '' || strlen($value) > MCM_TMDB_MAX_QUERY_BYTES) {
		return null;
	}
	// //u makes the match itself fail on a malformed sequence, which is how an
	// invalid encoding is refused rather than forwarded.
	if (preg_match('//u', $value) !== 1) {
		return null;
	}
	if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
		return null;
	}

	return $value;
}

/**
 * A result page number, or null. Absent means the first page.
 *
 * @param mixed $value
 * @return int|null
 */
function mcm_tmdb_page($value)
{
	$number = mcm_positive_int($value);

	return ($number !== null && $number <= MCM_TMDB_MAX_PAGE) ? $number : null;
}

/**
 * The check one accepted field goes through.
 *
 * @param string $field
 * @param mixed  $value
 * @return mixed the normalised value, or null
 */
function mcm_tmdb_value($field, $value)
{
	switch ($field) {
		case 'movie_id':
			return mcm_tmdb_identifier($value);
		case 'list_id':
			return mcm_tmdb_list_identifier($value);
		case 'query':
			return mcm_tmdb_search_query($value);
		case 'page':
			return mcm_tmdb_page($value);
		case 'movie_list_id':
			// One of this site's own list identifiers rather than a TMDb one, so
			// it is the same check every other endpoint makes of one. Whose list
			// it is is a different question, asked later and against the
			// database; this only settles that the value could be a list at all.
			return mcm_positive_int($value);
	}

	// A field with no check is a field this file does not know about, and an
	// unchecked value is exactly what must never reach a request.
	return null;
}

/*
 * ---------------------------------------------------------------------------
 * What one request will be
 * ---------------------------------------------------------------------------
 */

/** @return array path and query for the configuration operation */
function mcm_tmdb_plan_configuration(array $values)
{
	return array('path' => '/configuration', 'query' => array());
}

/** @return array path and query for a movie search */
function mcm_tmdb_plan_search(array $values)
{
	return array(
		'path'  => '/search/movie',
		'query' => array(
			'query' => $values['query'],
			'page'  => isset($values['page']) ? $values['page'] : 1,
			// This site's decision, not the caller's: there is no request field
			// that could turn it off.
			'include_adult' => 'false',
		),
	);
}

/** @return array path and query for one movie's details */
function mcm_tmdb_plan_movie(array $values)
{
	return array('path' => '/movie/' . $values['movie_id'], 'query' => array());
}

/** @return array path and query for one movie's videos */
function mcm_tmdb_plan_videos(array $values)
{
	return array('path' => '/movie/' . $values['movie_id'] . '/videos', 'query' => array());
}

/** @return array path and query for one list */
function mcm_tmdb_plan_list(array $values)
{
	return array('path' => '/list/' . $values['list_id'], 'query' => array());
}

/**
 * Turn a request into the one request it is allowed to become.
 *
 * Pure: it sends nothing, opens nothing and ends nothing. Either it hands back
 * the operation, the path and the query, or it hands back the reason it will
 * not - a fixed phrase and, where a value was involved, that value bounded by
 * mcm_log_detail() for the log. The caller decides what to do about it, which
 * is what lets the whole of this decision be tested without a network.
 *
 * @param mixed $operation the requested operation name
 * @param mixed $request   the request's own fields
 * @return array ok, and either operation/path/query/values or reason
 */
function mcm_tmdb_plan($operation, $request)
{
	if (!is_array($request)) {
		return array('ok' => false, 'reason' => 'the request carried no fields');
	}
	if (!mcm_tmdb_operation_exists($operation)) {
		return array('ok' => false, 'reason' => 'no such operation: ' . mcm_log_detail($operation));
	}

	$operations = mcm_tmdb_operations();
	$definition = $operations[$operation];
	$allowed    = array_merge(array(MCM_TMDB_OPERATION_FIELD), $definition['accepts']);

	// An unknown field is refused rather than ignored. Ignoring it would make
	// "this endpoint takes no URL" a fact about today's code; refusing it makes
	// it a fact about the request.
	foreach ($request as $field => $ignored) {
		if (!is_string($field) || !in_array($field, $allowed, true)) {
			return array('ok' => false, 'reason' => $operation . ' does not accept the field ' . mcm_log_detail($field));
		}
	}

	$values = array();
	foreach ($definition['accepts'] as $field) {
		if (!isset($request[$field])) {
			if (in_array($field, $definition['required'], true)) {
				return array('ok' => false, 'reason' => $operation . ' needs ' . $field);
			}
			continue;
		}

		$value = mcm_tmdb_value($field, $request[$field]);
		if ($value === null) {
			return array('ok' => false, 'reason' => $operation . ' will not accept that ' . $field . ': ' . mcm_log_detail($request[$field]));
		}
		$values[$field] = $value;
	}

	$plan = call_user_func($definition['plan'], $values);

	return array(
		'ok'        => true,
		'operation' => $operation,
		'path'      => $plan['path'],
		'query'     => $plan['query'],
		'values'    => $values,
	);
}

/*
 * ---------------------------------------------------------------------------
 * What comes back
 * ---------------------------------------------------------------------------
 *
 * Every projector below builds a new array with a fixed set of keys. None of
 * them copies a sub-array through, and none of them iterates an upstream array
 * of fields: a key that is not written out by name here does not exist as far
 * as a client is concerned.
 */

/**
 * One upstream value as text, bounded.
 *
 * @param array  $row
 * @param string $key
 * @return string "" when the value is absent or not a scalar
 */
function mcm_tmdb_text(array $row, $key)
{
	if (!isset($row[$key]) || !is_scalar($row[$key])) {
		return '';
	}
	if (is_bool($row[$key])) {
		return $row[$key] ? 'true' : 'false';
	}

	return substr((string) $row[$key], 0, MCM_TMDB_MAX_TEXT_BYTES);
}

/**
 * One upstream value as text, or null where TMDb has none.
 *
 * A poster path is the reason this exists: the browser tells "no poster" from
 * "a poster" by the value being null, and always has.
 *
 * @param array  $row
 * @param string $key
 * @return string|null
 */
function mcm_tmdb_optional_text(array $row, $key)
{
	if (!isset($row[$key]) || $row[$key] === null || $row[$key] === '') {
		return null;
	}

	return mcm_tmdb_text($row, $key);
}

/**
 * One upstream value as a whole number, or null.
 *
 * @param array  $row
 * @param string $key
 * @return int|null
 */
function mcm_tmdb_number(array $row, $key)
{
	if (!isset($row[$key]) || !is_scalar($row[$key]) || is_bool($row[$key])) {
		return null;
	}
	if (!is_int($row[$key]) && preg_match('/^-?[0-9]+$/', trim((string) $row[$key])) !== 1) {
		return null;
	}

	return (int) $row[$key];
}

/**
 * One upstream value as a flag.
 *
 * @param array  $row
 * @param string $key
 * @return bool
 */
function mcm_tmdb_flag(array $row, $key)
{
	return isset($row[$key]) && ($row[$key] === true || $row[$key] === 1 || $row[$key] === '1' || $row[$key] === 'true');
}

/**
 * The rows of one upstream collection, bounded in number.
 *
 * Anything that is not a list of objects - an object, a string, a number - is
 * no rows at all rather than an error, so a response that changes shape is
 * projected to an empty collection instead of reaching a page as something it
 * does not expect.
 *
 * @param array  $data
 * @param string $key
 * @return array
 */
function mcm_tmdb_rows(array $data, $key)
{
	if (!isset($data[$key]) || !is_array($data[$key])) {
		return array();
	}

	$rows = array();
	foreach ($data[$key] as $row) {
		if (count($rows) >= MCM_TMDB_MAX_ROWS) {
			mcm_log('TMDb proxy', 'a projected collection was capped at ' . MCM_TMDB_MAX_ROWS . ' rows');
			break;
		}
		if (is_array($row)) {
			$rows[] = $row;
		}
	}
	return $rows;
}

/**
 * The configuration a page needs to build a poster URL, and nothing else.
 *
 * @param array $data
 * @return array
 */
function mcm_tmdb_project_configuration(array $data)
{
	$images = (isset($data['images']) && is_array($data['images'])) ? $data['images'] : array();

	// A poster size is a bare word - "w92", "original" - rather than a row, so
	// it is checked against that shape one at a time instead of going through
	// mcm_tmdb_rows(). Anything else is dropped rather than repeated.
	$sizes = array();
	if (isset($images['poster_sizes']) && is_array($images['poster_sizes'])) {
		foreach ($images['poster_sizes'] as $size) {
			if (count($sizes) >= MCM_TMDB_MAX_ROWS) {
				break;
			}
			if (is_string($size) && preg_match('/^[A-Za-z0-9_]{1,16}$/', $size) === 1) {
				$sizes[] = $size;
			}
		}
	}

	return array(
		'images' => array(
			'base_url'        => mcm_tmdb_text($images, 'base_url'),
			'secure_base_url' => mcm_tmdb_text($images, 'secure_base_url'),
			'poster_sizes'    => $sizes,
		),
	);
}

/**
 * One movie as the type-ahead and the collection pages read it.
 *
 * @param array $row
 * @return array
 */
function mcm_tmdb_project_movie_summary(array $row)
{
	return array(
		'id'             => mcm_tmdb_number($row, 'id'),
		'title'          => mcm_tmdb_text($row, 'title'),
		'original_title' => mcm_tmdb_text($row, 'original_title'),
		'poster_path'    => mcm_tmdb_optional_text($row, 'poster_path'),
		'release_date'   => mcm_tmdb_text($row, 'release_date'),
	);
}

/**
 * A page of search results.
 *
 * @param array $data
 * @return array
 */
function mcm_tmdb_project_search(array $data)
{
	$results = array();
	foreach (mcm_tmdb_rows($data, 'results') as $row) {
		$results[] = mcm_tmdb_project_movie_summary($row);
	}

	return array(
		'page'          => mcm_tmdb_number($data, 'page'),
		'total_pages'   => mcm_tmdb_number($data, 'total_pages'),
		'total_results' => mcm_tmdb_number($data, 'total_results'),
		'results'       => $results,
	);
}

/**
 * One movie's details, as the movie dialog renders them.
 *
 * @param array $data
 * @return array
 */
function mcm_tmdb_project_movie(array $data)
{
	$genres = array();
	foreach (mcm_tmdb_rows($data, 'genres') as $genre) {
		$genres[] = array(
			'id'   => mcm_tmdb_number($genre, 'id'),
			'name' => mcm_tmdb_text($genre, 'name'),
		);
	}

	$movie           = mcm_tmdb_project_movie_summary($data);
	$movie['imdb_id']  = mcm_tmdb_optional_text($data, 'imdb_id');
	$movie['overview'] = mcm_tmdb_text($data, 'overview');
	$movie['runtime']  = mcm_tmdb_number($data, 'runtime');
	$movie['genres']   = $genres;

	return $movie;
}

/**
 * One movie's videos, in TMDb's current shape.
 *
 * The old response's "youtube", "source" and word-ranked "size" are gone from
 * TMDb and are not reconstructed here: what comes back is what the current
 * response holds, and issue #37 is where the dialog starts reading it.
 *
 * @param array $data
 * @return array
 */
function mcm_tmdb_project_videos(array $data)
{
	$videos = array();
	foreach (mcm_tmdb_rows($data, 'results') as $row) {
		$videos[] = array(
			'id'       => mcm_tmdb_text($row, 'id'),
			'key'      => mcm_tmdb_text($row, 'key'),
			'name'     => mcm_tmdb_text($row, 'name'),
			'site'     => mcm_tmdb_text($row, 'site'),
			'type'     => mcm_tmdb_text($row, 'type'),
			'size'     => mcm_tmdb_number($row, 'size'),
			'official' => mcm_tmdb_flag($row, 'official'),
		);
	}

	return array(
		'id'      => mcm_tmdb_number($data, 'id'),
		'results' => $videos,
	);
}

/**
 * One TMDb list, as an import reads it.
 *
 * The identifier stays text because TMDb's older list identifiers are
 * hexadecimal, which is what this site's own stored lists still use.
 *
 * @param array $data
 * @return array
 */
function mcm_tmdb_project_list(array $data)
{
	$items = array();
	foreach (mcm_tmdb_rows($data, 'items') as $row) {
		$items[] = mcm_tmdb_project_movie_summary($row);
	}

	return array(
		'id'          => mcm_tmdb_text($data, 'id'),
		'name'        => mcm_tmdb_text($data, 'name'),
		'description' => mcm_tmdb_text($data, 'description'),
		'item_count'  => mcm_tmdb_number($data, 'item_count'),
		'items'       => $items,
	);
}

/*
 * ---------------------------------------------------------------------------
 * The shared configuration cache
 * ---------------------------------------------------------------------------
 *
 * One file, holding the projected configuration and the moment it was stored.
 * It is shared rather than per-session on purpose: the answer is the same for
 * everybody, so a per-session copy would fetch it again for every fresh browser
 * and store the same bytes once per visitor.
 *
 * Three things keep it safe without a dependency. The directory is this
 * application's own, created private and used only when it really is a
 * directory this process owns, so a name in a shared temporary directory cannot
 * be pre-empted by somebody else's symlink. A write goes to a temporary file
 * and is moved into place, which is atomic, so a reader sees either the whole
 * previous answer or the whole new one and never half of either. And the cache
 * is advisory throughout: every failure means "fetch it again", never "fail the
 * request".
 */

/**
 * The directory cache files live in, or "" when there is nowhere safe to put
 * them.
 *
 * @return string
 */
function mcm_tmdb_cache_dir()
{
	$configured = defined('MCM_TMDB_CACHE_DIR') ? trim((string) MCM_TMDB_CACHE_DIR) : '';
	$dir        = ($configured !== '') ? rtrim($configured, '/') : sys_get_temp_dir() . '/mcm-tmdb-cache';

	if (!is_dir($dir)) {
		// 0700: nothing but this application has any business reading it, and
		// nothing else may create files in it.
		if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
			return '';
		}
	}
	// A symbolic link, or a directory somebody else owns, is not somewhere this
	// application will read a file from or write one to.
	if (is_link($dir) || (function_exists('posix_getuid') && fileowner($dir) !== posix_getuid())) {
		return '';
	}

	return $dir;
}

/**
 * The file one cache entry lives in, or "" when there is nowhere to put it.
 *
 * The name is derived from the endpoint as well as the entry, so a site that
 * changes its endpoint - which is how the test suite drives all of this against
 * a stub - never reads an answer the previous one gave.
 *
 * @param string $entry
 * @return string
 */
function mcm_tmdb_cache_file($entry)
{
	$dir = mcm_tmdb_cache_dir();
	if ($dir === '') {
		return '';
	}

	return $dir . '/' . preg_replace('/[^a-z_]/', '', $entry) . '-' . sha1($entry . '|' . mcm_tmdb_base_url()) . '.json';
}

/**
 * A cached answer that is still inside its lifetime, or null.
 *
 * @param string $entry
 * @param int    $ttl   seconds
 * @return array|null
 */
function mcm_tmdb_cache_read($entry, $ttl)
{
	$file = mcm_tmdb_cache_file($entry);
	if ($file === '' || !is_file($file) || is_link($file)) {
		return null;
	}

	$size = @filesize($file);
	if ($size === false || $size > MCM_TMDB_CACHE_MAX_BYTES) {
		return null;
	}

	$raw = @file_get_contents($file);
	if ($raw === false) {
		return null;
	}

	$held = json_decode($raw, true);
	if (!is_array($held) || !isset($held['stored'], $held['data']) || !is_array($held['data'])) {
		return null;
	}

	$stored = (int) $held['stored'];
	// A stored time in the future is a clock that moved, not an answer worth
	// keeping for another day.
	if ($stored <= 0 || $stored > time() || (time() - $stored) >= $ttl) {
		return null;
	}

	return $held['data'];
}

/**
 * Keep one answer, if it can be kept. Nothing here can fail a request.
 *
 * @param string $entry
 * @param array  $data  the projection, never an upstream body
 */
function mcm_tmdb_cache_write($entry, array $data)
{
	$file = mcm_tmdb_cache_file($entry);
	if ($file === '') {
		return;
	}

	$body = json_encode(array('stored' => time(), 'data' => $data));
	if (!is_string($body) || strlen($body) > MCM_TMDB_CACHE_MAX_BYTES) {
		mcm_log('TMDb proxy', 'the ' . $entry . ' answer was not worth caching');
		return;
	}

	// Written beside the real file and moved onto it: a reader never sees a
	// half-written answer, and two processes writing at once each write their
	// own temporary file before one of them wins the move.
	$temporary = $file . '.' . getmypid() . '.tmp';
	if (@file_put_contents($temporary, $body, LOCK_EX) === false) {
		mcm_log('TMDb proxy', 'the ' . $entry . ' answer could not be written to the cache');
		return;
	}
	@chmod($temporary, 0600);
	if (!@rename($temporary, $file)) {
		@unlink($temporary);
		mcm_log('TMDb proxy', 'the ' . $entry . ' answer could not be moved into the cache');
	}
}

/*
 * ---------------------------------------------------------------------------
 * Running one operation
 * ---------------------------------------------------------------------------
 */

/**
 * The status a failed operation is answered with.
 *
 * Coarse on purpose, and derived from inc/tmdb.php's categories rather than
 * from anything upstream said: a client learns that this site could not answer
 * and roughly why, which is what it needs to show a message, and nothing about
 * the request that was made on its behalf.
 *
 * @param string $category
 * @return int
 */
function mcm_tmdb_upstream_status($category)
{
	$statuses = array(
		'configuration' => 503,
		'unavailable'   => 502,
		'timeout'       => 504,
		'too_large'     => 502,
		'upstream'      => 502,
		'malformed'     => 502,
	);

	return isset($statuses[$category]) ? $statuses[$category] : 502;
}

/**
 * Who may ask for one operation: "any", "user" or "owner".
 *
 * @param string $operation one that mcm_tmdb_operation_exists() accepted
 * @return string
 */
function mcm_tmdb_caller_policy($operation)
{
	$operations = mcm_tmdb_operations();

	return $operations[$operation]['caller'];
}

/**
 * Refuse a request from nobody, where this operation needs somebody, and stop.
 *
 * Asked before the request's own values are looked at, which is the order
 * inc/guards.php sets out for a write and the same order for the same reason:
 * the session answers this on its own, so a request refused here has opened no
 * connection, sent nothing, and been told nothing about what the operation
 * would have accepted.
 *
 * @param string $operation
 */
function mcm_tmdb_require_session($operation)
{
	if (mcm_tmdb_caller_policy($operation) !== 'any') {
		mcm_require_login();
	}
}

/**
 * Refuse a request for somebody else's list, and stop.
 *
 * This is the one question that needs a database, so it is the one that comes
 * after the connection - and it still comes before mcm_tmdb_run() below, so a
 * refusal has made no outbound request and spent nothing of this site's.
 *
 * Every way of failing is mcm_require_list_owner()'s single 403: a list
 * belonging to somebody else and a list that does not exist are answered
 * identically, so the response says nothing about whose it is.
 *
 * A caller that already holds a connection passes it in, and the question is
 * asked over that one rather than over a second: import_list.php has opened one
 * to settle the same ownership for its own writes by the time it gets here. It
 * is the connection that is shared and never the answer - the question is asked
 * again regardless of who is asking, because "the list is theirs" is this
 * operation's own condition and not something a caller may assert on its behalf.
 *
 * @param array    $plan          as mcm_tmdb_plan() returns on success
 * @param PDO|null $db_connection one the caller already has, or null to open one
 */
function mcm_tmdb_require_owner(array $plan, $db_connection = null)
{
	if (mcm_tmdb_caller_policy($plan['operation']) !== 'owner') {
		return;
	}

	if ($db_connection === null) {
		$db_connection = mcm_db_or_fail('tmdb proxy: settling who owns the list');
	}
	mcm_require_list_owner($db_connection, $plan['values']['movie_list_id']);
}

/**
 * Run one planned operation: fetch, or take the cached answer, and project.
 *
 * The only call to mcm_tmdb_get() in this file is the one below. Everything
 * that decides what that call may be has already happened by the time it is
 * reached.
 *
 * @param array $plan as mcm_tmdb_plan() returns on success
 * @return array ok and data, or ok false with a category, a status and a message
 */
function mcm_tmdb_run(array $plan)
{
	$operations = mcm_tmdb_operations();
	$definition = $operations[$plan['operation']];

	if (!empty($definition['cached'])) {
		$cached = mcm_tmdb_cache_read($plan['operation'], MCM_TMDB_CONFIG_CACHE_SECONDS);
		if ($cached !== null) {
			return array('ok' => true, 'data' => $cached);
		}
		// The one line this cache writes on the ordinary path, and it is written
		// on the rare half of it: a miss happens once a day per deployment, so
		// the log says how often the endpoint was really asked without a line
		// per page view.
		mcm_log('TMDb proxy', 'configuration cache miss; asking the endpoint');
	}

	$result = mcm_tmdb_get($plan['path'], $plan['query']);
	if (empty($result['ok'])) {
		$category = isset($result['category']) ? $result['category'] : 'unavailable';

		return array(
			'ok'       => false,
			'category' => $category,
			'status'   => mcm_tmdb_upstream_status($category),
			'message'  => isset($result['message']) ? $result['message'] : 'The movie database could not be reached.',
		);
	}

	$data = call_user_func($definition['project'], $result['data']);
	if (!empty($definition['cached'])) {
		mcm_tmdb_cache_write($plan['operation'], $data);
	}

	return array('ok' => true, 'data' => $data);
}

/**
 * Settle one operation and run it: the whole of the proxy's policy, in order.
 *
 * Both ways into the proxy go through this. tmdb.php serves a browser and turns
 * what comes back into a JSON body; import_list.php is a page of this site that
 * needs the answer itself, and calls this directly rather than making an HTTP
 * request to its own server. Neither of them decides the order the questions
 * are asked in, and neither of them can skip one: the caller policy belongs to
 * the operation, so it is asked of whoever is asking.
 *
 * The order is inc/guards.php's own, and it is the point. The operation is
 * named first, because which questions come next is a property of the
 * operation. Then who is asking, which the session answers on its own. Then the
 * request's own values, which are pure. Then, for a list, whose list it is -
 * the one question that needs a connection. Only then TMDb. A request refused
 * anywhere in that sequence has cost this site no outbound request.
 *
 * A refusal ends the request where it stands, with the bounded body from
 * inc/guards.php and the reason in the log; an upstream failure comes back as a
 * value, because a caller may have something better to do with it than repeat
 * it.
 *
 * @param mixed    $operation     the requested operation name
 * @param mixed    $request       the request's own fields
 * @param PDO|null $db_connection one the caller already has, for the ownership
 *                                question, or null to open one
 * @return array as mcm_tmdb_run() returns
 */
function mcm_tmdb_resolve($operation, $request, $db_connection = null)
{
	if (!mcm_tmdb_operation_exists($operation)) {
		// The same bounded body a guard sends, with the reason in the log and
		// nowhere else.
		mcm_json_error(400, 'the TMDb proxy refused a request: no such operation: ' . mcm_log_detail($operation));
	}

	mcm_tmdb_require_session($operation);

	$plan = mcm_tmdb_plan($operation, $request);
	if (empty($plan['ok'])) {
		mcm_json_error(400, 'the TMDb proxy refused a request: ' . $plan['reason']);
	}

	mcm_tmdb_require_owner($plan, $db_connection);

	return mcm_tmdb_run($plan);
}

/**
 * Plan and run one operation, answering every failure as a value.
 *
 * The execution half of mcm_tmdb_resolve() above, and deliberately nothing
 * else: it names no policy, asks no session and asks no owner. Both end at
 * mcm_tmdb_run(), which stays the one place an operation is executed and the
 * only path to inc/tmdb.php.
 *
 * It exists because a refusal has two right answers, and which one is right
 * depends on who is asking. A request off the wire, and a page reading a list
 * on a visitor's behalf, both want mcm_tmdb_resolve(): a bad operation or a
 * value the operation will not accept ends the request there, with the bounded
 * body from inc/guards.php. add_movie.php wants the other answer. By the time
 * it needs a film's details it has already settled the method, the session, the
 * token and the list with its own guards, and it has a page of its own to
 * answer - so a failure has to come back as a value it can turn into that
 * page's response, not end the request from inside a helper.
 *
 * That is the whole of the difference, and it is why this asks no policy
 * question rather than asking a weaker version of one: a caller that has not
 * already settled who is asking must use mcm_tmdb_resolve(), which will.
 * Who may call this is written down in mcm_tmdb_execute_callers() in
 * tests/entrypoints.php, and the suite fails a page that starts calling it
 * without being named there.
 *
 * @param mixed $operation the requested operation name
 * @param mixed $request   the request's own fields
 * @return array as mcm_tmdb_run() returns; a refused plan comes back as the
 *               "request" category rather than ending the request
 */
function mcm_tmdb_execute($operation, $request)
{
	$plan = mcm_tmdb_plan($operation, $request);
	if (empty($plan['ok'])) {
		return array(
			'ok'       => false,
			'category' => 'request',
			'status'   => 400,
			'message'  => 'That request could not be made.',
			// Bounded by mcm_tmdb_plan() through mcm_log_detail() where a value
			// was involved. For the caller's log, never for its response.
			'reason'   => isset($plan['reason']) ? $plan['reason'] : '',
		);
	}

	return mcm_tmdb_run($plan);
}

/*
 * ---------------------------------------------------------------------------
 * Answering
 * ---------------------------------------------------------------------------
 */

/**
 * Send one bounded JSON body, and stop.
 *
 * @param int   $status
 * @param array $payload already projected; nothing here inspects it further
 */
function mcm_tmdb_respond($status, array $payload)
{
	// A substitution rather than a failure: one malformed byte in a title must
	// not be the difference between an answer and no answer.
	$body = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
	if (!is_string($body)) {
		mcm_log('TMDb proxy', 'the projected answer could not be encoded');
		$status = 502;
		$body   = json_encode(array('error' => 'malformed', 'message' => 'The movie database sent something this site could not read.'));
	}

	if (!headers_sent()) {
		// http_response_code() rather than a header line, because these statuses
		// are answered without a reason phrase of this site's invention.
		http_response_code((int) $status);
		header('Content-Type: application/json; charset=utf-8');
		// The answer is this site's to cache, not a shared proxy's: the one
		// thing worth keeping is kept on the server, for everybody.
		header('Cache-Control: no-store');
	}
	echo $body;
	exit(($status >= 200 && $status < 300) ? 0 : 1);
}

/**
 * Serve one request to the proxy.
 *
 * The whole entry point: decide the request, refuse it or run it, and answer.
 *
 * @param mixed $request the request's own fields
 */
function mcm_tmdb_serve($request)
{
	$method = mcm_request_method();
	if ($method !== 'GET' && $method !== 'POST') {
		if (!headers_sent()) {
			header('Allow: GET, POST');
		}
		// Read-only, so a GET is as legitimate as a POST here and the shared
		// refusal body is the same one every other endpoint uses.
		mcm_json_error(405, 'the TMDb proxy was asked with method ' . mcm_log_detail($method));
	}

	$operation = (is_array($request) && isset($request[MCM_TMDB_OPERATION_FIELD])) ? $request[MCM_TMDB_OPERATION_FIELD] : '';

	// Everything that decides whether this request may be made at all, and in
	// which order, is mcm_tmdb_resolve()'s. This file's door holds no policy of
	// its own beyond the method: what is left here is turning an answer, or a
	// failure, into a body.
	$result = mcm_tmdb_resolve($operation, $request);
	if (empty($result['ok'])) {
		mcm_tmdb_respond($result['status'], array(
			'error'   => $result['category'],
			'message' => $result['message'],
		));
	}

	mcm_tmdb_respond(200, array(
		'ok'        => true,
		'operation' => $operation,
		'data'      => $result['data'],
	));
}
