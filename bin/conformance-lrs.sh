#!/usr/bin/env bash
# Run the ADL LRS conformance suite against an xAPI endpoint and save the raw run record.
set -euo pipefail

usage() {
	cat <<'USAGE'
Usage: bin/conformance-lrs.sh --xapi <1.0.3|2.0.x> --endpoint <url> --key <key> --secret-file <path> [--grep <pattern>]

  --xapi         xAPI version to test: 1.0.3, or 2.0.x (the suite's 2.0.0 battery).
  --endpoint     LRS endpoint, e.g. http://localhost:8888/wp-json/learning-ledger/xapi/
  --key          Basic Auth username (client id).
  --secret-file  File holding the Basic Auth password. It is read here and handed to the
                 suite through the environment, so it never appears in a process list or log.
  --grep         Only run suite tests matching this pattern.

Writes conformance/raw/lrs-<xapi>-<YYYYMMDD>.json. Exits non-zero when the suite reports failures.
The suite is cloned at LRS_SUITE_SHA (bin/versions.env) into .cache/lrs-conformance.
Only run this against an LRS you operate; the suite writes and voids data.
USAGE
}

xapi="" endpoint="" key="" secret_file="" grep_pattern=""
while [ $# -gt 0 ]; do
	case "$1" in
		--xapi) xapi="${2:-}"; shift 2 ;;
		--endpoint) endpoint="${2:-}"; shift 2 ;;
		--key) key="${2:-}"; shift 2 ;;
		--secret-file) secret_file="${2:-}"; shift 2 ;;
		--grep) grep_pattern="${2:-}"; shift 2 ;;
		-h|--help) usage; exit 0 ;;
		*) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
	esac
done

if [ -z "$xapi" ] || [ -z "$endpoint" ] || [ -z "$key" ] || [ -z "$secret_file" ]; then
	usage >&2
	exit 2
fi
case "$xapi" in
	1.0.3) suite_version="1.0.3" ;;
	2.0.x|2.0.0) suite_version="2.0.0" ;;
	*) echo "Unsupported --xapi value: $xapi (use 1.0.3 or 2.0.x)" >&2; exit 2 ;;
esac
[ -r "$secret_file" ] || { echo "Cannot read secret file: $secret_file" >&2; exit 2; }

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=versions.env
. "$root/bin/versions.env"
# shellcheck source=redact.sh
. "$root/bin/redact.sh"
suite_dir="$root/.cache/lrs-conformance"
out_dir="$root/conformance/raw"
out_file="$out_dir/lrs-${xapi}-$(date +%Y%m%d).json"

if [ ! -d "$suite_dir/.git" ]; then
	mkdir -p "$root/.cache"
	git clone --quiet https://github.com/adlnet/lrs-conformance-test-suite "$suite_dir"
fi
git -C "$suite_dir" fetch --quiet origin "$LRS_SUITE_SHA" 2>/dev/null || true
git -C "$suite_dir" checkout --quiet "$LRS_SUITE_SHA"
(cd "$suite_dir" && npm install --no-audit --no-fund --silent)

# The suite only accepts the password as a -p argument. A preload adds it to argv inside the
# node process from the environment, so the secret is never on the shell command line.
preload="$root/.cache/lrs-password-preload.js"
cat > "$preload" <<'JS'
if (process.env.LLEDGER_SUITE_PASSWORD) {
	process.argv.push('-p', process.env.LLEDGER_SUITE_PASSWORD);
	delete process.env.LLEDGER_SUITE_PASSWORD;
}
JS

mkdir -p "$suite_dir/logs" "$out_dir"
marker="$(mktemp)"
trap 'rm -f "$marker"' EXIT

args=(-e "$endpoint" -a -u "$key" -x "$suite_version")
if [ -n "$grep_pattern" ]; then
	args+=(-g "$grep_pattern")
fi

status=0
secret="$(tr -d '\r\n' < "$secret_file")"
(cd "$suite_dir" && LLEDGER_SUITE_PASSWORD="$secret" node -r "$preload" bin/console_runner.js "${args[@]}" > "$root/.cache/lrs-last-run.txt" 2>&1) || status=$?

# Only a log written by this run counts (the marker predates the run); never fall back to an old one.
log="$(find "$suite_dir/logs" -name '*.log' -newer "$marker" -type f 2>/dev/null | head -n 1)"
if [ -z "$log" ]; then
	lledger_redact "$root/.cache/lrs-last-run.txt" "$key" "$secret" || true
	cat "$root/.cache/lrs-last-run.txt" >&2
	echo "The suite produced no new run log." >&2
	exit 1
fi
cp "$log" "$out_file"
lledger_redact "$out_file" "$key" "$secret"
lledger_redact "$root/.cache/lrs-last-run.txt" "$key" "$secret"
tail -n 40 "$root/.cache/lrs-last-run.txt"
echo "Wrote ${out_file#"$root"/} (suite exit status: $status)"
exit "$status"
