#!/usr/bin/env bash
#
# Regenerate inc/autoload/, the class autoloader the site loads.
#
#     tools/dump-autoload.sh
#     MCM_COMPOSER='php /path/to/composer.phar' tools/dump-autoload.sh
#
# inc/autoload/ is generated, committed and served. Committed because the site
# has to work from a checkout with nothing installed: the one thing this
# repository will not do is make a page depend on somebody having run a package
# manager first. Generated because the alternative is a hand-written list of
# classes that drifts from the files it names.
#
# What it maps is this project's own files and nothing else - inc/classes/ and
# inc/libs/, named by the "autoload" section of composer.json. "require" in
# that file is empty and stays empty: no package of anybody else's is
# installed, downloaded or served by any of this.
#
# Two flags are not decoration:
#
#   --no-dev                  the development tooling (PHPUnit) has no business
#                             in a map the site loads.
#   --classmap-authoritative  the map is the whole answer. A class that is not
#                             in it is never hunted for on disk, so a request
#                             cannot be made to stat its way through the
#                             filesystem, and the loader touches no file it was
#                             not generated to know about.
#
# COMPOSER_VENDOR_DIR is what puts the result under inc/ instead of in
# /vendor, which is git-ignored and holds the development tooling. The two are
# separate on purpose: /vendor is disposable and nothing a request reads,
# inc/autoload/ is committed and is what the bootstrap registers.
#
# The output is deterministic - the generated class names are pinned by
# "autoloader-suffix" in composer.json - so re-running this on an unchanged
# tree changes nothing, and any diff it does produce is a real one.
#
# Run this after adding, removing or renaming a class under inc/classes/ or
# inc/libs/, and commit what changes. The suite fails if you forget: the
# "generated class autoloader" group reads the committed map and compares it
# against the classes those directories actually declare.

set -eu

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

composer="${MCM_COMPOSER:-composer}"

# shellcheck disable=SC2086 # MCM_COMPOSER may legitimately carry arguments.
if ! command -v ${composer%% *} >/dev/null 2>&1 && [ ! -x "${composer%% *}" ]; then
	printf 'no Composer: %s is not runnable. Set MCM_COMPOSER.\n' "$composer" >&2
	exit 2
fi

# shellcheck disable=SC2086
COMPOSER_VENDOR_DIR=inc/autoload $composer dump-autoload \
	--no-dev \
	--classmap-authoritative \
	--no-interaction

printf 'inc/autoload/ regenerated. Commit any change it produced.\n'
