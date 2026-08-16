<?php

/**
 * Static checks over the project's PHP sources, using PHP's own tokenizer.
 *
 * Comments are stripped before any check, so a sentence such as "this is the
 * only session_start() in the application" cannot be mistaken for a call.
 */

/**
 * Tokenize a file, dropping whitespace and comments. Each token is
 * array('id' => int|null, 'text' => string); id is null for single-character
 * tokens such as ";" and ".".
 */
function mcm_tokens($file)
{
	$kept = array();
	foreach (token_get_all(file_get_contents($file)) as $token) {
		if (!is_array($token)) {
			$kept[] = array('id' => null, 'text' => $token);
			continue;
		}
		if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
			continue;
		}
		$kept[] = array('id' => $token[0], 'text' => $token[1]);
	}
	return $kept;
}

/**
 * The file's code as one line of space-separated tokens, for asserting that one
 * construct comes before another without hand-parsing the file.
 */
function mcm_flat_source($file)
{
	$parts = array();
	foreach (mcm_tokens($file) as $token) {
		$parts[] = trim($token['text']);
	}
	return implode(' ', $parts);
}

/**
 * Count calls to a named function, ignoring comments, method calls and the
 * function's own declaration.
 */
function mcm_count_calls($file, $function)
{
	$tokens = mcm_tokens($file);
	$count  = 0;

	foreach ($tokens as $index => $token) {
		if ($token['id'] !== T_STRING || strcasecmp($token['text'], $function) !== 0) {
			continue;
		}
		if (!isset($tokens[$index + 1]) || $tokens[$index + 1]['text'] !== '(') {
			continue;
		}
		if (isset($tokens[$index - 1])) {
			$previous = $tokens[$index - 1]['id'];
			if ($previous === T_FUNCTION || $previous === T_OBJECT_OPERATOR || $previous === T_DOUBLE_COLON) {
				continue;
			}
		}
		$count++;
	}
	return $count;
}

/** Every PHP file in the project, excluding this test suite. */
function mcm_php_sources($root)
{
	$found = array();
	$queue = array($root);

	while (count($queue) > 0) {
		$directory = array_shift($queue);
		foreach (scandir($directory) as $entry) {
			if ($entry === '.' || $entry === '..' || $entry === '.git' || $entry === 'tests') {
				continue;
			}
			$path = $directory . '/' . $entry;
			if (is_dir($path)) {
				$queue[] = $path;
			} elseif (substr($entry, -4) === '.php') {
				$found[] = $path;
			}
		}
	}

	sort($found);
	return $found;
}

/**
 * The public entry points: every PHP file in the document root, plus the
 * captcha image, which the browser requests directly (see inc/.htaccess).
 */
function mcm_entry_points($root)
{
	$files = glob($root . '/*.php');
	sort($files);

	$captcha = $root . '/inc/showCaptcha.php';
	if (file_exists($captcha)) {
		$files[] = $captcha;
	}
	return $files;
}

/**
 * The include/require statements in a file, as
 * array('position', 'once', 'anchored', 'literals', 'text').
 *
 * "anchored" is decided from the statement's own tokens, never from the rest of
 * the file: a page whose include relies on the include path must stay rejected
 * however often it mentions __DIR__ somewhere else.
 */
function mcm_include_statements($file)
{
	$tokens     = mcm_tokens($file);
	$forms      = array(T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE);
	$total      = count($tokens);
	$statements = array();

	foreach ($tokens as $index => $token) {
		if (!in_array($token['id'], $forms, true)) {
			continue;
		}

		$literals = array();
		$anchored = false;
		$text     = '';

		for ($cursor = $index; $cursor < $total; $cursor++) {
			$current = $tokens[$cursor];
			$text   .= $current['text'] . ' ';

			if ($current['id'] === T_CONSTANT_ENCAPSED_STRING) {
				$literals[] = substr($current['text'], 1, -1);
			}
			if ($current['id'] === T_DIR || $current['id'] === T_FILE) {
				$anchored = true;
			}
			if ($current['text'] === ';') {
				break;
			}
		}

		$statements[] = array(
			'position' => $index,
			'once'     => ($token['id'] === T_REQUIRE_ONCE || $token['id'] === T_INCLUDE_ONCE),
			'anchored' => $anchored,
			'literals' => $literals,
			'text'     => rtrim($text),
		);
	}
	return $statements;
}

/**
 * Check one entry point against the four rules issue #17 states, plus two cheap
 * robustness rules: the include is anchored to the file's own directory, and it
 * resolves to this project's bootstrap rather than a same-named file elsewhere.
 * Returns the problems found; empty means the file is fine.
 */
function mcm_check_entry_point($file, $root)
{
	$includes = array();
	foreach (mcm_include_statements($file) as $statement) {
		foreach ($statement['literals'] as $literal) {
			if (substr($literal, -13) === 'bootstrap.php') {
				$includes[] = $statement;
				break;
			}
		}
	}

	if (count($includes) === 0) {
		return array('does not include the bootstrap at all');
	}

	$problems = array();
	$first    = $includes[0];
	$tokens   = mcm_tokens($file);

	if (count($includes) > 1) {
		$problems[] = 'includes the bootstrap ' . count($includes) . ' times';
	}
	if (!$first['once']) {
		$problems[] = 'includes the bootstrap with a non-once form: ' . $first['text'];
	}

	// The opening tag is the first significant token; the bootstrap include has
	// to be the statement right after it, before anything else can run.
	$leading = isset($tokens[0]) && $tokens[0]['id'] === T_OPEN_TAG ? 1 : 0;
	if ($first['position'] !== $leading) {
		$problems[] = 'the bootstrap is not the first statement in the file';
	}

	if (!$first['anchored']) {
		$problems[] = 'the include path is not anchored to __DIR__ or __FILE__';
	}

	$literal = end($first['literals']);
	$target  = realpath(dirname($file) . '/' . ltrim($literal, '/'));
	if ($target === false || $target !== realpath($root . '/inc/bootstrap.php')) {
		$problems[] = 'the include does not resolve to inc/bootstrap.php (' . $literal . ')';
	}
	return $problems;
}
