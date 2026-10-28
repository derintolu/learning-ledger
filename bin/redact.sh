#!/usr/bin/env bash
# Redaction helper sourced by the conformance scripts.
#
# lledger_redact <file> <key> <secret> [<secret>...]
# Rewrites <file> in place, replacing each secret, base64("<key>:<secret>") (the Basic Auth
# credential) and the value of any Authorization header or form field with [REDACTED].
# Each needle also matches its URL-encoded forms (any character as %XX in either hex case,
# a space as "+", "/" as "\/" and any character as \u00XX), so a credential sent in a
# form body (Authorization=Basic%20<base64>, with %3D padding) is caught. The value of an
# Authorization=Basic|Bearer field ends at whitespace, a quote, "&", "," or "}". Needles
# travel in the environment, never in argv. Fails if any needle form, or an unredacted
# Authorization Basic/Bearer value, is still present afterwards. The repository root
# (the parent of this script's directory) is stripped from absolute paths, plain or
# JSON-escaped, so saved run records hold repo-relative paths such as ".cache/...".

# Perl shared by both passes. Builds $needle_re (every needle, every encoding) and $auth_re.
_lledger_redact_perl_common='
	BEGIN {
		my @n = grep { length } split /\n/, $ENV{LLEDGER_REDACT_NEEDLES};
		my @alts;
		for my $x (@n) {
			my $re = "";
			for my $c (split //, $x) {
				if ($c =~ /^[A-Za-z0-9._~-]$/) { $re .= quotemeta($c); next; }
				my $h = sprintf("%02X", ord($c));
				my @a = (quotemeta($c), "%" . $h, "\\\\u00" . $h);
				push @a, "\\+" if $c eq " ";
				push @a, "\\\\\\/" if $c eq "/";
				$re .= "(?:" . join("|", @a) . ")";
			}
			push @alts, $re;
		}
		@alts = sort { length($b) <=> length($a) } @alts;
		$needle_re = @alts ? "(?i:" . join("|", @alts) . ")" : "(?!)";
		$needle_re = qr/$needle_re/;
		$sep = qr/(?:\s+|%20|\+)/i;
		$eq  = qr/(?:[:=]|%3A|%3D)/i;
		$auth_re = qr/(Authorization(?:["\x27]|%22|%27)?\s*$eq\s*(?:["\x27]|%22|%27)?(?:Basic|Bearer)$sep)/i;
		$val = qr/[^\s"\x27,}&]/;
	}
'

_lledger_repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

lledger_redact() {
	local file="$1" key="$2" needles="" secret b64
	shift 2
	for secret in "$@"; do
		[ -n "$secret" ] || continue
		b64="$(printf '%s:%s' "$key" "$secret" | base64 | tr -d '\n')"
		needles+="${secret}"$'\n'"${b64}"$'\n'
	done
	LLEDGER_REDACT_NEEDLES="$needles" LLEDGER_REPO_ROOT="$_lledger_repo_root" perl -i -pe "${_lledger_redact_perl_common}"'
		BEGIN {
			$root = $ENV{LLEDGER_REPO_ROOT};
			($root_json = $root) =~ s{/}{\\/}g;
		}
		s{\Q$root_json\E\\/}{}g if length $root;
		s{\Q$root\E/}{}g if length $root;
		s/$needle_re/[REDACTED]/g;
		s/$auth_re(?!\[REDACTED\])$val+/$1\[REDACTED]/g;
	' "$file" || return 1
	if ! LLEDGER_REDACT_NEEDLES="$needles" perl -ne "${_lledger_redact_perl_common}"'
		if ($_ =~ $needle_re || $_ =~ /$auth_re(?!\[REDACTED\])$val/) { exit 1; }
	' "$file"; then
		echo "Redaction failed for $file" >&2
		return 1
	fi
}
