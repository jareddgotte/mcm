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
 * One named function's own code, as a line of space-separated tokens, so a case
 * can say what that function does without reading the rest of the file.
 *
 * @return string '' when the file declares no such function
 */
function mcm_function_source($file, $function)
{
	$tokens = mcm_tokens($file);
	$total  = count($tokens);

	foreach ($tokens as $index => $token) {
		if ($token['id'] !== T_FUNCTION || !isset($tokens[$index + 1])
			|| strcasecmp($tokens[$index + 1]['text'], $function) !== 0) {
			continue;
		}

		$depth = 0;
		$parts = array();
		for ($cursor = $index; $cursor < $total; $cursor++) {
			$parts[] = trim($tokens[$cursor]['text']);
			if ($tokens[$cursor]['text'] === '{') {
				$depth++;
			} elseif ($tokens[$cursor]['text'] === '}') {
				$depth--;
				if ($depth === 0) {
					break;
				}
			}
		}
		return implode(' ', $parts);
	}
	return '';
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

/**
 * The escaping helpers a rendered value is allowed to have passed through.
 */
function mcm_escapers()
{
	return array('mcm_html', 'mcm_url', 'mcm_js');
}

/**
 * The statement a token belongs to, as a slice of the token list.
 *
 * Statement boundaries are the plain ones - ";", a brace, a PHP tag - which is
 * enough here because the check only ever asks what a single statement does
 * with a value, and never reads a fact off the rest of the file.
 *
 * @return array the tokens of the statement, in order
 */
function mcm_statement_of(array $tokens, $index)
{
	// "<?=" is not a boundary: it is the statement's own echo, and the caller
	// needs to see it to know the statement outputs.
	$stoppers = array(';', '{', '}');
	$tags     = array(T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML);

	$start = $index;
	while ($start > 0) {
		$previous = $tokens[$start - 1];
		if (in_array($previous['text'], $stoppers, true) || in_array($previous['id'], $tags, true)) {
			break;
		}
		$start--;
	}

	$end  = $index;
	$last = count($tokens) - 1;
	while ($end < $last) {
		$current = $tokens[$end];
		if (in_array($current['text'], $stoppers, true) || in_array($current['id'], $tags, true)) {
			break;
		}
		$end++;
	}

	return array_slice($tokens, $start, $end - $start + 1);
}

/**
 * Request values that reach the page, and so have to be escaped on the way out.
 * $_SERVER is deliberately absent: what the site does with the request host and
 * path is a redirect question rather than an escaping one.
 */
function mcm_request_superglobals()
{
	return array('$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SESSION');
}

/**
 * Reads of a request value that this file renders without escaping it.
 *
 * A statement is checked when it either outputs (echo, print, printf, sprintf)
 * or concatenates onto a string literal, which is how every page here builds
 * its markup. header() statements are left alone - a Location value is not
 * HTML - and isset()/empty()/array_key_exists() are reads that render nothing.
 *
 * Returns the problems found; empty means the file escapes what it renders.
 */
function mcm_escaping_problems($file)
{
	$tokens   = mcm_tokens($file);
	$outputs  = array('printf', 'sprintf', 'vprintf', 'vsprintf', 'print_r');
	$exempt   = array_merge(mcm_escapers(), array('array_key_exists'));
	// isset(), empty() and unset() are language constructs with tokens of their
	// own rather than T_STRING function names.
	$constructs = array(T_ISSET, T_EMPTY, T_UNSET);
	$problems = array();

	foreach ($tokens as $index => $token) {
		if ($token['id'] !== T_VARIABLE || !in_array($token['text'], mcm_request_superglobals(), true)) {
			continue;
		}

		$statement  = mcm_statement_of($tokens, $index);
		$first      = $statement[0];
		$echoes     = array(T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO);
		$isOutput   = in_array($first['id'], $echoes, true);
		$isHeader   = ($first['id'] === T_STRING && strcasecmp($first['text'], 'header') === 0);
		$hasLiteral = false;
		$hasConcat  = false;
		$text       = '';

		foreach ($statement as $position => $current) {
			$text .= $current['text'] . ' ';
			if ($current['id'] === T_CONSTANT_ENCAPSED_STRING) {
				$hasLiteral = true;
			}
			if ($current['text'] === '.') {
				$hasConcat = true;
			}
			if ($current['id'] === T_STRING && in_array(strtolower($current['text']), $outputs, true)
				&& isset($statement[$position + 1]) && $statement[$position + 1]['text'] === '(') {
				$isOutput = true;
			}
		}

		if ($isHeader || (!$isOutput && !($hasLiteral && $hasConcat))) {
			continue;
		}

		$wrapped = isset($tokens[$index - 2])
			&& $tokens[$index - 1]['text'] === '('
			&& (in_array($tokens[$index - 2]['id'], $constructs, true)
				|| ($tokens[$index - 2]['id'] === T_STRING
					&& in_array(strtolower($tokens[$index - 2]['text']), $exempt, true)));

		if (!$wrapped) {
			$problems[] = $token['text'] . ' is rendered without an escaping helper: ' . rtrim($text);
		}
	}
	return $problems;
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
