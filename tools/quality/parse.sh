#!/usr/bin/env bash
#
# The parse sweep: every PHP file this repository tracks compiles.
#
# This is the cheapest check there is and the one with the worst failure mode
# behind it. Deployment is manual and additive (see "Sharp edges" in
# AGENTS.md), so a file that does not parse is not a red test - it is a white
# page for whoever loads it. Nothing else in the suite reads every file: the
# static checks in tests/entrypoints.php deliberately skip vendor/, build/ and
# the suite itself, and a case only ever drives the pages it is about.
#
# The list comes from git rather than from a walk of the directory, so it is
# exactly what a deployment would carry: nothing generated, nothing installed,
# and no copy somebody left lying about. Outside a git checkout it falls back
# to a walk that skips the same generated trees.
#
#     tools/quality/parse.sh              sweep with php from PATH
#     MCM_QUALITY_PHP=/path/to/php tools/quality/parse.sh
#
# Exits non-zero naming every file that did not compile.

set -u

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

php_bin="${MCM_QUALITY_PHP:-php}"

if ! command -v "$php_bin" >/dev/null 2>&1 && [ ! -x "$php_bin" ]; then
	printf 'no PHP CLI: %s is not runnable. Set MCM_QUALITY_PHP.\n' "$php_bin" >&2
	exit 2
fi

list_sources () {
	if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
		git ls-files -z -- '*.php' '*.inc'
		return
	fi
	find . \
		\( -path ./vendor -o -path ./build -o -path ./node_modules \) -prune -o \
		\( -name '*.php' -o -name '*.inc' \) -print0
}

# One PHP process per file is what makes this the slowest cheap check there
# is, so the files are swept in parallel. xargs answers 123 when any of them
# exited non-zero, which is the only thing this needs to know beyond the names
# it printed on the way past.
jobs="${MCM_QUALITY_JOBS:-$(getconf _NPROCESSORS_ONLN 2>/dev/null || printf 4)}"

list="$(mktemp)"
trap 'rm -f "$list"' EXIT

list_sources > "$list"
checked=$(tr -cd '\0' < "$list" | wc -c | tr -d ' ')

if [ "$checked" -eq 0 ]; then
	# A sweep that found nothing to sweep is a broken sweep, not a pass: it is
	# what a wrong working directory looks like from the outside.
	printf 'no PHP sources were found to parse\n' >&2
	exit 2
fi

set +e
xargs -0 -P "$jobs" -I '{}' \
	sh -c '"$0" -l "$1" >/dev/null 2>&1 && exit 0
		printf "PARSE FAILED: %s\n" "$1"
		"$0" -l "$1" 2>&1 | sed "s/^/  /"
		exit 1' \
	"$php_bin" '{}' < "$list"
rc=$?
set -e

printf 'parse sweep: %d files on %s, %d job(s), %s\n' \
	"$checked" \
	"$("$php_bin" -r 'echo "PHP " . PHP_VERSION;')" \
	"$jobs" \
	"$([ "$rc" -eq 0 ] && printf 'all compiled' || printf 'see the PARSE FAILED lines above')"

[ "$rc" -eq 0 ]
