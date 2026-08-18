<?php

/**
 * Static checks over the project's own sources: the PHP through PHP's own
 * tokenizer, and the browser scripts through the small scanner at the end of
 * this file.
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
	return mcm_count_calls_in(mcm_tokens($file), $function);
}

/**
 * The body of one function or method, as tokens.
 *
 * A fact about one authentication transition has to be read from that
 * transition's own code: a check that scans the whole file would accept a call
 * that sits in some other method entirely. Returns an empty array when the file
 * declares no such function.
 */
function mcm_method_tokens($file, $method)
{
	$tokens = mcm_tokens($file);
	$total  = count($tokens);

	foreach ($tokens as $index => $token) {
		if ($token['id'] !== T_FUNCTION) {
			continue;
		}
		if (!isset($tokens[$index + 1]) || $tokens[$index + 1]['id'] !== T_STRING
			|| strcasecmp($tokens[$index + 1]['text'], $method) !== 0) {
			continue;
		}

		// Walk past the parameter list: the body starts at the first "{" that is
		// not inside parentheses, which a default parameter value could be.
		$parentheses = 0;
		$body        = array();
		$depth       = 0;
		$started     = false;

		for ($cursor = $index + 2; $cursor < $total; $cursor++) {
			$text = $tokens[$cursor]['text'];
			$id   = $tokens[$cursor]['id'];

			if (!$started) {
				if ($text === '(') {
					$parentheses++;
				} elseif ($text === ')') {
					$parentheses--;
				} elseif ($text === '{' && $parentheses === 0) {
					$started = true;
					$depth   = 1;
				} elseif ($text === ';' && $parentheses === 0) {
					// An abstract or interface declaration has no body at all.
					return array();
				}
				continue;
			}

			// T_CURLY_OPEN and T_DOLLAR_OPEN_CURLY_BRACES are the "{" of a string
			// interpolation; their closing "}" is an ordinary token, so they have
			// to be counted or the braces stop balancing.
			if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
				$depth++;
			} elseif ($text === '}') {
				$depth--;
				if ($depth === 0) {
					return $body;
				}
			}
			$body[] = $tokens[$cursor];
		}
	}

	return array();
}

/** Count calls to a named function inside one function or method only. */
function mcm_method_calls($file, $method, $function)
{
	return mcm_count_calls_in(mcm_method_tokens($file, $method), $function);
}

/** Count calls to a named function in an already tokenized slice of source. */
function mcm_count_calls_in(array $tokens, $function)
{
	$count = 0;

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

/**
 * The arguments of every header() call in a file, one flat token string per
 * call.
 *
 * Reading the call's own tokens is what makes this usable as evidence: a
 * comment about the Host header, or a mention of it elsewhere in the file,
 * cannot be mistaken for a header the file actually sends.
 */
function mcm_header_calls($file)
{
	$tokens = mcm_tokens($file);
	$total  = count($tokens);
	$calls  = array();

	foreach ($tokens as $index => $token) {
		if ($token['id'] !== T_STRING || strcasecmp($token['text'], 'header') !== 0) {
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

		$depth = 0;
		$parts = array();
		for ($cursor = $index + 1; $cursor < $total; $cursor++) {
			$text = $tokens[$cursor]['text'];
			if ($text === '(') {
				$depth++;
				if ($depth === 1) {
					continue;
				}
			}
			if ($text === ')') {
				$depth--;
				if ($depth === 0) {
					break;
				}
			}
			$parts[] = $text;
		}
		$calls[] = implode(' ', $parts);
	}
	return $calls;
}

/**
 * Every header() call in $files that names something from the request itself,
 * as "<file>: <call>" lines.
 *
 * A destination built from the request's Host header is a destination the
 * request chooses, which is the defect this check exists to keep out.
 */
function mcm_request_derived_headers(array $files, $root)
{
	$found = array();

	foreach ($files as $file) {
		foreach (mcm_header_calls($file) as $call) {
			if (preg_match('/HTTP_HOST|SERVER_NAME|HTTP_X_FORWARDED_HOST/', $call)) {
				$name = strpos($file, $root) === 0 ? substr($file, strlen($root) + 1) : $file;
				$found[] = $name . ': ' . $call;
			}
		}
	}
	return $found;
}

/**
 * Count "new <Class>" expressions naming a class, ignoring comments.
 *
 * Reading tokens rather than text matters as much here as it does for
 * mcm_count_calls(): several files talk about PDO in prose.
 */
function mcm_count_new($file, $class)
{
	$tokens = mcm_tokens($file);
	$count  = 0;

	foreach ($tokens as $index => $token) {
		if ($token['id'] !== T_NEW || !isset($tokens[$index + 1])) {
			continue;
		}
		$next = $tokens[$index + 1];
		if ($next['id'] === T_STRING && strcasecmp($next['text'], $class) === 0) {
			$count++;
		}
	}
	return $count;
}

/**
 * Count reads of a bare constant, such as DB_PASS.
 *
 * A constant looks like any other T_STRING, so the things it is not have to be
 * excluded: a function call, a method or class member, and a declaration. The
 * name inside define('DB_PASS', ...) is a quoted string and is not counted,
 * which is what keeps the configuration files out of the result.
 */
function mcm_count_constant_reads($file, $name)
{
	$tokens = mcm_tokens($file);
	$count  = 0;

	foreach ($tokens as $index => $token) {
		if ($token['id'] !== T_STRING || strcmp($token['text'], $name) !== 0) {
			continue;
		}
		if (isset($tokens[$index + 1]) && $tokens[$index + 1]['text'] === '(') {
			continue;
		}
		if (isset($tokens[$index - 1])) {
			$previous = $tokens[$index - 1]['id'];
			if ($previous === T_FUNCTION || $previous === T_OBJECT_OPERATOR || $previous === T_DOUBLE_COLON || $previous === T_NEW || $previous === T_CONST) {
				continue;
			}
		}
		$count++;
	}
	return $count;
}

/**
 * Count calls to the functions that dump a value straight into the response.
 *
 * These are what turned a failed query into a page full of driver detail, so
 * application code may not call them at all. The return-a-string forms, such
 * as print_r($value, true), are refused with the rest: nothing in this
 * application needs one, and allowing them would mean judging each call site.
 */
function mcm_count_debug_output($file)
{
	$count = 0;
	foreach (array('var_dump', 'print_r', 'var_export', 'debug_zval_dump', 'debug_print_backtrace') as $function) {
		$count += mcm_count_calls($file, $function);
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

/** Every web-server rule file in the project. */
function mcm_htaccess_files($root)
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
			} elseif ($entry === '.htaccess') {
				$found[] = $path;
			}
		}
	}

	sort($found);
	return $found;
}

/**
 * Files that would put an HSTS header on a response, as project-relative names.
 *
 * Comments are not code, and this distinction is the point: the configuration
 * example explains in prose why the site does not send this header, and saying
 * so must not read as sending it. PHP is taken through the tokenizer, which
 * drops comments, and comment lines are stripped from the web-server rules.
 */
function mcm_hsts_sources($root)
{
	$found = array();

	foreach (mcm_php_sources($root) as $file) {
		if (stripos(mcm_flat_source($file), 'Strict-Transport-Security') !== false) {
			$found[] = substr($file, strlen($root) + 1);
		}
	}

	foreach (mcm_htaccess_files($root) as $file) {
		$rules = preg_replace('/^\s*#.*$/m', '', file_get_contents($file));
		if (stripos($rules, 'Strict-Transport-Security') !== false) {
			$found[] = substr($file, strlen($root) + 1);
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

/**
 * The entry points that have adopted the shared guards, project-relative and
 * sorted.
 *
 * Adoption is deliberate and endpoint by endpoint, so this is a written-down
 * list rather than something derived from the source: derived from the source
 * it would agree with whatever the source happens to do, which is the one thing
 * an adoption check must not do.
 *
 * @return array
 */
function mcm_guarded_entry_points()
{
	return array(
		'add_movie.php',
		'adjust_lists.php',
		'create_list.php',
		'delete_list.php',
		'delete_movie.php',
		'import_list.php',
		'move.php',
		'rename_list.php',
		'share_lists.php',
	);
}

/**
 * The statements in a file that write to the database, as flat token strings.
 *
 * "Writes" means the three verbs that change a row. Reading the statement's own
 * tokens is what makes this usable as evidence: a file that mentions a WHERE
 * clause in a comment, or qualifies a different query by owner elsewhere, must
 * not be able to satisfy an assertion about this one. Comments are already gone
 * by the time the tokenizer is done with them.
 *
 * @param string $file
 * @return array one flat string per writing statement
 */
function mcm_write_statements($file)
{
	$found = array();

	foreach (mcm_tokens($file) as $token) {
		if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
			continue;
		}
		$sql = trim($token['text'], "'\"");
		if (preg_match('/^\s*(UPDATE|DELETE|INSERT)\s/i', $sql) === 1) {
			$found[] = preg_replace('/\s+/', ' ', $sql);
		}
	}
	return $found;
}

/**
 * Whether a write statement from mcm_write_statements() actually restricts by
 * owner, rather than merely mentioning "user_id" somewhere in the string.
 *
 * A bare substring check (does "user_id" appear anywhere in the SQL text)
 * passes for an UPDATE that only sets user_id, or that names it in a comment-
 * like alias, and passes for a DELETE with no WHERE clause at all as long as
 * user_id shows up in the table or column list elsewhere. This instead parses
 * the statement's own shape: for an INSERT, user_id must be one of the columns
 * being written; for an UPDATE or DELETE, user_id must appear in the WHERE
 * clause bound to :user_id by equality, which is what a database driver would
 * actually use to restrict the rows touched.
 *
 * @param string $sql one flattened statement from mcm_write_statements()
 * @return bool
 */
function mcm_statement_owner_qualified($sql)
{
	$sql = trim($sql);

	if (preg_match('/^INSERT\s+INTO\s+\S+\s*\(([^)]*)\)/i', $sql, $matches) === 1) {
		$columns = array_map('trim', explode(',', $matches[1]));
		return in_array('user_id', $columns, true);
	}

	if (preg_match('/^(UPDATE|DELETE)\b/i', $sql) === 1) {
		if (preg_match('/\bWHERE\b(.*)$/is', $sql, $matches) !== 1) {
			return false;
		}
		return preg_match('/\buser_id\s*=\s*:user_id\b/i', $matches[1]) === 1;
	}

	return false;
}

/*
 * ---------------------------------------------------------------------------
 * The browser scripts
 * ---------------------------------------------------------------------------
 *
 * The server escapes what it renders; the page scripts have the same duty for
 * what they render afterwards, and js/dom.js is where they do it. These checks
 * read the scripts and state the two rules that keep a value out of markup:
 * markup this project wrote is never concatenated with a value, and .html() is
 * never handed anything but a literal.
 *
 * A rule about the source is not a rule about a browser. What the browser then
 * builds out of a hostile list name is tests/browser/xss.html, which is opened
 * by hand.
 */

/**
 * The browser scripts this project writes. The vendored libraries in js/ and
 * everything under js/libs/ are third-party downloads and are not this
 * project's to rewrite.
 */
function mcm_browser_sources($root)
{
	$vendored = array('bootstrap-tabdrop.js', 'jquery.lazyload.js', 'jquery.lazyload.min.js', 'typeahead.bundle.min.js');
	$found    = array();

	foreach (glob($root . '/js/*.js') as $file) {
		if (!in_array(basename($file), $vendored, true)) {
			$found[] = $file;
		}
	}
	sort($found);
	return $found;
}

/**
 * A script with every string literal replaced by a marker and every comment
 * dropped, so a check can read what surrounds a literal without parsing
 * JavaScript. A marker is "\x01<index>\x01", and the literals come back beside
 * the code.
 *
 * Strings and comments are recognised in one pass, in source order, which is
 * what keeps them from being confused with one another: the "//" inside
 * 'http://example.com' is inside a string that has already been matched, and an
 * apostrophe in a comment never starts a string.
 *
 * The one construct this does not model is a regular expression literal, whose
 * quotes would be read as the start of a string. The scripts this runs over
 * have none, and mcm_js_regex_literals() keeps it that way.
 *
 * @return array 'code' => the marked source, 'literals' => index => literal
 */
function mcm_js_markers($source)
{
	$literals = array();
	$pattern  = '~(//[^\n]*)|(/\*.*?\*/)|(\'(?:\\\\.|[^\'\\\\])*\')|("(?:\\\\.|[^"\\\\])*")|(`(?:\\\\.|[^`\\\\])*`)~s';

	$code = preg_replace_callback($pattern, function ($match) use (&$literals) {
		if (substr($match[0], 0, 1) === '/') {
			return ' ';
		}
		$literals[] = $match[0];

		return "\x01" . (count($literals) - 1) . "\x01";
	}, $source);

	return array('code' => $code, 'literals' => $literals);
}

/** A short, readable piece of scanned code for a failure message. */
function mcm_js_excerpt($text)
{
	$text = preg_replace('~\x01\d+\x01~', '<string>', $text);
	$text = trim(preg_replace('~\s+~', ' ', $text));
	if (strlen($text) > 60) {
		$text = substr($text, 0, 60) . '...';
	}
	return $text;
}

/** Whether a string literal is markup rather than a selector, a class or a message. */
function mcm_js_is_markup($literal)
{
	return preg_match('~<\s*[a-zA-Z/!]~', $literal) === 1;
}

/**
 * Places in a browser script where a value is joined to markup.
 *
 * Markup the file wrote itself is fine, and so is a value in a selector, a
 * class name or a URL: the defect is the join, because that is the only way a
 * list name or a TMDb title can become tags. Both sides of the "+" are checked,
 * and "+=" is not a join of that kind - it appends this file's own markup to a
 * string it is building.
 *
 * Returns the problems found; empty means the file builds no markup out of a
 * value.
 */
function mcm_js_markup_problems($file)
{
	$scan     = mcm_js_markers(file_get_contents($file));
	$code     = $scan['code'];
	$problems = array();

	foreach ($scan['literals'] as $index => $literal) {
		if (!mcm_js_is_markup($literal)) {
			continue;
		}

		// A template literal carries its own interpolation, so the markup and
		// the value are already one string.
		if (substr($literal, 0, 1) === '`' && strpos($literal, '${') !== false) {
			$problems[] = 'a value is interpolated into markup: ' . mcm_js_excerpt($literal);
			continue;
		}

		$marker = "\x01" . $index . "\x01";
		$at     = strpos($code, $marker);
		if ($at === false) {
			continue;
		}

		$after = ltrim(substr($code, $at + strlen($marker)));
		if (substr($after, 0, 1) === '+' && substr($after, 1, 1) !== '=' && substr($after, 1, 1) !== '+') {
			$operand = ltrim(substr($after, 1));
			if (substr($operand, 0, 1) !== "\x01") {
				$problems[] = 'a value is concatenated onto markup: ' . mcm_js_excerpt($literal) . ' + ' . mcm_js_excerpt(substr($operand, 0, 40));
			}
		}

		$before = rtrim(substr($code, 0, $at));
		if (substr($before, -1) === '+' && substr($before, -2, 1) !== '+') {
			$operand = rtrim(substr($before, 0, -1));
			if (substr($operand, -1) !== "\x01") {
				$problems[] = 'markup is concatenated onto a value: ' . mcm_js_excerpt(substr($operand, -40)) . ' + ' . mcm_js_excerpt($literal);
			}
		}
	}
	return $problems;
}

/**
 * .html() calls in a browser script that assign something other than a literal.
 *
 * .html() parses whatever it is given, so a value handed to it is markup
 * however carefully the rest of the file was written. Reading with .html(), and
 * assigning a literal this file wrote, are both fine.
 */
function mcm_js_html_assignments($file)
{
	$scan   = mcm_js_markers(file_get_contents($file));
	$code   = $scan['code'];
	$found  = array();
	$offset = 0;

	while (($at = strpos($code, '.html(', $offset)) !== false) {
		$offset   = $at + 6;
		$argument = ltrim(substr($code, $offset));

		// ".html()" reads; ".html('<p>x</p>')" assigns markup from this file.
		if (substr($argument, 0, 1) === ')' || preg_match('~^\x01\d+\x01\s*\)~', $argument) === 1) {
			continue;
		}
		// The argument alone, so the failure message names the call rather than
		// the line that happened to follow it.
		$found[] = mcm_js_excerpt(substr($argument, 0, min(strcspn($argument, ");,\n"), 60)));
	}
	return $found;
}

/**
 * Regular expression literals in a browser script.
 *
 * The scanner above reads a "/" as division, so a regular expression holding a
 * quote would derail it. This looks for the shape of one - a "/" where an
 * expression can start - and the suite refuses it, so the scanner's one blind
 * spot cannot open quietly.
 */
function mcm_js_regex_literals($file)
{
	// The marked code, so a slash inside a string or a comment - the "//" of a
	// URL, most of all - is not mistaken for one.
	$scan  = mcm_js_markers(file_get_contents($file));
	$code  = $scan['code'];
	$found = array();

	if (preg_match_all('~(?:^|[=(,:!&|?{};]|\breturn\b)\s*/(?![/*])~m', $code, $matches, PREG_OFFSET_CAPTURE) > 0) {
		foreach ($matches[0] as $match) {
			$found[] = mcm_js_excerpt(substr($code, $match[1], 40));
		}
	}
	return $found;
}
