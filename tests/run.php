<?php

/**
 * The dependency-free runner.
 *
 *     php tests/run.php                      run every group
 *     php tests/run.php --filter=cookie      run the groups whose name matches
 *     php tests/run.php --group=quick        run the groups carrying that tag
 *     php tests/run.php --list               name every group and its tags
 *
 * Needs nothing but a PHP CLI: no package manager, no Composer tree, no
 * framework, no database. It is the only runner that reaches PHP 8.1, which is
 * why it stays: PHPUnit 12 requires PHP 8.3 and so cannot say anything about
 * the older runtime the site may still be running on.
 *
 * The cases are not here. They are in tests/cases.php, the static checks in
 * tests/entrypoints.php and the optional database group in tests/database.php,
 * all written against the harness in tests/harness.php - which PHPUnit consumes
 * too, through tests/phpunit/. An assertion is written once and neither runner
 * owns it.
 *
 * One group is the exception, and only ever by addition: tests/database.php
 * runs a private, throw-away database server when a developer has a server
 * binary to run, and prints a loud skip naming the uncovered regressions when
 * they do not. The suite passes either way.
 *
 * tests/mail.php is the same shape for the mail path: a stand-in for the far
 * end of a send, and the other PHP runtimes MCM_TEST_PHP names, both optional
 * and both loud about what is not covered without them.
 */

if (PHP_SAPI !== 'cli') {
	die("This is a command line test suite.\n");
}

require_once dirname(__FILE__) . '/harness.php';
require_once MCM_TESTS_DIR . '/entrypoints.php';
require_once MCM_TESTS_DIR . '/database.php';
require_once MCM_TESTS_DIR . '/mail.php';
require_once MCM_TESTS_DIR . '/cases.php';

$mcm_filter = '';
$mcm_tag    = '';
$mcm_list   = false;
foreach (array_slice($argv, 1) as $mcm_argument) {
	if (strpos($mcm_argument, '--filter=') === 0) {
		$mcm_filter = substr($mcm_argument, 9);
	} elseif (strpos($mcm_argument, '--group=') === 0) {
		$mcm_tag = substr($mcm_argument, 8);
	} elseif ($mcm_argument === '--list') {
		$mcm_list = true;
	} else {
		die("Usage: php tests/run.php [--filter=<substring>] [--group=<tag>] [--list]\n");
	}
}

if ($mcm_list) {
	foreach (mcm_groups() as $mcm_group) {
		echo implode(',', mcm_group_tags($mcm_group)) . "\t" . $mcm_group['name'] . "\n";
	}
	exit(0);
}

echo 'mcm bootstrap test suite - PHP ' . PHP_VERSION . "\n";

$mcm_ran = 0;
foreach (mcm_groups() as $mcm_group) {
	if (!mcm_group_selected($mcm_group, $mcm_filter, $mcm_tag)) {
		continue;
	}

	$mcm_ran++;
	$GLOBALS['mcm_state']['group'] = $mcm_group['name'];
	echo "\n== " . $mcm_group['name'] . " ==\n";
	call_user_func($mcm_group['callback']);
}

// A selection that matched nothing is a caller's mistake, not a green run: a
// misspelled filter would otherwise report a pass having asserted nothing.
if ($mcm_ran === 0) {
	echo "\nno group matched the selection\n";
	exit(2);
}

$mcm_failures = $GLOBALS['mcm_state']['failures'];

echo "\n";
foreach ($mcm_failures as $mcm_failure) {
	echo 'failed: [' . $mcm_failure['group'] . '] ' . $mcm_failure['label'] . "\n";
}

echo sprintf(
	"%s: %d assertions, %d failures, %d skipped in %.2fs\n",
	count($mcm_failures) === 0 ? 'PASS' : 'FAIL',
	$GLOBALS['mcm_state']['assertions'],
	count($mcm_failures),
	$GLOBALS['mcm_state']['skipped'],
	microtime(true) - $GLOBALS['mcm_state']['started']
);

// Every server this run started, and everything it wrote, goes here. It is
// registered as a shutdown function too, so an interrupt or a fatal reaches it
// as well; calling it twice is harmless.
mcm_cleanup();

exit(count($mcm_failures) === 0 ? 0 : 1);
