#!/usr/bin/env bash
# Load test against the spec section 11 targets (Task 68), with k6 from the grafana/k6 Docker image.
#
# Usage: bin/load-test.sh [--seed=<n>] [--actors=<n>] [--registrations=<n>] [--only=ingest,query,state,connector] [--out=<file>]
#
# Environment:
#   WP_ENV_PORT              wp-env port of the LRS site (default 8888).
#   LLEDGER_TEST_BASE_URL    LRS site as seen from this machine (default http://localhost:$WP_ENV_PORT).
#   LLEDGER_K6_BASE_URL      the same site as seen from the k6 container
#                            (default: localhost, 127.0.0.1 or [::1] -> host.docker.internal).
#   LLEDGER_K6_NETWORK       Docker network for the k6 container; LLEDGER_K6_BASE_URL must then name a
#                            container (or a container alias) on that network.
#   LLEDGER_XAPI_PATH        xAPI base path (default /wp-json/learning-ledger/xapi).
#   LLEDGER_WP               WP-CLI prefix for the LRS site (default: npx @wordpress/env run cli wp).
#   LLEDGER_WP_CONNECTOR     WP-CLI prefix for a site with Sensei, for the connector measurement
#                            (default: npx @wordpress/env run tests-cli wp).
#   LLEDGER_K6_IMAGE         k6 image (default grafana/k6:0.54.0).
#   SUSTAIN_DURATION, QUERY_RATE, QUERY_DURATION, STATE_RATE, STATE_DURATION are passed to the k6 scripts.
#
# The LRS site needs LLEDGER_TESTING (for the seed command) and the lrs role. Load only ever goes
# to this machine: the site URL, the k6 URL and the siteurl of every WP-CLI target must be local,
# or nothing is seeded, created or loaded. The client secret is generated here and given to k6
# only, through an env file readable by this user alone; WP-CLI sees its SHA-256. The load client
# is deleted when the script exits.
set -euo pipefail
cd "$(dirname "$0")/.."

WP_ENV_PORT=${WP_ENV_PORT:-8888}
BASE_URL=${LLEDGER_TEST_BASE_URL:-http://localhost:$WP_ENV_PORT}
K6_NETWORK=${LLEDGER_K6_NETWORK:-}
XAPI_PATH=${LLEDGER_XAPI_PATH:-/wp-json/learning-ledger/xapi}
K6_IMAGE=${LLEDGER_K6_IMAGE:-grafana/k6:0.54.0}
read -r -a WP <<<"${LLEDGER_WP:-npx @wordpress/env run cli wp}"
read -r -a WP_CONNECTOR <<<"${LLEDGER_WP_CONNECTOR:-npx @wordpress/env run tests-cli wp}"
OUT="conformance/load-$(date -u +%Y%m%d).json"
SEED=0
ACTORS=1000
REGISTRATIONS=10000
ONLY=ingest,query,state,connector

for arg in "$@"; do
	case "$arg" in
		--seed=*) SEED=${arg#*=} ;;
		--actors=*) ACTORS=${arg#*=} ;;
		--registrations=*) REGISTRATIONS=${arg#*=} ;;
		--only=*) ONLY=${arg#*=} ;;
		--out=*) OUT=${arg#*=} ;;
		*) echo "Unknown argument: $arg" >&2; exit 2 ;;
	esac
done

# Host of a URL, without userinfo or port; an IPv6 literal keeps its brackets.
host_of() {
	local h=${1#*://}
	h=${h%%/*}
	h=${h##*@}
	if [[ $h == \[* ]]; then
		echo "${h%%]*}]"
	else
		echo "${h%%:*}"
	fi
}
is_local() { case "$1" in localhost|127.0.0.1|'[::1]') return 0 ;; *) return 1 ;; esac; }
refuse() { echo "Refusing to load $1: only this machine may be load tested." >&2; exit 2; }

is_local "$(host_of "$BASE_URL")" || refuse "$BASE_URL"
if [ -z "${LLEDGER_K6_BASE_URL:-}" ]; then
	BASE_HOST=$(host_of "$BASE_URL")
	K6_BASE_URL=${BASE_URL/"$BASE_HOST"/host.docker.internal}
else
	K6_BASE_URL=$LLEDGER_K6_BASE_URL
fi
K6_HOST=$(host_of "$K6_BASE_URL")
if [ -z "$K6_NETWORK" ]; then
	is_local "$K6_HOST" || [ "$K6_HOST" = host.docker.internal ] || refuse "$K6_BASE_URL"
else
	# With a network, the k6 host must be a container (by name or alias) on that network.
	members=" "
	for id in $(docker network inspect "$K6_NETWORK" --format '{{range $id, $c := .Containers}}{{$id}} {{end}}'); do
		members+="$(docker inspect "$id" --format '{{.Name}}' | tr -d '/') "
		for field in Aliases DNSNames; do
			members+="$(docker inspect "$id" --format "{{with index .NetworkSettings.Networks \"$K6_NETWORK\"}}{{range .$field}}{{.}} {{end}}{{end}}" 2>/dev/null || true) "
		done
	done
	[[ "$members" == *" $K6_HOST "* ]] || refuse "$K6_BASE_URL (not a container on $K6_NETWORK)"
fi

wp() { "${WP[@]}" "$@" 2> >(grep -v -E '^(ℹ|✔)' >&2); }
wants() { [[ ",$ONLY," == *",$1,"* ]]; }

# Every WP-CLI target must be a site on this machine before anything is written to it.
check_site() {
	local url
	url=$("$@" option get siteurl 2>/dev/null | tail -n 1)
	is_local "$(host_of "$url")" || refuse "the WP-CLI target ($* -> ${url:-no siteurl})"
}
check_site "${WP[@]}"
if wants connector; then
	check_site "${WP_CONNECTOR[@]}"
fi

DELETE_CLIENT='global $wpdb; $wpdb->delete( $wpdb->prefix . "lledger_clients", array( "id" => "lledger-load" ) );'
WORK=$(mktemp -d)
cleanup() {
	wp eval "$DELETE_CLIENT" >/dev/null 2>&1 || true
	rm -rf "$WORK"
}
trap cleanup EXIT

SEED_SECONDS=null
if [ "$SEED" -gt 0 ]; then
	echo "Seeding $SEED statements..."
	started=$(date +%s)
	wp learning-ledger dev seed --statements="$SEED" --actors="$ACTORS" --registrations="$REGISTRATIONS"
	SEED_SECONDS=$(( $(date +%s) - started ))
fi

# Rows, the server, and a `since` that keeps the newest tenth of the rows.
read -r ROWS DB_VERSION SINCE < <(wp eval '
global $wpdb;
$t   = $wpdb->prefix . "lledger_statements";
$max = (int) $wpdb->get_var( "SELECT MAX(seq) FROM {$t}" );
$at  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT `stored` FROM {$t} WHERE seq <= %d ORDER BY seq DESC LIMIT 1", (int) ( $max * 0.9 ) ) );
echo $max, " ", str_replace( " ", "-", (string) $wpdb->get_var( "SELECT VERSION()" ) ), " ", "" === $at ? "1970-01-01T00:00:00Z" : str_replace( " ", "T", $at ) . "Z", "\n";
' | tail -n 1)
echo "Statements: $ROWS on $DB_VERSION; since=$SINCE"

SECRET=$(openssl rand -hex 32)
SECRET_SHA=$(printf '%s' "$SECRET" | openssl dgst -sha256 -r | cut -d' ' -f1)
wp eval "$DELETE_CLIENT" >/dev/null
wp learning-ledger client create --id=lledger-load --name="Load test" --secret-sha256="$SECRET_SHA" --scopes=all --rate-class=unlimited >/dev/null
( umask 077 && printf 'LRS_SECRET=%s\n' "$SECRET" >"$WORK/k6.env" )

k6() {
	local script=$1
	local status=0
	docker run --rm ${K6_NETWORK:+--network "$K6_NETWORK"} \
		-v "$PWD/tests/load:/scripts:ro" -v "$WORK:/out" --env-file "$WORK/k6.env" \
		-e BASE_URL="$K6_BASE_URL$XAPI_PATH" -e LRS_KEY=lledger-load \
		-e ACTORS="$ACTORS" -e REGISTRATIONS="$REGISTRATIONS" -e SINCE="$SINCE" \
		-e SUSTAIN_DURATION -e QUERY_RATE -e QUERY_DURATION -e STATE_RATE -e STATE_DURATION \
		"$K6_IMAGE" run --quiet "/scripts/$script.js" || status=$?
	# 99: thresholds crossed; the summary is still written and the run goes on.
	if [ "$status" -ne 0 ] && [ "$status" -ne 99 ]; then
		echo "k6 $script failed with exit code $status" >&2
		exit "$status"
	fi
}

for script in ingest query state; do
	if wants "$script"; then
		echo "k6: $script"
		k6 "$script"
	fi
done

# Connector page-view overhead: time the connector's own sensei_user_lesson_end callback
# (one INSERT expected after a learner's first event) on a site with Sensei.
if wants connector; then
	echo "Connector: Sensei lesson completion"
	"${WP_CONNECTOR[@]}" plugin activate sensei-lms learning-ledger >/dev/null 2>&1 || true
	"${WP_CONNECTOR[@]}" learning-ledger role enable connector >/dev/null 2>&1 || true
	"${WP_CONNECTOR[@]}" eval '
$listener = null;
foreach ( $GLOBALS["wp_filter"]["sensei_user_lesson_end"]->callbacks ?? array() as $list ) {
	foreach ( $list as $cb ) {
		if ( is_array( $cb["function"] ) && $cb["function"][0] instanceof \LearningLedger\Integrations\Sensei\Sensei_Listeners ) {
			$listener = $cb["function"];
		}
	}
}
if ( null === $listener ) {
	echo wp_json_encode( array( "error" => "connector Sensei listener not registered" ) ), "\n";
	return;
}
$course = wp_insert_post( array( "post_type" => "course", "post_status" => "publish", "post_title" => "Load course" ) );
$lesson = wp_insert_post( array( "post_type" => "lesson", "post_status" => "publish", "post_title" => "Load lesson" ) );
update_post_meta( $lesson, "_lesson_course", $course );
$sql = array();
add_filter( "query", function ( $q ) use ( &$sql ) { $sql[] = $q; return $q; } );
$first = array(); $steady = array(); $inserts = array(); $queries = array();
for ( $u = 0; $u < 40; $u++ ) {
	$user = wp_insert_user( array( "user_login" => "load-" . wp_generate_password( 8, false ), "user_pass" => wp_generate_password(), "role" => "subscriber" ) );
	for ( $k = 0; $k < 6; $k++ ) {
		$sql = array();
		$t   = hrtime( true );
		call_user_func( $listener, $user, $lesson );
		$ms  = ( hrtime( true ) - $t ) / 1e6;
		if ( 0 === $k ) {
			$first[] = $ms;
			continue;
		}
		$steady[]  = $ms;
		$queries[] = count( $sql );
		$inserts[] = count( array_filter( $sql, function ( $q ) { return 0 === stripos( ltrim( $q ), "INSERT" ); } ) );
	}
}
$pct = function ( array $v, float $p ) { sort( $v ); return round( $v[ (int) floor( ( count( $v ) - 1 ) * $p ) ], 3 ); };
echo wp_json_encode( array(
	"first_event_ms"    => array( "med" => $pct( $first, 0.5 ), "p(95)" => $pct( $first, 0.95 ), "count" => count( $first ) ),
	"steady_ms"         => array( "med" => $pct( $steady, 0.5 ), "p(95)" => $pct( $steady, 0.95 ), "max" => $pct( $steady, 1 ), "count" => count( $steady ) ),
	"inserts_per_event" => max( $inserts ),
	"queries_per_event" => array( "med" => $pct( $queries, 0.5 ), "max" => max( $queries ) ),
) ), "\n";
' 2>/dev/null | grep '^{' | tail -n 1 >"$WORK/connector.json"
fi

ROWS="$ROWS" DB_VERSION="$DB_VERSION" SINCE="$SINCE" SEED="$SEED" SEED_SECONDS="$SEED_SECONDS" ACTORS="$ACTORS" REGISTRATIONS="$REGISTRATIONS" \
	node - "$WORK" "$OUT" <<'NODE'
const fs = require( 'fs' );
const [ work, out ] = process.argv.slice( 2 );
const read = ( name ) => ( fs.existsSync( `${ work }/${ name }.json` ) ? JSON.parse( fs.readFileSync( `${ work }/${ name }.json`, 'utf8' ) ) : null );
const targets = {
	single: [ 'Single-statement POST (50/s sustained)', 150 ],
	burst: [ 'Batched POST, 100 statements (500 statements/s for 10 s)', null ],
	agent_registration: [ 'Filtered GET agent + registration, limit 100', 300 ],
	activity_since: [ 'Filtered GET activity + since, limit 100', 300 ],
	related_activities: [ 'related_activities GET, limit 100', 800 ],
	related_agents: [ 'related_agents GET, limit 100', 800 ],
	state_put: [ 'State PUT', 80 ],
	state_get: [ 'State GET', 80 ],
};
const result = {
	date: new Date().toISOString(),
	host: 'local',
	database: process.env.DB_VERSION,
	statements: Number( process.env.ROWS ),
	actors: Number( process.env.ACTORS ),
	registrations: Number( process.env.REGISTRATIONS ),
	seed: Number( process.env.SEED ) > 0 ? { statements: Number( process.env.SEED ), seconds: Number( process.env.SEED_SECONDS ) } : null,
	since: process.env.SINCE,
	scenarios: {},
};
for ( const name of [ 'ingest', 'query', 'state' ] ) {
	const data = read( name );
	if ( ! data ) {
		continue;
	}
	for ( const [ key, metric ] of Object.entries( data.metrics ) ) {
		const m = key.match( /^http_req_duration\{scenario:(\w+)(,op:(put|get))?\}$/ );
		if ( ! m || ! targets[ m[ 1 ] ] ) {
			continue;
		}
		const [ label, p95 ] = targets[ m[ 1 ] ];
		const v = metric.values;
		const round = ( x ) => Math.round( x * 100 ) / 100;
		result.scenarios[ m[ 1 ] ] = {
			label,
			target_p95_ms: p95,
			requests: v.count,
			med_ms: round( v.med ),
			p90_ms: round( v[ 'p(90)' ] ),
			p95_ms: round( v[ 'p(95)' ] ),
			p99_ms: round( v[ 'p(99)' ] ),
			max_ms: round( v.max ),
			met: null === p95 ? null : v[ 'p(95)' ] < p95,
		};
	}
	if ( 'ingest' === name ) {
		const stored = data.metrics.statements_stored;
		const dropped = data.metrics.dropped_iterations;
		const failed = data.metrics[ 'checks{scenario:burst}' ];
		result.ingest = {
			statements_stored: stored ? stored.values.count : 0,
			burst_statements_stored: data.metrics[ 'statements_stored{scenario:burst}' ] ? data.metrics[ 'statements_stored{scenario:burst}' ].values.count : 0,
			dropped_iterations: dropped ? dropped.values.count : 0,
			burst_checks_passed: failed ? failed.values.rate : null,
		};
	}
	result[ `${ name }_checks_passed` ] = data.metrics.checks ? data.metrics.checks.values.rate : null;
}
const connector = read( 'connector' );
if ( connector ) {
	// The target is the typical added time ("under 2 ms, one INSERT"), so it is judged on the median.
	result.connector_lesson_completion = Object.assign( { target_ms: 2, judged_on: 'steady_ms.med', met: connector.steady_ms ? connector.steady_ms.med < 2 && 1 === connector.inserts_per_event : null }, connector );
}
fs.writeFileSync( out, JSON.stringify( result, null, '\t' ) + '\n' );
console.log( JSON.stringify( result, null, 2 ) );
NODE
echo "Wrote $OUT"
