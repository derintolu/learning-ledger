#!/usr/bin/env bash
# Redaction helper sourced by the conformance scripts.
#
# lledger_redact <file> <key> <secret> [<secret>...]
# Rewrites <file> in place, replacing each secret, base64("<key>:<secret>") (the Basic Auth
# credential) and the value of any Authorization header with [REDACTED]. Needles travel in the
# environment, never in argv. Fails if any needle is still present afterwards.

lledger_redact() {
	local file="$1" key="$2" needles="" secret b64
	shift 2
	for secret in "$@"; do
		[ -n "$secret" ] || continue
		b64="$(printf '%s:%s' "$key" "$secret" | base64 | tr -d '\n')"
		needles+="${secret}"$'\n'"${b64}"$'\n'
	done
	LLEDGER_REDACT_NEEDLES="$needles" perl -i -pe '
		BEGIN { @n = grep { length } split /\n/, $ENV{LLEDGER_REDACT_NEEDLES}; }
		for my $x (@n) { s/\Q$x\E/[REDACTED]/g; }
		s/(Authorization["\x27]?\s*[:=]\s*["\x27]?(?:Basic|Bearer)\s+)[^\s"\x27,}]+/$1\[REDACTED]/gi;
	' "$file"
	local n
	while IFS= read -r n; do
		[ -n "$n" ] || continue
		if grep -qF -- "$n" "$file"; then
			echo "Redaction failed for $file" >&2
			return 1
		fi
	done <<< "$needles"
}
