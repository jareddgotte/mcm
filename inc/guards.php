<?php

/**
 * Reusable request guards.
 *
 * Small, single-purpose helpers for the questions a write endpoint in this
 * application has to ask before it touches the database:
 *
 *   1. is this a POST?               mcm_require_post()
 *   2. is anybody signed in?         mcm_require_login()
 *   3. did this request come from a
 *      page this site handed out?    mcm_require_csrf()
 *   4. is this list theirs?          mcm_require_list_owner()
 *
 * Each question comes in two forms: a predicate that answers it and returns,
 * and a guard that answers it and, on "no", ends the request with a generic
 * JSON error.
 *
 * Nothing here decides anything on its own. The file only declares functions,
 * and no entry point calls them yet: adopting them, endpoint by endpoint, is
 * deliberately separate work, so that adding the helpers cannot change what any
 * request does today.
 *
 * Two rules hold throughout:
 *
 *   - a rejection tells the client the status and a fixed generic message, and
 *     nothing else; what was actually wrong goes to the server-side log through
 *     mcm_log(), exactly as it does for a failure;
 *   - the CSRF comparison is constant-time, so a rejected request never reveals
 *     how much of a guessed token was right.
 */

// The session, the configuration and the error handling all come from the
// shared bootstrap. Requiring it here is a no-op for an entry point that has
// already loaded it, and keeps this file usable on its own.
require_once(dirname(__FILE__) . '/bootstrap.php');

/** Where the session keeps the CSRF token. Login::logout() empties $_SESSION, so signing out drops it. */
define('MCM_CSRF_SESSION_KEY', 'mcm_csrf_token');

/** The form field a submitted CSRF token is read from. */
define('MCM_CSRF_FIELD', 'csrf_token');

/** The $_SERVER key for the X-CSRF-Token request header, which AJAX callers may use instead of the field. */
define('MCM_CSRF_HEADER', 'HTTP_X_CSRF_TOKEN');

/*
 * ---------------------------------------------------------------------------
 * Values
 * ---------------------------------------------------------------------------
 */

/**
 * A positive integer, or null when the value is not one.
 *
 * Every identifier this application takes from a request is a positive integer
 * key. Accepting only the digits, rather than letting PHP cast, keeps values
 * such as "3 OR 1=1", "0" and "" out of a query and out of a comparison.
 *
 * @param mixed $value
 * @return int|null
 */
function mcm_positive_int($value)
{
	if (is_int($value)) {
		return $value > 0 ? $value : null;
	}
	if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
		$number = (int) $value;
		return $number > 0 ? $number : null;
	}

	return null;
}

/**
 * A value from the request, made safe to put in a log line.
 *
 * The log is one line per entry, so anything a client controls has to lose its
 * control characters and its length before it goes anywhere near it.
 *
 * @param mixed $value
 * @return string
 */
function mcm_log_detail($value)
{
	if (!is_string($value)) {
		if (is_bool($value)) {
			return 'boolean ' . ($value ? 'true' : 'false');
		}
		if (is_null($value)) {
			return 'null';
		}
		if (is_int($value)) {
			return 'integer ' . $value;
		}
		if (is_float($value)) {
			return 'double ' . $value;
		}

		return gettype($value);
	}

	$value = preg_replace('/[^\x20-\x7e]/', '?', $value);
	if (strlen($value) > 100) {
		$value = substr($value, 0, 100) . '...';
	}

	return $value;
}

/*
 * ---------------------------------------------------------------------------
 * Who is asking
 * ---------------------------------------------------------------------------
 */

/**
 * Whether a user is signed in.
 *
 * The test is the one Login::isUserLoggedIn() has always made, so a session
 * that is signed in for the login code is signed in here too. The comparison
 * with 1 stays loose on purpose: the flag is written as an integer, but a
 * session restored from disk by an older runtime can carry it as a string.
 *
 * @return bool
 */
function mcm_is_logged_in()
{
	return isset($_SESSION['user_logged_in'])
		&& $_SESSION['user_logged_in'] == 1
		&& !empty($_SESSION['user_name'])
		&& is_string($_SESSION['user_name']);
}

/**
 * The signed-in user's id, or null when nobody is signed in.
 *
 * Null is also the answer when a session says it is signed in but carries no
 * usable id, so a caller can never end up querying with a nonsense owner.
 *
 * @return int|null
 */
function mcm_current_user_id()
{
	if (!mcm_is_logged_in() || !isset($_SESSION['user_id'])) {
		return null;
	}

	return mcm_positive_int($_SESSION['user_id']);
}

/**
 * The signed-in user's name, or null when nobody is signed in.
 *
 * @return string|null
 */
function mcm_current_user_name()
{
	return mcm_is_logged_in() ? $_SESSION['user_name'] : null;
}

/*
 * ---------------------------------------------------------------------------
 * How they are asking
 * ---------------------------------------------------------------------------
 */

/**
 * The request method, upper-cased, or "" when there is none (the command line).
 *
 * Method override headers are deliberately not consulted: a guard that can be
 * talked out of its own check by a header is not a guard.
 *
 * @return string
 */
function mcm_request_method()
{
	if (!isset($_SERVER['REQUEST_METHOD']) || !is_string($_SERVER['REQUEST_METHOD'])) {
		return '';
	}

	return strtoupper($_SERVER['REQUEST_METHOD']);
}

/**
 * Whether this request is a POST.
 *
 * @return bool
 */
function mcm_request_is_post()
{
	return mcm_request_method() === 'POST';
}

/*
 * ---------------------------------------------------------------------------
 * CSRF tokens
 * ---------------------------------------------------------------------------
 */

/**
 * A random hex token.
 *
 * Prefers the runtime's cryptographic source and falls back only where there is
 * none, because the site's own PHP version is not something this repository can
 * assume (see the version branch at the top of inc/php-login.php).
 *
 * @param int $bytes number of random bytes; the token is twice as many hex characters
 * @return string
 */
function mcm_random_token($bytes = 32)
{
	$bytes = (int) $bytes;
	if ($bytes < 16) {
		$bytes = 16;
	}

	if (function_exists('random_bytes')) {
		return bin2hex(random_bytes($bytes));
	}

	if (function_exists('openssl_random_pseudo_bytes')) {
		$strong = false;
		$raw    = openssl_random_pseudo_bytes($bytes, $strong);
		if ($raw !== false && $strong) {
			return bin2hex($raw);
		}
	}

	// Last resort, for a runtime with neither: not a cryptographic source, but
	// still unpredictable enough to be worth more than no token at all.
	$token = '';
	while (strlen($token) < $bytes * 2) {
		$token .= hash('sha256', uniqid((string) mt_rand(), true));
	}

	return substr($token, 0, $bytes * 2);
}

/**
 * The session's CSRF token, minted on first use.
 *
 * The token lasts as long as the session: it is not rotated per request, so a
 * page open in a second tab keeps working.
 *
 * @return string
 */
function mcm_csrf_token()
{
	if (!isset($_SESSION[MCM_CSRF_SESSION_KEY])
		|| !is_string($_SESSION[MCM_CSRF_SESSION_KEY])
		|| $_SESSION[MCM_CSRF_SESSION_KEY] === ''
	) {
		$_SESSION[MCM_CSRF_SESSION_KEY] = mcm_random_token();
	}

	return $_SESSION[MCM_CSRF_SESSION_KEY];
}

/**
 * The token this request submitted, from the form field or the header, or "".
 *
 * @return string
 */
function mcm_submitted_csrf_token()
{
	if (isset($_POST[MCM_CSRF_FIELD]) && is_string($_POST[MCM_CSRF_FIELD])) {
		return $_POST[MCM_CSRF_FIELD];
	}
	if (isset($_SERVER[MCM_CSRF_HEADER]) && is_string($_SERVER[MCM_CSRF_HEADER])) {
		return $_SERVER[MCM_CSRF_HEADER];
	}

	return '';
}

/**
 * Whether a submitted token matches the session's.
 *
 * A session with no token of its own matches nothing, so this can never be
 * satisfied by submitting an empty token. The token is only read here, never
 * minted: an unauthenticated rejection leaves no state behind.
 *
 * @param mixed $token
 * @return bool
 */
function mcm_csrf_token_is_valid($token)
{
	if (!is_string($token) || $token === '') {
		return false;
	}
	if (!isset($_SESSION[MCM_CSRF_SESSION_KEY])
		|| !is_string($_SESSION[MCM_CSRF_SESSION_KEY])
		|| $_SESSION[MCM_CSRF_SESSION_KEY] === ''
	) {
		return false;
	}

	return mcm_hash_equals($_SESSION[MCM_CSRF_SESSION_KEY], $token);
}

/**
 * Constant-time string comparison.
 *
 * This is the only place a token is compared. It contains no comparison of its
 * own on purpose: a comparison that returns as soon as two bytes differ tells
 * an attacker, through how long it took, how much of a guess was right.
 *
 * @param string $known the value the server holds
 * @param string $given the value the client submitted
 * @return bool
 */
function mcm_hash_equals($known, $given)
{
	// Anything that is not a string is not the token. Checking here rather than
	// letting hash_equals() raise a TypeError keeps a caller that forwards
	// whatever the request contained on the refusing path, where it belongs.
	if (!is_string($known) || !is_string($given)) {
		return false;
	}

	if (function_exists('hash_equals')) {
		return hash_equals($known, $given);
	}

	return mcm_constant_time_equals($known, $given);
}

/**
 * The constant-time comparison used where the runtime has no hash_equals()
 * (PHP before 5.6).
 *
 * The length of the submitted value is not a secret and is allowed to decide
 * early; the bytes are, so every one of them is folded into the same
 * accumulator and the answer is only read at the end.
 *
 * @param string $known
 * @param string $given
 * @return bool
 */
function mcm_constant_time_equals($known, $given)
{
	if (!is_string($known) || !is_string($given)) {
		return false;
	}

	$known_length = strlen($known);
	$given_length = strlen($given);

	if ($known_length === 0 || $given_length === 0) {
		return $known_length === $given_length;
	}

	$difference = $known_length ^ $given_length;
	for ($index = 0; $index < $given_length; $index++) {
		// Reading the known value round-robin keeps the work proportional to
		// what was submitted rather than to where the first difference is; a
		// length mismatch has already made $difference non-zero.
		$difference |= ord($known[$index % $known_length]) ^ ord($given[$index]);
	}

	return $difference === 0;
}

/*
 * ---------------------------------------------------------------------------
 * Ownership
 * ---------------------------------------------------------------------------
 */

/**
 * Whether a movie list belongs to a user.
 *
 * A list that does not exist, an identifier that is not a positive integer and
 * a list owned by somebody else are all the same answer: false. The caller
 * cannot tell them apart, and neither can the client.
 *
 * @param PDO   $db_connection
 * @param mixed $movie_list_id as it arrived from the request
 * @param mixed $user_id       the owner to check against
 * @return bool
 */
function mcm_user_owns_list(PDO $db_connection, $movie_list_id, $user_id)
{
	$list_id  = mcm_positive_int($movie_list_id);
	$owner_id = mcm_positive_int($user_id);

	if ($list_id === null || $owner_id === null) {
		return false;
	}

	$query = $db_connection->prepare('SELECT user_id FROM movie_lists WHERE movie_list_id = :movie_list_id');
	if ($query === false) {
		mcm_log('Ownership check', 'the ownership query could not be prepared');
		return false;
	}

	$query->bindValue(':movie_list_id', $list_id, PDO::PARAM_INT);
	if ($query->execute() === false) {
		$info = $query->errorInfo();
		mcm_log('Ownership check', 'the ownership query failed: ' . (isset($info[2]) ? $info[2] : 'unknown error'));
		return false;
	}

	$row = $query->fetch(PDO::FETCH_ASSOC);
	if ($row === false || !isset($row['user_id'])) {
		return false;
	}

	return (int) $row['user_id'] === $owner_id;
}

/*
 * ---------------------------------------------------------------------------
 * Refusing a request
 * ---------------------------------------------------------------------------
 */

/**
 * The statuses a guard may refuse with, and the fixed body of each.
 *
 * Fixed is the point: two different reasons that share a status are answered
 * identically, so an invalid token and somebody else's list cannot be told
 * apart from the outside.
 *
 * @return array
 */
function mcm_json_error_catalogue()
{
	return array(
		400 => array(
			'reason'  => 'Bad Request',
			'error'   => 'bad_request',
			'message' => 'The request could not be processed.',
		),
		401 => array(
			'reason'  => 'Unauthorized',
			'error'   => 'authentication_required',
			'message' => 'You must be signed in to do that.',
		),
		403 => array(
			'reason'  => 'Forbidden',
			'error'   => 'forbidden',
			'message' => 'You are not allowed to do that.',
		),
		405 => array(
			'reason'  => 'Method Not Allowed',
			'error'   => 'method_not_allowed',
			'message' => 'That request method is not allowed here.',
		),
	);
}

/**
 * The catalogued status for a value, defaulting to 400.
 *
 * @param mixed $status
 * @return int
 */
function mcm_json_error_status($status)
{
	$status    = (int) $status;
	$catalogue = mcm_json_error_catalogue();

	return isset($catalogue[$status]) ? $status : 400;
}

/**
 * The response body for a refusal: a machine-readable code and a generic
 * message, and nothing that depends on the request.
 *
 * @param mixed $status
 * @return string
 */
function mcm_json_error_body($status)
{
	$entry = mcm_json_error_catalogue();
	$entry = $entry[mcm_json_error_status($status)];

	return json_encode(array('error' => $entry['error'], 'message' => $entry['message']));
}

/**
 * Refuse the request with a JSON body, and stop.
 *
 * @param mixed  $status one of the catalogued statuses; anything else is 400
 * @param string $detail why, for the log; it never reaches the client
 */
function mcm_json_error($status, $detail = '')
{
	$status = mcm_json_error_status($status);

	if ($detail !== '') {
		mcm_log('Refused request', $detail);
	}

	if (!headers_sent()) {
		$catalogue = mcm_json_error_catalogue();
		header('HTTP/1.1 ' . $status . ' ' . $catalogue[$status]['reason'], true, $status);
		header('Content-Type: application/json; charset=utf-8');
		// A refusal is about this one request and this one session; nothing may
		// serve it to anybody else.
		header('Cache-Control: no-store');
	}

	echo mcm_json_error_body($status);

	// Non-zero for the same reason mcm_fail() uses it: the request did not do
	// what it was asked to do.
	exit(1);
}

/*
 * ---------------------------------------------------------------------------
 * The guards
 * ---------------------------------------------------------------------------
 */

/**
 * Refuse anything that is not a POST.
 */
function mcm_require_post()
{
	if (mcm_request_is_post()) {
		return;
	}

	if (!headers_sent()) {
		header('Allow: POST');
	}
	mcm_json_error(405, 'method ' . mcm_log_detail(mcm_request_method()) . ' is not allowed here');
}

/**
 * Refuse a request that has nobody signed in behind it.
 */
function mcm_require_login()
{
	if (mcm_is_logged_in()) {
		return;
	}

	mcm_json_error(401, 'no signed-in user');
}

/**
 * Refuse a request that did not carry this session's CSRF token.
 *
 * The submitted token is never logged: it is a credential, and a log is not a
 * place for one.
 */
function mcm_require_csrf()
{
	if (mcm_csrf_token_is_valid(mcm_submitted_csrf_token())) {
		return;
	}

	mcm_json_error(403, 'the request carried no valid CSRF token');
}

/**
 * Refuse a request for a movie list the signed-in user does not own, and hand
 * back the identifier once it is known to be safe to use.
 *
 * Every way of failing answers 403 with the same body, so the response never
 * says whether a list exists.
 *
 * @param PDO   $db_connection
 * @param mixed $movie_list_id as it arrived from the request
 * @return int the validated identifier
 */
function mcm_require_list_owner(PDO $db_connection, $movie_list_id)
{
	$list_id = mcm_positive_int($movie_list_id);
	$user_id = mcm_current_user_id();

	if ($list_id === null) {
		mcm_json_error(403, 'refused a movie list id that is not a positive integer: ' . mcm_log_detail($movie_list_id));
	}
	if ($user_id === null) {
		mcm_json_error(403, 'refused movie list ' . $list_id . ': no signed-in user');
	}
	if (!mcm_user_owns_list($db_connection, $list_id, $user_id)) {
		mcm_json_error(403, 'refused movie list ' . $list_id . ': not owned by user ' . $user_id);
	}

	return $list_id;
}
