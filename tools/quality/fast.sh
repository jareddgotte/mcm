#!/usr/bin/env bash
#
# The fast tier: the checks that come back while a change is still being read.
#
#     tools/quality/fast.sh
#     MCM_QUALITY_PHP=/path/to/php8.3 tools/quality/fast.sh
#     tools/quality/fast.sh --require phpunit
#
# Its whole reason for being separate is speed. It needs one PHP CLI and, for
# one of its five checks, the Composer tool tree; it starts no web server, opens
# no socket, launches no browser and connects to no database. On the machine it
# was written on it takes about twenty seconds. If it ever stops being quick
# that is a regression in its own right, and the intent is written down here so
# the regression is visible rather than merely felt.
#
# What it runs, and what a failure means:
#
#   parse          every tracked PHP file compiles. A failure is a white page
#                  for whoever loads that file, not a red test - deployment is
#                  manual and additive.
#   lint           the reserved formatting / static-analysis lane. Always
#                  RESERVED, never PASS: nothing fills it yet, and which tool
#                  will is a decision held elsewhere.
#   hygiene        the credential and example-configuration checks the project
#                  used to make by hand, run by group name. A failure means a
#                  credential, a TMDb host or a real value has reached a file
#                  that is served to a browser or tracked as documentation.
#   suite-quick    every test group that listens on no socket, under the
#                  dependency-free runner.
#   phpunit-quick  the same groups under PHPUnit, which is what produces
#                  build/logs/junit.xml. Loud SKIP where PHPUnit cannot run -
#                  no Composer tree, or a PHP older than 8.3.
#
# What it deliberately does not do: anything that needs a socket, a browser, a
# mail path or a database server. All of that is the longer tier - see
# tools/quality/integration.sh - and a change is not ready on this tier alone.
#
# Exits non-zero if any check failed. A SKIP is not a failure unless the caller
# named that prerequisite with --require.

set -u

. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

# The hygiene groups, by the names they register under in tests/cases.php. They
# are named rather than selected by tag on purpose: they are also part of
# suite-quick, and running them again by name is what gives them a line of
# their own in the recorded result. A rename here fails loudly, because the
# runner treats a selection that matched nothing as an error.
Q_HYGIENE_GROUPS=(
	'tmdb credential hygiene in the source'
	'the movie search leaves no credential in the browser'
	'the vendored TMDb wrapper and auth-session path are gone'
)

while [ $# -gt 0 ]; do
	case "$1" in
		--require) Q_REQUIRED="$2"; shift 2 ;;
		--require=*) Q_REQUIRED="${1#--require=}"; shift ;;
		-h|--help)
			cat <<'USAGE'
Usage: tools/quality/fast.sh [--require phpunit]

The fast tier: parse sweep, the reserved lint lane, credential and
example-configuration hygiene, and the quick test groups under both runners.
Reads MCM_QUALITY_PHP for the PHP CLI to use. Records the run in
build/quality/fast/. --require phpunit turns the PHPUnit skip into a failure.
See the comment at the top of this file, README.md and AGENTS.md.
USAGE
			exit 0 ;;
		*)
			printf 'Usage: tools/quality/fast.sh [--require phpunit]\n' >&2
			exit 2 ;;
	esac
done

cd "$Q_ROOT"

php_bin="$(q_php)"
if ! "$php_bin" -v >/dev/null 2>&1; then
	printf 'no PHP CLI: %s is not runnable. Set MCM_QUALITY_PHP.\n' "$php_bin" >&2
	exit 2
fi

q_init fast

# -- parse -------------------------------------------------------------------

q_run parse 'every tracked PHP file compiles' \
	bash tools/quality/parse.sh

# -- the reserved lane -------------------------------------------------------

# Not q_run: this lane must never report PASS. It has checked nothing.
printf -- '-- lint: reserved formatting / static-analysis lane\n'
bash tools/quality/lint.sh | tee "$Q_DIR/lint.log" | sed 's/^/   /'
q_record RESERVED lint 0 'reserved formatting / static-analysis lane (nothing configured)'
printf '\n'

# -- hygiene -----------------------------------------------------------------

q_hygiene () {
	local group rc=0
	for group in "${Q_HYGIENE_GROUPS[@]}"; do
		printf '### %s\n' "$group"
		"$php_bin" tests/run.php --filter="$group" || rc=1
	done
	return "$rc"
}

q_run hygiene 'credential and example-configuration hygiene, by group name' \
	q_hygiene

# -- the quick groups, both runners ------------------------------------------

q_run suite-quick 'quick groups, dependency-free runner' \
	"$php_bin" tests/run.php --group=quick

if [ -f vendor/bin/phpunit ] && q_php_at_least 8.3; then
	q_run phpunit-quick 'quick groups, PHPUnit' \
		"$php_bin" vendor/bin/phpunit --group quick
elif [ ! -f vendor/bin/phpunit ]; then
	q_skip phpunit-quick 'quick groups, PHPUnit' \
		'there is no vendor/bin/phpunit - run "composer install" first' \
		'test discovery, group selection and the JUnit report at build/logs/junit.xml' \
		'the agreement between the two runners on which groups ran and how many assertions they made'
	q_enforce phpunit-quick phpunit
else
	q_skip phpunit-quick 'quick groups, PHPUnit' \
		"PHPUnit 12 requires PHP 8.3 and this is PHP $(q_php_version)" \
		'the JUnit report - no assertion, because suite-quick made every one of them on this runtime' \
		'this skip is expected on 8.1 and is the reason the dependency-free runner stays'
	q_enforce phpunit-quick phpunit
fi

# -- what this run did not cover ---------------------------------------------

q_note_skips phpunit-quick
q_note_skips suite-quick
q_note_skips hygiene

q_finish
