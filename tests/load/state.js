// State load: PUT (after a GET for the ETag) then GET of State documents on a pool of keys.
// Target (spec section 11): State GET or PUT p95 < 80 ms.
// Run by bin/load-test.sh: k6 run -e BASE_URL=... -e LRS_KEY=... -e LRS_SECRET=... tests/load/state.js
import http from 'k6/http';
import { check } from 'k6';
import encoding from 'k6/encoding';

const BASE = __ENV.BASE_URL;
const AUTH = 'Basic ' + encoding.b64encode( __ENV.LRS_KEY + ':' + __ENV.LRS_SECRET );
const ACTORS = parseInt( __ENV.ACTORS || '1000', 10 );
const RATE = parseInt( __ENV.STATE_RATE || '20', 10 );
const DURATION = parseInt( __ENV.STATE_DURATION || '30', 10 );
const KEYS = 500;

export const options = {
	discardResponseBodies: true,
	scenarios: {
		state_put: {
			executor: 'constant-arrival-rate',
			rate: RATE,
			timeUnit: '1s',
			duration: DURATION + 's',
			preAllocatedVUs: 10,
			maxVUs: 60,
			exec: 'put',
		},
		state_get: {
			executor: 'constant-arrival-rate',
			rate: RATE,
			timeUnit: '1s',
			duration: DURATION + 's',
			preAllocatedVUs: 10,
			maxVUs: 60,
			startTime: DURATION + 2 + 's',
			exec: 'get',
		},
	},
	thresholds: {
		'http_req_duration{scenario:state_put,op:put}': [ 'p(95)<80' ],
		'http_req_duration{scenario:state_get,op:get}': [ 'p(95)<80' ],
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

// Key k: actor k % ACTORS, its registration k, a lesson of that registration's course, stateId `bookmark`.
function url( k ) {
	const agent = JSON.stringify( { objectType: 'Agent', account: { homePage: 'https://seed.example', name: 'learner-' + ( k % ACTORS ) } } );
	const params = {
		activityId: 'https://seed.example/course/' + ( k % 50 ) + '/lesson/' + ( k % 20 ),
		agent,
		registration: '5eed0000-0000-4000-8000-' + hex12( k ),
		stateId: 'bookmark',
	};
	return BASE + '/activities/state?' + Object.entries( params ).map( ( [ a, v ] ) => a + '=' + encodeURIComponent( v ) ).join( '&' );
}

// What an AU does to save a bookmark: GET the document, then PUT with If-Match (If-None-Match: * when
// there is none yet). Only the PUT counts toward the target; a 412 means another VU won the race.
export function put() {
	const target = url( Math.floor( Math.random() * KEYS ) );
	const before = http.get( target, { headers: HEADERS, tags: { op: 'read_before_put' } } );
	const precondition = 200 === before.status ? { 'If-Match': '"' + ( before.headers.Etag || before.headers.ETag || '' ).replace( /"/g, '' ) + '"' } : { 'If-None-Match': '*' };
	const body = JSON.stringify( { location: 'page-' + __ITER, suspend: 'x'.repeat( 512 ) } );
	const res = http.put( target, body, { headers: Object.assign( { 'Content-Type': 'application/json' }, precondition, HEADERS ), tags: { op: 'put' } } );
	check( res, { 'PUT 204 or 412': ( x ) => 204 === x.status || 412 === x.status } );
}

export function get() {
	const res = http.get( url( Math.floor( Math.random() * KEYS ) ), { headers: HEADERS, tags: { op: 'get' } } );
	check( res, { 'GET 200 or 404': ( x ) => 200 === x.status || 404 === x.status } );
}

export function handleSummary( data ) {
	return { '/out/state.json': JSON.stringify( data ) };
}
