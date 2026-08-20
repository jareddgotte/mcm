#!/usr/bin/env bash
#
# Fetch the pinned database server the longer tier wants, and print its path.
#
#     export MCM_TEST_MYSQLD="$(tools/quality/fetch-mariadb.sh)"
#     tools/quality/integration.sh --require database
#
# The suite does not want a database service, and installing one is not what
# this does. It wants a server *binary* it can start a private, throw-away
# instance from - its own data directory, its own port, its own credentials,
# all destroyed when the run ends. README.md describes doing this by hand;
# this file is the same thing with the version and the checksum written down,
# so an automated run and a developer's run are the same run.
#
# Nothing else in tools/quality/ downloads anything. This is run deliberately,
# once, and then the unpacked tree is reused from the cache directory.
#
# The version is pinned here rather than tracked from upstream on purpose: a
# check that silently follows a moving server version is a check whose failures
# nobody can reproduce.

set -eu

MCM_MARIADB_VERSION='11.4.4'
MCM_MARIADB_SHA256='c9f26fd8c37a97458310fb577d6cc3bce44cf048c0e6ce014ee6ae157ebc1697'
MCM_MARIADB_URL="https://archive.mariadb.org/mariadb-${MCM_MARIADB_VERSION}/bintar-linux-systemd-x86_64/mariadb-${MCM_MARIADB_VERSION}-linux-systemd-x86_64.tar.gz"

# Everything this writes lands here and nothing lands in the checkout.
cache="${MCM_QUALITY_CACHE:-${TMPDIR:-/tmp}/mcm-quality-cache}"
root="$cache/mariadb-$MCM_MARIADB_VERSION"
server="$root/bin/mariadbd"

say () { printf '%s\n' "$*" >&2; }

if [ "$(uname -s)" != 'Linux' ] || [ "$(uname -m)" != 'x86_64' ]; then
	say "the pinned binary tarball is linux-x86_64 only; this is $(uname -s)/$(uname -m)."
	say 'Unpack a MariaDB or MySQL tarball for this platform yourself and set'
	say 'MCM_TEST_MYSQLD to the server binary inside it - see README.md.'
	exit 2
fi

if [ -x "$server" ]; then
	printf '%s\n' "$server"
	exit 0
fi

mkdir -p "$cache"
archive="$cache/mariadb-$MCM_MARIADB_VERSION.tar.gz"

if [ ! -f "$archive" ]; then
	say "downloading MariaDB $MCM_MARIADB_VERSION (about 350 MB, once)"
	curl --fail --silent --show-error --location --output "$archive.part" "$MCM_MARIADB_URL"
	mv "$archive.part" "$archive"
fi

# Verified before it is unpacked, so a truncated or substituted archive never
# becomes a server this suite starts.
actual="$(sha256sum "$archive" | cut -d' ' -f1)"
if [ "$actual" != "$MCM_MARIADB_SHA256" ]; then
	say "checksum mismatch for $archive"
	say "  expected $MCM_MARIADB_SHA256"
	say "  got      $actual"
	rm -f "$archive"
	exit 1
fi

say "unpacking into $root"
rm -rf "$root.part"
mkdir -p "$root.part"
tar xzf "$archive" -C "$root.part" --strip-components=1
mv "$root.part" "$root"

printf '%s\n' "$server"
