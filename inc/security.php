<?php

/**
 * Shared security primitives: random tokens, constant-time comparison,
 * password hashing and session identifier renewal.
 *
 * The login and registration code calls these instead of rolling its own, so
 * there is one place to look at when any of it has to change again.
 *
 * Nothing here changes the format of anything already stored:
 *   - a remember-me token is still 64 hexadecimal characters, the width of
 *     users.user_rememberme_token;
 *   - an activation or password-reset token is still 40 hexadecimal
 *     characters, the width of users.user_activation_hash and
 *     users.user_password_reset_hash;
 *   - a password hash is still what password_hash() produces, so every hash
 *     already in the database keeps verifying.
 * Only the way new values are generated and compared is different.
 *
 * The file is inert on its own: it defines functions and does nothing else.
 * inc/bootstrap.php loads it for every request.
 */

/**
 * Guard against this file being executed by a direct web request. inc/.htaccess
 * denies the whole include tree already; this makes the file safe even on a
 * host where that rule is missing.
 */
if (!defined('MCM_BOOTSTRAP')) {
	header('HTTP/1.0 403 Forbidden');
	exit('Forbidden');
}

/**
 * Read cryptographically secure random bytes.
 *
 * Sources are tried strongest first, and a source that cannot promise
 * cryptographic quality is never used: if none is available the request fails
 * rather than issuing a guessable token. On any PHP 7 or newer the first branch
 * is the only one that ever runs; the rest exist because the runtime the site
 * is served with is not pinned.
 *
 * @param int $count number of bytes
 * @return string raw bytes
 * @throws Exception when no secure source is available
 */
function mcm_random_bytes($count)
{
	$count = (int) $count;
	if ($count < 1) {
		$count = 1;
	}

	// PHP 7+: the one source that raises rather than degrading on failure.
	if (function_exists('random_bytes')) {
		return random_bytes($count);
	}

	if (function_exists('openssl_random_pseudo_bytes')) {
		$strong = false;
		$bytes  = openssl_random_pseudo_bytes($count, $strong);
		// $strong false means OpenSSL itself does not vouch for the bytes.
		if ($strong && is_string($bytes) && strlen($bytes) === $count) {
			return $bytes;
		}
	}

	if (function_exists('mcrypt_create_iv') && defined('MCRYPT_DEV_URANDOM')) {
		$bytes = @mcrypt_create_iv($count, MCRYPT_DEV_URANDOM);
		if (is_string($bytes) && strlen($bytes) === $count) {
			return $bytes;
		}
	}

	if (@is_readable('/dev/urandom')) {
		$handle = @fopen('/dev/urandom', 'rb');
		if ($handle !== false) {
			if (function_exists('stream_set_read_buffer')) {
				@stream_set_read_buffer($handle, 0);
			}
			$bytes = @fread($handle, $count);
			fclose($handle);
			if (is_string($bytes) && strlen($bytes) === $count) {
				return $bytes;
			}
		}
	}

	// Deliberately no mt_rand() fallback: a token nobody can trust is worse
	// than a request that fails and says so in the log.
	throw new Exception('no cryptographically secure source of randomness is available');
}

/**
 * Generate a random token as lowercase hexadecimal characters.
 *
 * The length is in characters rather than bytes because that is what the
 * database columns are measured in. An odd length is rounded up to the next
 * even one, so the value always comes from whole random bytes.
 *
 * @param int $length number of hexadecimal characters, at least 2
 * @return string
 */
function mcm_random_token($length = 64)
{
	$length = (int) $length;
	if ($length < 2) {
		$length = 2;
	}
	if ($length % 2 === 1) {
		$length++;
	}

	return bin2hex(mcm_random_bytes($length / 2));
}

/**
 * Compare two strings in a way that does not leak where they first differ.
 *
 * Used wherever a value from the request is checked against a secret the
 * server holds, so that response timing says nothing about how much of a
 * guessed token was correct.
 *
 * @param string $known the server's value
 * @param string $given the value from the request
 * @return bool
 */
function mcm_hash_equals($known, $given)
{
	if (!is_string($known) || !is_string($given)) {
		return false;
	}

	// PHP 5.6+.
	if (function_exists('hash_equals')) {
		return hash_equals($known, $given);
	}

	// Same idea by hand: every byte is always compared. The length is allowed
	// to leak, exactly as hash_equals() lets it.
	$length = strlen($known);
	if ($length !== strlen($given)) {
		return false;
	}

	$difference = 0;
	for ($index = 0; $index < $length; $index++) {
		$difference |= ord($known[$index]) ^ ord($given[$index]);
	}

	return $difference === 0;
}

/**
 * The options password_hash() and password_needs_rehash() are called with.
 *
 * HASH_COST_FACTOR has always been optional and is a string in the shipped
 * example configuration, so it is normalised here and ignored when it is not a
 * cost bcrypt accepts. An empty array means "whatever PHP's own default is",
 * which is what the algorithm would have used anyway.
 *
 * @return array
 */
function mcm_password_options()
{
	$options = array();

	if (defined('HASH_COST_FACTOR')) {
		$cost = (int) HASH_COST_FACTOR;
		if ($cost >= 4 && $cost <= 31) {
			$options['cost'] = $cost;
		}
	}

	return $options;
}

/**
 * Hash a password for storage.
 *
 * @param string $password
 * @return string
 */
function mcm_password_hash($password)
{
	return password_hash($password, PASSWORD_DEFAULT, mcm_password_options());
}

/**
 * Check a password against a stored hash.
 *
 * Every hash the application has ever written came from password_hash(), so
 * this verifies hashes from any earlier version of the site, whatever cost
 * factor they were created with. A missing or empty stored hash is a failed
 * login, never an accepted one.
 *
 * @param string $password
 * @param string $hash stored hash
 * @return bool
 */
function mcm_password_verify($password, $hash)
{
	if (!is_string($hash) || $hash === '' || !is_string($password)) {
		return false;
	}

	return password_verify($password, $hash);
}

/**
 * Whether a stored hash should be replaced with a freshly calculated one.
 *
 * This is only ever asked right after the password itself has been verified,
 * which is the one moment the plain password is available to hash again. It
 * says nothing about whether the password is still acceptable: an old hash
 * keeps working until the account's owner happens to sign in.
 *
 * @param string $hash stored hash
 * @return bool
 */
function mcm_password_needs_rehash($hash)
{
	if (!is_string($hash) || $hash === '') {
		return false;
	}

	return password_needs_rehash($hash, PASSWORD_DEFAULT, mcm_password_options());
}

/**
 * The hash half of a remember-me cookie.
 *
 * The formula is exactly the one this application has always used, so a cookie
 * issued before any of this existed still validates and a cookie issued now is
 * indistinguishable in shape from an old one. Changing it would sign out every
 * visitor who ticked "remember me", which COOKIE_SECRET_KEY is there to do
 * deliberately rather than by accident.
 *
 * @param string|int $user_id
 * @param string $token the value stored in users.user_rememberme_token
 * @return string
 */
function mcm_remember_me_hash($user_id, $token)
{
	return hash('sha256', $user_id . ':' . $token . COOKIE_SECRET_KEY);
}

/**
 * Build the value of a remember-me cookie: "<user id>:<token>:<hash>".
 *
 * @param string|int $user_id
 * @param string $token
 * @return string
 */
function mcm_remember_me_cookie_value($user_id, $token)
{
	return $user_id . ':' . $token . ':' . mcm_remember_me_hash($user_id, $token);
}

/**
 * Read a remember-me cookie, returning its parts only when the hash matches.
 *
 * The hash is compared in constant time, so a cookie that is being guessed at
 * gets no timing hint about how much of the guess was right. A malformed
 * cookie is simply invalid; the caller deletes it, as it always has.
 *
 * Passing the check means the cookie was issued by this site. It does not mean
 * the token is still current - only the database can say that.
 *
 * @param string $cookie the raw cookie value
 * @return array|false array('user_id' => string, 'token' => string), or false
 */
function mcm_remember_me_cookie_parts($cookie)
{
	if (!is_string($cookie)) {
		return false;
	}

	$parts = explode(':', $cookie);
	if (count($parts) !== 3) {
		return false;
	}

	list($user_id, $token, $hash) = $parts;
	if (empty($token)) {
		return false;
	}
	if (!mcm_hash_equals(mcm_remember_me_hash($user_id, $token), $hash)) {
		return false;
	}

	return array('user_id' => $user_id, 'token' => $token);
}

/**
 * Give the current session a new identifier, keeping its contents.
 *
 * Called at every point where a visitor's authentication state changes, so
 * that an identifier an attacker managed to plant in the browser beforehand is
 * not the one the authenticated session ends up using. The old session file is
 * removed, so the previous identifier stops working immediately.
 *
 * Failures are logged and reported back rather than thrown: the visitor has
 * just authenticated successfully, and losing the identifier renewal is not a
 * reason to fail the request they made.
 *
 * @return bool whether the identifier was renewed
 */
function mcm_session_regenerate_id()
{
	$active = function_exists('session_status')
		? (session_status() === PHP_SESSION_ACTIVE)
		: (session_id() !== '');

	if (!$active) {
		mcm_log('Session', 'the session identifier was not renewed: no session is active');
		return false;
	}

	// A new identifier travels in a Set-Cookie header, which is impossible once
	// the body has started.
	$file = '';
	$line = 0;
	if (headers_sent($file, $line)) {
		mcm_log('Session', 'the session identifier was not renewed: output had already started', $file, $line);
		return false;
	}

	if (!session_regenerate_id(true)) {
		mcm_log('Session', 'the session identifier could not be renewed');
		return false;
	}

	return true;
}
