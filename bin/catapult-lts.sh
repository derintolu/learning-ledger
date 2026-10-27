#!/usr/bin/env bash
# Run the CATAPULT cmi5 LMS Test Suite (LTS) against a Learning Ledger site.
set -euo pipefail

usage() {
	cat <<'USAGE'
Usage: bin/catapult-lts.sh --site <url> --content-host <url> --key <key> --secret-file <path> [--test-secret-file <path>]

  --site              Site base URL, e.g. http://localhost:8888
  --content-host      Host that serves the LTS test content, e.g. http://127.0.0.1:8888
  --key               LRS client id used by the adapter to read statements.
  --secret-file       File holding that client's secret (never passed as an argument).
  --test-secret-file  File holding LLEDGER_CATAPULT_TEST_SECRET, the shared secret for the
                      test-only routes (set in .wp-env.override.json).

Clones CATAPULT at CATAPULT_SHA (bin/versions.env) into .cache/catapult, builds the LTS package
library, installs tests/catapult/adapter.js as the LMS adapter (lib/lms.custom.js) and runs the
suite unchanged. Writes conformance/raw/catapult-lts-<YYYYMMDD>.json (Jest JSON report).
Secrets reach the adapter through environment variables only.
USAGE
}

site="" content_host="" key="" secret_file="" test_secret_file=""
while [ $# -gt 0 ]; do
	case "$1" in
		--site) site="${2:-}"; shift 2 ;;
		--content-host) content_host="${2:-}"; shift 2 ;;
		--key) key="${2:-}"; shift 2 ;;
		--secret-file) secret_file="${2:-}"; shift 2 ;;
		--test-secret-file) test_secret_file="${2:-}"; shift 2 ;;
		-h|--help) usage; exit 0 ;;
		*) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
	esac
done

if [ -z "$site" ] || [ -z "$content_host" ] || [ -z "$key" ] || [ -z "$secret_file" ]; then
	usage >&2
	exit 2
fi
[ -r "$secret_file" ] || { echo "Cannot read secret file: $secret_file" >&2; exit 2; }
if [ -n "$test_secret_file" ] && [ ! -r "$test_secret_file" ]; then
	echo "Cannot read test secret file: $test_secret_file" >&2
	exit 2
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=versions.env
. "$root/bin/versions.env"
catapult_dir="$root/.cache/catapult"
lts_dir="$catapult_dir/lts"
out_dir="$root/conformance/raw"
out_file="$out_dir/catapult-lts-$(date +%Y%m%d).json"

if [ ! -d "$catapult_dir/.git" ]; then
	mkdir -p "$root/.cache"
	git clone --quiet https://github.com/adlnet/CATAPULT "$catapult_dir"
fi
git -C "$catapult_dir" fetch --quiet origin "$CATAPULT_SHA" 2>/dev/null || true
git -C "$catapult_dir" checkout --quiet "$CATAPULT_SHA"

(cd "$lts_dir" && npm ci --no-audit --no-fund --silent)
(cd "$lts_dir/pkg" && npx webpack)

# lib/lms.custom.js is git-ignored upstream. Copy (not symlink) so the adapter's relative
# requires resolve inside the LTS tree.
cp "$root/tests/catapult/adapter.js" "$lts_dir/lib/lms.custom.js"

mkdir -p "$out_dir"
lrs_secret="$(tr -d '\r\n' < "$secret_file")"
test_secret=""
if [ -n "$test_secret_file" ]; then
	test_secret="$(tr -d '\r\n' < "$test_secret_file")"
fi

status=0
(
	cd "$lts_dir"
	CATAPULT_LMS="./lib/lms.custom.js" \
	LLEDGER_SITE="$site" \
	LLEDGER_CONTENT_HOST="$content_host" \
	LLEDGER_LRS_KEY="$key" \
	LLEDGER_LRS_SECRET="$lrs_secret" \
	LLEDGER_CATAPULT_TEST_SECRET="$test_secret" \
	npx jest --json --outputFile="$out_file"
) || status=$?

if [ ! -s "$out_file" ]; then
	echo "The LTS produced no report." >&2
	exit 1
fi
for s in "$lrs_secret" "$test_secret"; do
	if [ -n "$s" ] && grep -qF -- "$s" "$out_file"; then
		rm -f "$out_file"
		echo "Refusing to save a report that contains a secret." >&2
		exit 1
	fi
done
echo "Wrote ${out_file#"$root"/} (suite exit status: $status)"
exit "$status"
