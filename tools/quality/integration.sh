#!/usr/bin/env bash
#
# The longer tier: everything that needs more than a PHP process.
#
#     tools/quality/integration.sh
#     MCM_TEST_MYSQLD=/path/to/bin/mariadbd \
#     MCM_TEST_PHP=/path/to/php8.1:/path/to/php8.4 \
#     tools/quality/integration.sh
#     tools/quality/integration.sh --require browser,database,mail-runtimes
#
# This tier is not meant to run on every keystroke. It opens sockets, starts
# PHP's built-in server several times over, launches a real browser, runs a
# private database server and drives the mail path on every PHP CLI it is
# given, and it takes minutes rather than seconds. It is what a change is
# measured against before it is considered ready, not what tells you whether
# you typed a semicolon.
#
# What it runs, and what a failure means:
#
#   browser        tests/browser/run.js - the hostile-value page in a real,
#                  layout-capable Chromium. A failure means a value a visitor
#                  typed reached the document as markup, or that the tab strip
#                  wrapped and renameList() would rename the wrong tab.
#   suite-full     every group under the dependency-free runner, including the
#                  server, mail and database ones. A failure is an ordinary
#                  test failure and the log names the assertion.
#   phpunit-full   the same groups under PHPUnit, which is where
#                  build/logs/junit.xml comes from.
#   runners-agree  the two runners made the same number of assertions. This is
#                  the one failure a green run looks exactly like: a group that
#                  quietly stopped being reached under one runner still leaves
#                  every other check green. Compared only between two green
#                  runs - see where it is called for why.
#   database       whether the database-backed groups actually ran, read off
#                  the run's own output. Three regression classes are invisible
#                  without a server; tests/database.php names them and this
#                  copies its words into the summary.
#   mail-runtimes  whether the mail check saw more than one PHP runtime. One
#                  runtime is one data point, and what a send does is decided
#                  by the runtime.
#
# What it deliberately does not do: it reaches neither TMDb nor the live site.
# Every outbound request goes to a stand-in inside the run's own throw-away
# fixture, the mail path ends at a stand-in bound to 127.0.0.1, and the
# database server is a private one this run creates and destroys. It installs
# nothing and it deploys nothing.
#
# Nothing here is silenced. On PHP 8.5 the captcha's relative font path stops
# working (see AGENTS.md), so this tier fails there on purpose; 8.5 is forward
# evidence and must not gate anything while that assertion stands.
#
# Exits non-zero if any check failed. A SKIP is not a failure unless the caller
# named that prerequisite with --require, which is what an automated run does
# so that a missing browser or database server cannot pass for a green one.

set -u

. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

while [ $# -gt 0 ]; do
	case "$1" in
		--require) Q_REQUIRED="$2"; shift 2 ;;
		--require=*) Q_REQUIRED="${1#--require=}"; shift ;;
		-h|--help)
			cat <<'USAGE'
Usage: tools/quality/integration.sh [--require browser,database,mail-runtimes,phpunit]

The longer tier: the real-browser page, every test group under both runners,
and the mail and database coverage that needs more than a PHP process.
Reads MCM_QUALITY_PHP, MCM_TEST_MYSQLD and MCM_TEST_PHP. Records the run in
build/quality/integration/. --require turns the named loud skips into
failures. See the comment at the top of this file, README.md and AGENTS.md.
USAGE
			exit 0 ;;
		*)
			printf 'Usage: tools/quality/integration.sh [--require <prerequisite>[,<prerequisite>]]\n' >&2
			exit 2 ;;
	esac
done

cd "$Q_ROOT"

php_bin="$(q_php)"
if ! "$php_bin" -v >/dev/null 2>&1; then
	printf 'no PHP CLI: %s is not runnable. Set MCM_QUALITY_PHP.\n' "$php_bin" >&2
	exit 2
fi

q_init integration

# -- the real browser --------------------------------------------------------

q_browser () {
	( cd tests/browser && node run.js )
}

if ! command -v node >/dev/null 2>&1; then
	q_skip browser 'the hostile-value page in a real browser' \
		'there is no node on PATH' \
		'what a browser actually builds out of a hostile list name, movie title, poster path or movie identifier' \
		'the two checks that depend on layout - a wrapped tab strip makes renameList() rename the wrong tab'
	q_enforce browser browser
elif [ ! -d tests/browser/node_modules ]; then
	q_skip browser 'the hostile-value page in a real browser' \
		'tests/browser/node_modules is not there - run "npm ci" in tests/browser' \
		'what a browser actually builds out of a hostile list name, movie title, poster path or movie identifier' \
		'the two checks that depend on layout - a wrapped tab strip makes renameList() rename the wrong tab'
	q_enforce browser browser
else
	# run.js exits zero and says SKIP when it cannot launch a browser, so that
	# a developer without one is not blocked. The record follows what it said,
	# not what it exited with.
	q_run browser 'the hostile-value page in a real browser' q_browser
	q_note_skips browser
	q_downgrade_on_skip browser
	q_enforce browser browser
fi

# -- every group, both runners -----------------------------------------------

q_run suite-full 'every group, dependency-free runner' \
	"$php_bin" tests/run.php

q_note_skips suite-full

# What the run itself said about the two optional halves of its own coverage.
# The pattern keys on the group's own universal skip banner
# (mcm_db_skip_notice() in tests/database.php) rather than one or two known
# reasons, so a server that was found but whose initialization failed - not
# just one that was never there - is caught the same way.
q_coverage database 'database-backed coverage' \
	"$Q_DIR/suite-full.log" \
	"$Q_DB_SKIP_PATTERN" \
	'a call that is present in a method but never reached' \
	'a value written to a column too narrow to hold it' \
	'a WHERE clause that stops restricting the rows it reads or changes' \
	'whose list a request names, and what an authorized import actually writes' \
	'set MCM_TEST_MYSQLD to a mariadbd or mysqld binary - see README.md'
q_note_skips database
q_enforce database database

q_coverage mail-runtimes 'cross-runtime mail coverage' \
	"$Q_DIR/suite-full.log" \
	'MCM_TEST_PHP names no other PHP CLI binary' \
	'whether the each() failure in the vendored SMTP library behaves the same on every runtime in play' \
	'one runtime is one data point, and what a send does is decided by the runtime' \
	'set MCM_TEST_PHP to further PHP CLI binaries, separated as PATH separates directories'
q_note_skips mail-runtimes
q_enforce mail-runtimes mail-runtimes

if [ -f vendor/bin/phpunit ] && q_php_at_least 8.3; then
	q_run phpunit-full 'every group, PHPUnit' \
		"$php_bin" vendor/bin/phpunit
	q_note_skips phpunit-full
elif [ ! -f vendor/bin/phpunit ]; then
	q_skip phpunit-full 'every group, PHPUnit' \
		'there is no vendor/bin/phpunit - run "composer install" first' \
		'test discovery, group selection and the JUnit report at build/logs/junit.xml' \
		'the agreement between the two runners, which is what runners-agree below needs'
	q_enforce phpunit-full phpunit
else
	q_skip phpunit-full 'every group, PHPUnit' \
		"PHPUnit 12 requires PHP 8.3 and this is PHP $(q_php_version)" \
		'the JUnit report - no assertion, because suite-full made every one of them on this runtime' \
		'this skip is expected on 8.1 and is the reason the dependency-free runner stays'
	q_enforce phpunit-full phpunit
fi

# -- the two runners agree ---------------------------------------------------

# An assertion count is the only thing that can tell a group that stopped being
# reached under one runner from a group that passed, because both look like a
# green run. The numbers are read out of each runner's own summary line rather
# than derived by arithmetic, for the same reason AGENTS.md gives.
q_agreement () {
	local runner_total phpunit_total

	runner_total=$(sed -nE 's/^(PASS|FAIL): ([0-9]+) assertions.*/\2/p' "$Q_DIR/suite-full.log" | tail -1)
	phpunit_total=$(sed -nE 's/.*<testsuite [^>]*assertions="([0-9]+)".*/\1/p' build/logs/junit.xml 2>/dev/null | head -1)

	printf 'dependency-free runner: %s assertions\n' "${runner_total:-<none reported>}"
	printf 'PHPUnit:                %s assertions\n' "${phpunit_total:-<none reported>}"

	if [ -z "$runner_total" ] || [ -z "$phpunit_total" ]; then
		printf 'one of the two runners reported no assertion count at all\n'
		return 1
	fi

	if [ "$runner_total" != "$phpunit_total" ]; then
		printf 'the two runners disagree, which is what a group reached by only one of them looks like\n'
		return 1
	fi

	printf 'the two runners agree\n'
	return 0
}

if [ ! -f build/logs/junit.xml ]; then
	q_skip runners-agree 'both runners made the same number of assertions' \
		'there is no build/logs/junit.xml, so PHPUnit did not run here' \
		'the one failure a green run looks exactly like - a group reached by only one runner'
	q_enforce runners-agree phpunit
elif [ "$(q_status_of suite-full)" != PASS ] || [ "$(q_status_of phpunit-full)" != PASS ]; then
	# The comparison only means anything between two green runs. The PHPUnit
	# bridge ends a failing group with one Assert::fail(), which PHPUnit counts
	# as an assertion, so a run with failures is a run where PHPUnit's total is
	# the other runner's plus one per failing group - a difference that says
	# nothing about which groups were reached. The tier has already failed on
	# those runs, so nothing is being hidden by declining to compare.
	q_skip runners-agree 'both runners made the same number of assertions' \
		'one of the two runners did not finish green, and the totals are only comparable between two green runs' \
		'whether a group stopped being reached under one runner - ask again once the failures above are fixed'
else
	q_run runners-agree 'both runners made the same number of assertions' q_agreement
fi

q_finish
