#!/usr/bin/env bash
#
# Regression for the database-coverage false pass: the longer tier's
# `database` check (tools/quality/integration.sh, driven through
# tools/quality/lib.sh's q_coverage()) used to key on a narrow pattern that
# matched only "no mariadbd/mysqld was found". A server that WAS found, but
# whose data-directory initialization then failed - the case a system
# MySQL/MariaDB option file naming a "user" produces, see the positive
# control below - still emits the database group's own universal skip
# banner (mcm_db_skip_notice() in tests/database.php), which the narrow
# pattern did not match. q_coverage() then fell through to its PASS branch,
# so --require database had nothing to rewrite: a run where every
# database-backed group was skipped still recorded PASS.
#
# This script proves three things against the checked-in fixtures below,
# which are lifted verbatim from a captured GitHub Actions integration run
# (the exact evidence in the linked issue) plus a synthetic genuinely-absent
# case:
#
#   1. negative control - the OLD pattern already caught a genuinely absent
#      server (fixture A) and still does; nothing about that regressed.
#   2. the bug, reproduced - the OLD pattern was blind to a found-but-failed
#      server (fixture B): it recorded PASS even though every database
#      group was skipped.
#   3. the fix - the NEW pattern, the one tools/quality/integration.sh
#      actually runs today (checked directly against that file, not just
#      asserted here), catches both fixtures and turns --require database
#      into a FAIL for both.
#
# It then runs a positive control with a real mariadb-install-db, if one is
# available: an ambient option file naming "user = mysql" makes
# initialization fail exactly as fixture B describes, and --no-defaults -
# the fix in tests/database.php - is what makes it succeed again. Without a
# MariaDB install script on hand this half prints a loud SKIP, the same
# contract every other optional prerequisite in this tier keeps.
#
# Exits non-zero if any of the above does not hold.

set -u

. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib.sh"

q_init db-coverage-regression
fail=0

section () { printf '\n=== %s ===\n' "$1"; }

check () {
	local desc="$1" got="$2" want="$3"
	if [ "$got" = "$want" ]; then
		printf 'ok    %s (%s)\n' "$desc" "$got"
	else
		printf 'FAIL  %s: got "%s", want "%s"\n' "$desc" "$got" "$want"
		fail=1
	fi
}

# -- fixtures -----------------------------------------------------------------

# A: a genuinely absent server. Goes through the same universal skip banner
# as B, with a different Reason: line - mcm_db_skip_notice() in
# tests/database.php builds both the same way. The pattern always caught
# this one because its reason line names "no mariadbd or mysqld was found".
fixture_absent="$Q_DIR/fixture-absent.log"
cat > "$fixture_absent" <<'EOF'

  ------------------------------------------------------------------------
  SKIPPED: the optional real-database group did not run.
  Reason: no mariadbd or mysqld was found on PATH, and MCM_TEST_MYSQLD is not set

  Not covered by this run:
    1. a call that is present in a method but never reached
    2. a value written to a column too narrow to hold it
    3. a WHERE clause that stops restricting the rows it reads or changes
    4. whose list a request names, and what an authorized import actually writes

PASS: 3329 assertions, 0 failures, 8 skipped in 40.00s
EOF

# B: a server was found, but mariadb-install-db's chown step failed - the
# database group's own universal skip banner, reproduced verbatim from a
# captured GitHub Actions integration run. This is the case the old pattern
# missed.
fixture_failed="$Q_DIR/fixture-init-failed.log"
cat > "$fixture_failed" <<'EOF'

  ------------------------------------------------------------------------
  SKIPPED: the optional real-database group did not run.
  Reason: could not create a data directory (status 1): chown: changing ownership of '/tmp/mcm-tests-4438-65fc5251/database/data': Operation not permitted

  Not covered by this run:
    1. a call that is present in a method but never reached
    2. a value written to a column too narrow to hold it
    3. a WHERE clause that stops restricting the rows it reads or changes
    4. whose list a request names, and what an authorized import actually writes

PASS: 3329 assertions, 0 failures, 8 skipped in 48.79s
EOF

old_pattern='no mariadbd or mysqld was found|MCM_TEST_MYSQLD is (not set|set to)'
new_pattern='SKIPPED: the optional real-database group did not run\.'

Q_REQUIRED=database

section 'negative control: a genuinely absent server, old and new pattern'
q_coverage db-absent-old 'old pattern, absent server' "$fixture_absent" "$old_pattern"
q_enforce db-absent-old database
check 'old pattern catches a genuinely absent server' "$(q_status_of db-absent-old)" FAIL

q_coverage db-absent-new 'new pattern, absent server' "$fixture_absent" "$new_pattern"
q_enforce db-absent-new database
check 'new pattern still catches a genuinely absent server' "$(q_status_of db-absent-new)" FAIL

section 'the bug: a server was found but initialization failed'
q_coverage db-failed-old 'old pattern, init failed' "$fixture_failed" "$old_pattern"
q_enforce db-failed-old database
check 'old pattern missed it - the reported false pass' "$(q_status_of db-failed-old)" PASS

section 'the fix: the new pattern catches it'
q_coverage db-failed-new 'new pattern, init failed' "$fixture_failed" "$new_pattern"
q_enforce db-failed-new database
check 'new pattern catches it - PASS becomes FAIL' "$(q_status_of db-failed-new)" FAIL

section 'the fix is the pattern integration.sh actually runs'
if grep -Fq "'$new_pattern' \\" "$Q_ROOT/tools/quality/integration.sh"; then
	printf 'ok    tools/quality/integration.sh uses the widened pattern\n'
else
	printf 'FAIL  tools/quality/integration.sh does not contain the expected pattern - this regression has drifted from the real check\n'
	fail=1
fi

# -- positive control: real initialization, with and without --no-defaults --

section 'positive control: real mariadb-install-db, ambient user=mysql option file'

install_script=""
if [ -n "${MCM_TEST_MYSQLD:-}" ] && [ -x "$MCM_TEST_MYSQLD" ]; then
	basedir=$(cd "$(dirname "$MCM_TEST_MYSQLD")/.." && pwd)
	for candidate in scripts/mariadb-install-db scripts/mysql_install_db; do
		if [ -x "$basedir/$candidate" ]; then
			install_script="$basedir/$candidate"
			break
		fi
	done
fi

if [ -z "$install_script" ]; then
	printf 'SKIP: no mariadb-install-db to hand - set MCM_TEST_MYSQLD to a mariadbd binary\n'
	printf 'SKIP: the positive control that --no-defaults is what fixes the initialization failure\n'
else
	pc_root="$Q_DIR/positive-control"
	mkdir -p "$pc_root/data-a" "$pc_root/data-b"
	printf '[mysqld]\nuser = mysql\n' > "$pc_root/.my.cnf"

	HOME="$pc_root" "$install_script" --basedir="$basedir" --datadir="$pc_root/data-a" \
		> "$pc_root/without-no-defaults.log" 2>&1
	without_status=$?
	check 'without --no-defaults, an ambient user=mysql option file breaks initialization' "$without_status" 1

	HOME="$pc_root" "$install_script" --no-defaults --basedir="$basedir" --datadir="$pc_root/data-b" \
		> "$pc_root/with-no-defaults.log" 2>&1
	with_status=$?
	check 'with --no-defaults (the fix), the same option file cannot reach it' "$with_status" 0

	if grep -Fq "'--no-defaults'" "$Q_ROOT/tests/database.php"; then
		printf 'ok    tests/database.php passes --no-defaults on the MariaDB initialization path\n'
	else
		printf 'FAIL  tests/database.php no longer passes --no-defaults - this regression has drifted from the real fix\n'
		fail=1
	fi
fi

printf '\n'
if [ "$fail" -eq 0 ]; then
	printf 'PASS: the database-coverage regression and its controls all hold\n'
else
	printf 'FAIL: the database-coverage regression did not hold - see above\n'
fi
exit "$fail"
