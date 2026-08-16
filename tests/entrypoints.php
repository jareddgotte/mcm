<?php

/**
 * Static checks over the project's PHP sources, using PHP's own tokenizer.
 *
 * Two things are checked here rather than at runtime, because both are claims
 * about the source as a whole and a single request can only ever visit part of
 * it:
 *
 *   - every public entry point loads the shared bootstrap first, exactly once,
 *     and through an include-once form;
 *   - the application starts a session in exactly one place.
 *
 * Comments are stripped before either check, so a sentence such as "this is the
 * only session_start() in the application" cannot be mistaken for a call.
 */

/**
 * Tokenize a file, dropping whitespace and comments.
 *
 * @param string $file
 * @return array list of array('id' => int|null, 'text' => string); id is null
 *               for single-character tokens such as ";" and "."
 */
function mcm_tokens($file)
{
	$tokens = token_get_all(file_get_contents($file));
	$kept   = array();

	foreach ($tokens as $token) {
		if (is_array($token)) {
			if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
				continue;
			}
			$kept[] = array('id' => $token[0], 'text' => $token[1]);
		} else {
			$kept[] = array('id' => null, 'text' => $token);
		}
	}

	return $kept;
}

/**
 * The file's code with comments and formatting removed, rendered as one line of
 * space-separated tokens. Useful for asserting that one construct comes before
 * another without hand-parsing the file.
 *
 * @param string $file
 * @return string
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
 * Count calls to a named function in a file, ignoring comments, method calls
 * and the function's own declaration.
 *
 * @param string $file
 * @param string $function
 * @return int
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

/**
 * Every PHP file in the project, excluding this test suite.
 *
 * @param string $root
 * @return array absolute paths
 */
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
 *
 * @param string $root
 * @return array absolute paths
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
 * Find the include/require statements in a file.
 *
 * @param string $file
 * @return array list of array('position', 'once', 'literals', 'text')
 */
function mcm_include_statements($file)
{
	$tokens     = mcm_tokens($file);
	$statements = array();
	$forms      = array(T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE);

	foreach ($tokens as $index => $token) {
		if ($token['id'] === null || !in_array($token['id'], $forms, true)) {
			continue;
		}

		$literals = array();
		$text     = '';
		for ($cursor = $index; $cursor < count($tokens); $cursor++) {
			$current = $tokens[$cursor];
			$text   .= $current['text'] . ' ';
			if ($current['id'] === T_CONSTANT_ENCAPSED_STRING) {
				$literals[] = substr($current['text'], 1, -1);
			}
			if ($current['text'] === ';') {
				break;
			}
		}

		$statements[] = array(
			'position' => $index,
			'once'     => ($token['id'] === T_REQUIRE_ONCE || $token['id'] === T_INCLUDE_ONCE),
			'literals' => $literals,
			'text'     => rtrim($text),
		);
	}

	return $statements;
}

/**
 * Check one entry point.
 *
 * @param string $file absolute path
 * @param string $root document root the file belongs to
 * @return array list of problems; empty means the file is fine
 */
function mcm_check_entry_point($file, $root)
{
	$problems  = array();
	$tokens    = mcm_tokens($file);
	$bootstrap = realpath($root . '/inc/bootstrap.php');

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
	if (count($includes) > 1) {
		$problems[] = 'includes the bootstrap ' . count($includes) . ' times';
	}

	$first = $includes[0];

	if (!$first['once']) {
		$problems[] = 'includes the bootstrap with a non-once form: ' . $first['text'];
	}

	// The first significant token is the opening tag; the bootstrap include has
	// to be the statement right after it, before anything else can run.
	$leading = isset($tokens[0]) && $tokens[0]['id'] === T_OPEN_TAG ? 1 : 0;
	if ($first['position'] !== $leading) {
		$problems[] = 'the bootstrap is not the first statement in the file';
	}

	// The include has to be anchored to the file's own directory, so it cannot
	// depend on the working directory or the include path.
	$anchored = false;
	foreach ($tokens as $token) {
		if ($token['id'] === T_DIR || $token['id'] === T_FILE) {
			$anchored = true;
			break;
		}
	}
	if (!$anchored) {
		$problems[] = 'the include path is not anchored to __DIR__ or __FILE__';
	}

	$literal = end($first['literals']);
	$target  = realpath(dirname($file) . '/' . ltrim($literal, '/'));
	if ($target === false || $target !== $bootstrap) {
		$problems[] = 'the include does not resolve to inc/bootstrap.php (' . $literal . ')';
	}

	return $problems;
}
