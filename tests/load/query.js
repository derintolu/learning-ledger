// Query load against a seeded LRS (wp learning-ledger dev seed), one scenario after another.
// Targets (spec section 11), limit 100: filtered GET (agent + registration, or activity + since) p95 < 300 ms;
// related_activities / related_agents p95 < 800 ms.
// Run by bin/load-test.sh: k6 run -e BASE_URL=... -e LRS_KEY=... -e LRS_SECRET=... -e SINCE=<xAPI time> tests/load/query.js
import http from 'k6/http';
import { check } from 'k6';
import encoding from 'k6/encoding';

const BASE = __ENV.BASE_URL;
const AUTH = 'Basic ' + encoding.b64encode( __ENV.LRS_KEY + ':' + __ENV.LRS_SECRET );
const ACTORS = parseInt( __ENV.ACTORS || '1000', 10 );
const REGISTRATIONS = parseInt( __ENV.REGISTRATIONS || '10000', 10 );
const SINCE = __ENV.SINCE;
const RATE = parseInt( __ENV.QUERY_RATE || '5', 10 );
const DURATION = parseInt( __ENV.QUERY_DURATION || '30', 10 );
const NAMES = [ 'agent_registration', 'activity_since', 'related_activities', 'related_agents' ];

function scenario( name, i ) {
	return {
		executor: 'constant-arrival-rate',
		rate: RATE,
		timeUnit: '1s',
		duration: DURATION + 's',
		preAllocatedVUs: 10,
		maxVUs: 60,
		startTime: i * ( DURATION + 2 ) + 's',
		exec: name,
	};
}

export const options = {
	discardResponseBodies: false,
	scenarios: Object.fromEntries( NAMES.map( ( n, i ) => [ n, scenario( n, i ) ] ) ),
	thresholds: {
		'http_req_duration{scenario:agent_registration}': [ 'p(95)<300' ],
		'http_req_duration{scenario:activity_since}': [ 'p(95)<300' ],
		'http_req_duration{scenario:related_activities}': [ 'p(95)<800' ],
		'http_req_duration{scenario:related_agents}': [ 'p(95)<800' ],
		checks: [ 'rate==1' ],
	},
	summaryTrendStats: [ 'avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max', 'count' ],
};

const HEADERS = {
	Authorization: AUTH,
	'X-Experience-API-Version': '2.0.0',
};

function hex12( n ) {
	return ( '000000000000' + n.toString( 16 ) ).slice( -12 );
}

function agent( a ) {
	return JSON.stringify( { objectType: 'Agent', account: { homePage: 'https://seed.example', name: 'learner-' + a } } );
}

function pick( n ) {
	return Math.floor( Math.random() * n );
}

function get( params, minimum ) {
	const qs = Object.entries( params ).map( ( [ k, v ] ) => k + '=' + encodeURIComponent( v ) ).join( '&' );
	const res = http.get( BASE + '/statements?' + qs, { headers: HEADERS, timeout: '30s' } );
	check( res, {
		'200': ( x ) => 200 === x.status,
		'found rows': ( x ) => 200 === x.status && x.json( 'statements' ).length >= minimum,
	} );
}

// A seeded registration r belongs to actor r % ACTORS, so every pair has rows.
export function agent_registration() {
	const r = pick( REGISTRATIONS );
	get( { agent: agent( r % ACTORS ), registration: '5eed0000-0000-4000-8000-' + hex12( r ), limit: 100 }, 1 );
}

export function activity_since() {
	get( { activity: 'https://seed.example/course/' + pick( 50 ) + '/lesson/' + pick( 20 ), since: SINCE, limit: 100 }, 0 );
}

// A course is every seeded statement's context parent.
export function related_activities() {
	get( { activity: 'https://seed.example/course/' + pick( 50 ), related_activities: 'true', limit: 100 }, 100 );
}

export function related_agents() {
	get( { agent: agent( pick( ACTORS ) ), related_agents: 'true', limit: 100 }, 1 );
}

export function handleSummary( data ) {
	return { '/out/query.json': JSON.stringify( data ) };
}
