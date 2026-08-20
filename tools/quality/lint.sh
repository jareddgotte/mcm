#!/usr/bin/env bash
#
# The reserved lane: formatting verification and static analysis.
#
# It is deliberately empty, and this file is what makes that visible. The
# decision this lane is waiting on - which formatter, which indentation width,
# which static analyser and which rule set - is genuinely unsettled and is held
# separately from the work that built the tiers. The tracked .editorconfig is
# one input to that decision rather than the answer to it.
#
# So the lane exists, the fast tier runs it, and it reports RESERVED rather
# than PASS. That distinction is the whole point: a lane that reported a pass
# would be indistinguishable from one that had checked something, and the first
# person to read the summary would believe this repository verifies its
# formatting. It does not.
#
# Filling it in is one edit to this file and one line in the fast tier's
# documentation. Until then it presupposes no choice: it runs no tool, reads no
# configuration and has no opinion about a single character of the source.
#
#     tools/quality/lint.sh          say what this lane is and is not
#
# Always exits zero. It has nothing to fail on.

set -u

cat <<'TEXT'
RESERVED: no formatting or static-analysis check is configured.

  This lane is created and left empty on purpose. Which formatter, which
  indentation standard, which static analyser and which rule set fill it is a
  separate decision with its own review, and picking one here would settle it
  by accident.

  Uncovered until it is filled:
    1. formatting - nothing verifies that a file matches any style, and the
       tracked .editorconfig is an input to the pending decision rather than
       an enforced standard
    2. static analysis - nothing looks for an undefined symbol, an unreachable
       branch or a type that cannot hold what is assigned to it; only the
       parse sweep and the suite's own token-level checks read the sources

  Everything else the fast tier runs is a real check and reports PASS or FAIL.
TEXT
