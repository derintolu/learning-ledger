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
suite unchanged. Writes conformance/raw/catapult-lts-<YYYYMMDD>.json (Jest JSON report) and
exits non-zero only when a failing test is not listed in conformance/known-gaps.json.
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
# shellcheck source=redact.sh
. "$root/bin/redact.sh"
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

console_log="$root/.cache/catapult-last-run.txt"
# --runInBand: one test file at a time, so the slow 1000-AU import in package.js does not
# share the site with runtime.js's timed AU runs.
status=0
(
	cd "$lts_dir"
	CATAPULT_LMS="./lib/lms.custom.js" \
	LLEDGER_SITE="$site" \
	LLEDGER_CONTENT_HOST="$content_host" \
	LLEDGER_LRS_KEY="$key" \
	LLEDGER_LRS_SECRET="$lrs_secret" \
	LLEDGER_CATAPULT_TEST_SECRET="$test_secret" \
	npx jest --runInBand --json --outputFile="$out_file"
) > "$console_log" 2>&1 || status=$?

lledger_redact "$console_log" "$key" "$lrs_secret" "$test_secret"
cat "$console_log"
if [ ! -s "$out_file" ]; then
	echo "The LTS produced no report." >&2
	exit 1
fi
lledger_redact "$out_file" "$key" "$lrs_secret" "$test_secret"
echo "Wrote ${out_file#"$root"/} (suite exit status: $status)"
# The gate: fail only on a failing test that conformance/known-gaps.json does not list.
gate=0
php "$root/bin/summarise-conformance.php" --check "$out_file" --suite-status "$status" || gate=$?
exit "$gate"
