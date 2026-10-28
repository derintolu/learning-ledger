#!/usr/bin/env bash
#
# Build the release zip: bin/build-zip.sh <version> -> dist/learning-ledger-<version>.zip
#
# Installs the PHP and JS dependencies, prefixes the bundled PHP libraries into
# vendor-prefixed/ (Strauss), compiles the blocks into build/, then copies the
# plugin into dist/learning-ledger/ without the paths in .distignore and zips it.
# vendor/ is not shipped: Strauss copies every runtime library, with readable
# sources, into vendor-prefixed/, and vendor/ only adds the un-prefixed copies
# and the dev tools (Strauss itself runs from vendor/, so the install keeps them).
#
# <version> must equal the plugin header Version, LEARNING_LEDGER_VERSION and the
# readme's Stable tag.

set -euo pipefail

version="${1:-}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
	echo "Usage: bin/build-zip.sh <version>   (for example 0.1.0)" >&2
	exit 2
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

check_version() {
	if [[ "$2" != "$version" ]]; then
		echo "Version mismatch: $1 is '$2', expected '$version'." >&2
		exit 1
	fi
}
check_version "the plugin header Version" "$(sed -n 's/^ \* Version: *//p' learning-ledger.php)"
check_version "LEARNING_LEDGER_VERSION" "$(sed -n "s/^define( 'LEARNING_LEDGER_VERSION', '\([^']*\)' );\$/\1/p" learning-ledger.php)"
check_version "the readme.txt Stable tag" "$(sed -n 's/^Stable tag: *//p' readme.txt)"

composer install --no-progress --prefer-dist --no-interaction
composer prefix
npm ci --no-audit --no-fund
npm run build

for required in vendor-prefixed/autoload.php build/blocks build/fields/index.js; do
	if [[ ! -e "$required" ]]; then
		echo "Build output missing: $required" >&2
		exit 1
	fi
done

stage="$root/dist/learning-ledger"
zip_path="$root/dist/learning-ledger-$version.zip"
rm -rf "$stage" "$zip_path"
mkdir -p "$stage"
rsync -a --exclude-from="$root/.distignore" "$root/" "$stage/"

(cd "$root/dist" && zip -rqX "learning-ledger-$version.zip" learning-ledger)

echo "Built $zip_path"
