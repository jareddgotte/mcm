# Shared machinery for the two check tiers. Sourced, never executed.
#
# What this file exists to do is keep one answer to four questions that both
# tiers ask, so the tiers themselves read as a list of checks rather than as a
# reimplementation of a test runner:
#
#   where does a run write itself down    q_init, q_finish
#   what is one check, and did it hold    q_run, q_record
#   which PHP is this                     q_php
#   what did a check not cover            q_note_skips
#
# A check is a command. It passes when it exits zero and fails when it does
# not; nothing here interprets its output to decide that. What the summary adds
# on top is the one thing an exit code cannot say - that a check ran but
# covered less than it could have, because a browser, a database server or a
# second PHP runtime was not there. Those lines are lifted verbatim out of the
# check's own output rather than restated here, so the wording a reviewer reads
# is the suite's own and there is no second copy of it to drift.

# An unset variable is a bug here and stops the run. `set -e` deliberately does
# not join it: a check that fails must not end the tier, because a tier that
# stopped at its first failure would report every later check as though it had
# never been asked.
set -u

# The repository root, from this file's location, so a tier script can be run
# from anywhere.
Q_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

Q_TIER=""
Q_DIR=""
Q_RESULTS=""
Q_STARTED=0

# The database group's own universal skip banner (mcm_db_skip_notice() in
# tests/database.php), lifted here once so the database-coverage check in
# tools/quality/integration.sh and its regression in
# tools/quality/regressions/database-coverage.sh read the same pattern rather
# than each naming their own copy of it.
Q_DB_SKIP_PATTERN='SKIPPED: the optional real-database group did not run\.'

# Prerequisites the caller insisted on, as a comma-delimited list. Empty means
# "loud skips are acceptable", which is the default and what a developer wants;
# an automated run names them so that a missing browser or database server is a
# failure there rather than a notice nobody is watching. MCM_QUALITY_REQUIRE is
# the same list from the environment, which is what a workflow matrix can vary
# per entry without a conditional in the command line.
Q_REQUIRED="${MCM_QUALITY_REQUIRE:-}"

# ---------------------------------------------------------------------------
# Where a run writes itself down
# ---------------------------------------------------------------------------

# Start a tier. $1 is its name; the recorded result lands in build/quality/$1,
# which is generated and git-ignored like the rest of build/.
q_init () {
	Q_TIER="$1"
	Q_DIR="$Q_ROOT/build/quality/$Q_TIER"
	Q_RESULTS="$Q_DIR/results.tsv"
	Q_STARTED=$(date +%s)

	rm -rf "$Q_DIR"
	mkdir -p "$Q_DIR"
	: > "$Q_RESULTS"

	printf '== mcm %s checks ==\n' "$Q_TIER"
	printf 'recorded in %s\n\n' "${Q_DIR#"$Q_ROOT"/}"
}

# Record one result. $1 status, $2 identifier, $3 seconds, $4 one-line title.
q_record () {
	printf '%s\t%s\t%s\t%s\n' "$1" "$2" "$3" "$4" >> "$Q_RESULTS"
}

# Run one check. $1 identifier, $2 one-line title, then the command.
#
# The command's output is both streamed and kept, because the two readers are
# different people: whoever is watching the run wants it now, and whoever reads
# the recorded result afterwards wants the file. A failure prints nothing extra
# - it has already been streamed - and the tier carries on, so one broken check
# still leaves every other one reported.
q_run () {
	local id="$1" title="$2"
	shift 2

	local log="$Q_DIR/$id.log"
	local started ended rc

	printf -- '-- %s: %s\n' "$id" "$title"
	started=$(date +%s)

	set +e
	(
		set -o pipefail
		"$@" 2>&1 | tee "$log"
	)
	rc=$?
	set -e

	ended=$(date +%s)

	if [ "$rc" -eq 0 ]; then
		q_record PASS "$id" "$((ended - started))" "$title"
		printf '   PASS (%ss)\n\n' "$((ended - started))"
	else
		q_record FAIL "$id" "$((ended - started))" "$title"
		printf '   FAIL (exit %s, %ss)\n\n' "$rc" "$((ended - started))"
	fi

	return 0
}

# Record a check that did not run at all, and say what is therefore uncovered.
# $1 identifier, $2 title, $3 why, and every further argument one line of
# coverage this run does not have.
q_skip () {
	local id="$1" title="$2" why="$3"
	shift 3

	printf -- '-- %s: %s\n' "$id" "$title"
	printf '   SKIP: %s\n' "$why"

	printf '%s\n' "$title - $why" > "$Q_DIR/$id.skips"

	local line
	for line in "$@"; do
		printf '   SKIP: %s\n' "$line"
		printf '  %s\n' "$line" >> "$Q_DIR/$id.skips"
	done
	printf '\n'

	# The log a reader is pointed at says the same thing, so that every check
	# has one whether it ran or not.
	cp "$Q_DIR/$id.skips" "$Q_DIR/$id.log"

	q_record SKIP "$id" 0 "$title"
	return 0
}

# The title a check was recorded under, by identifier.
q_title_of () {
	awk -F'\t' -v id="$1" '$2 == id { print $4; exit }' "$Q_RESULTS"
}

# The verdict a check was recorded with, by identifier.
q_status_of () {
	awk -F'\t' -v id="$1" '$2 == id { print $1; exit }' "$Q_RESULTS"
}

# Lift the skip lines a check printed into the run's own summary.
#
# A check's exit code cannot say "I ran, and covered less than I could have".
# Both things that can say it already do, in their own words: the suite prints
# "  skip  <what> - <why>" for a group it could not run, and the browser runner
# prints "SKIP: <why>". Copying those lines is what keeps a partial run from
# reading as a whole one, without this file owning a second copy of the wording.
q_note_skips () {
	local id="$1"
	local log="$Q_DIR/$id.log"
	local lifted

	[ -f "$log" ] || return 0
	[ -f "$Q_DIR/$id.skips" ] && return 0

	lifted=$(grep -E '^(SKIP:|[[:space:]]+skip[[:space:]])' "$log" \
		| sed -E -e 's/^SKIP:[[:space:]]*//' -e 's/^[[:space:]]+skip[[:space:]]+//' \
		| sed -e 's/^/  /') || true

	if [ -n "$lifted" ]; then
		{
			printf '%s\n' "$(q_title_of "$id")"
			printf '%s\n' "$lifted"
		} > "$Q_DIR/$id.skips"
	fi

	return 0
}

# A check that exited zero having quietly covered less than it claims.
#
# The browser runner is the case this exists for: a machine with no Chromium
# makes it print SKIP lines and exit zero, on purpose, so that a developer
# without a browser is not blocked. Zero is the right exit code and PASS is the
# wrong verdict, so the record follows the tool's own SKIP lines rather than
# its exit code.
q_downgrade_on_skip () {
	local id="$1"

	[ -s "$Q_DIR/$id.skips" ] || return 0

	awk -F'\t' -v id="$id" 'BEGIN { OFS = "\t" } $2 == id && $1 == "PASS" { $1 = "SKIP" } { print }' \
		"$Q_RESULTS" > "$Q_RESULTS.tmp"
	mv "$Q_RESULTS.tmp" "$Q_RESULTS"
	return 0
}

# Whether a run covered something optional, decided from what the run said.
#
# $1 identifier, $2 title, $3 a log to read, $4 an extended regular expression
# that matches only when the coverage was NOT there, then one line per piece of
# coverage that is therefore missing. Asking the log rather than asking the
# machine is deliberate: "is there a mysqld on PATH" answers a question about
# this computer, and the question worth recording is whether the groups that
# need one actually ran.
q_coverage () {
	local id="$1" title="$2" log="$3" pattern="$4"
	shift 4

	if [ -f "$log" ] && grep -Eq "$pattern" "$log"; then
		q_skip "$id" "$title" 'the run reported it was not there' "$@"
		return 0
	fi

	printf -- '-- %s: %s\n' "$id" "$title"
	printf '   PASS\n\n'
	q_record PASS "$id" 0 "$title"
	return 0
}

# ---------------------------------------------------------------------------
# Prerequisites
# ---------------------------------------------------------------------------

# Whether the caller said this prerequisite has to be there. Used by the tier
# to turn a loud skip into a failure on a run that is supposed to have
# everything, which is the only way an automated run cannot go quietly green
# with half its coverage missing.
q_required () {
	case ",$Q_REQUIRED," in
		*",$1,"*) return 0 ;;
		*)        return 1 ;;
	esac
}

# Turn a skip into a failure because the caller required that prerequisite.
#
# $1 is the check's identifier and $2 the prerequisite's name. This rewrites
# the record rather than adding a second one, so a check still has exactly one
# verdict, and it is a no-op when the caller did not name the prerequisite -
# which is the ordinary case, where a loud skip is the right answer.
q_enforce () {
	local id="$1" name="$2"

	q_required "$name" || return 0

	printf '   REQUIRED: --require named "%s", so that skip is a failure here\n\n' "$name"
	awk -F'\t' -v id="$id" 'BEGIN { OFS = "\t" } $2 == id && $1 == "SKIP" { $1 = "FAIL" } { print }' \
		"$Q_RESULTS" > "$Q_RESULTS.tmp"
	mv "$Q_RESULTS.tmp" "$Q_RESULTS"
	return 0
}

# ---------------------------------------------------------------------------
# The PHP under test
# ---------------------------------------------------------------------------

# The PHP CLI to run everything with: MCM_QUALITY_PHP if set, otherwise php on
# PATH. Anything else is the caller's to arrange - these checks install
# nothing.
q_php () {
	if [ -n "${MCM_QUALITY_PHP:-}" ]; then
		printf '%s' "$MCM_QUALITY_PHP"
		return 0
	fi
	printf 'php'
}

# The version of that binary as three numbers, e.g. 8.3.29.
q_php_version () {
	"$(q_php)" -r 'echo PHP_VERSION;' 2>/dev/null || printf 'unknown'
}

# Whether that binary is at least $1.$2. PHPUnit 12 needs 8.3, which is the
# only thing this is asked about.
q_php_at_least () {
	"$(q_php)" -r 'exit(version_compare(PHP_VERSION, $argv[1], ">=") ? 0 : 1);' "$1" 2>/dev/null
}

# ---------------------------------------------------------------------------
# The recorded result
# ---------------------------------------------------------------------------

# Assemble build/quality/<tier>/summary.txt, print it, and exit accordingly.
#
# The summary is the thing a reviewer is pointed at, so it holds everything
# needed to say what this run was: which tier, which PHP, which pinned tools,
# every check and its verdict, and every line of coverage the run did not have.
q_finish () {
	local summary="$Q_DIR/summary.txt"
	local failed=0 skipped=0 passed=0 reserved=0
	local status id seconds title

	{
		printf 'mcm %s checks\n' "$Q_TIER"
		printf '%s\n\n' '------------------------------------------------------------------'
		printf 'when          %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
		# The version, never the path: this file is meant to be quotable in a
		# review, and where somebody keeps a PHP binary is nobody else's
		# business.
		printf 'php           %s\n' "$(q_php_version)"
		printf 'commit        %s\n' "$(git -C "$Q_ROOT" rev-parse HEAD 2>/dev/null || printf 'not a git checkout')"
		printf 'required      %s\n' "${Q_REQUIRED:-nothing; loud skips are accepted}"
		printf '\nchecks\n'
	} > "$summary"

	while IFS=$'\t' read -r status id seconds title; do
		[ -n "$status" ] || continue
		printf '  %-8s %-16s %4ss  %s\n' "$status" "$id" "$seconds" "$title" >> "$summary"
		case "$status" in
			PASS)     passed=$((passed + 1)) ;;
			FAIL)     failed=$((failed + 1)) ;;
			SKIP)     skipped=$((skipped + 1)) ;;
			RESERVED) reserved=$((reserved + 1)) ;;
		esac
	done < "$Q_RESULTS"

	# What ran but covered less than it could have. This is the half of the
	# result an exit code cannot carry, so it is spelled out rather than left
	# for whoever thinks to scroll.
	# What ran but covered less than it could have, in the order the checks
	# were recorded rather than in whatever order the shell globs them.
	if ls "$Q_DIR"/*.skips >/dev/null 2>&1; then
		printf '\nnot covered by this run\n' >> "$summary"
		while IFS=$'\t' read -r status id seconds title; do
			[ -f "$Q_DIR/$id.skips" ] || continue
			printf '\n' >> "$summary"
			sed 's/^/  /' "$Q_DIR/$id.skips" >> "$summary"
		done < "$Q_RESULTS"
	fi

	{
		printf '\nlogs          %s/<check>.log\n' "${Q_DIR#"$Q_ROOT"/}"
		printf '\n%s: %d passed, %d failed, %d skipped, %d reserved in %ss\n' \
			"$([ "$failed" -eq 0 ] && printf 'PASS' || printf 'FAIL')" \
			"$passed" "$failed" "$skipped" "$reserved" "$(( $(date +%s) - Q_STARTED ))"
	} >> "$summary"

	printf '\n'
	cat "$summary"

	[ "$failed" -eq 0 ]
}
