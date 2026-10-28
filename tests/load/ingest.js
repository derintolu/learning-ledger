// Ingest load: 50 single-statement POSTs/s sustained, then 500 statements/s for 10 s as batched POSTs.
// Targets (spec section 11): sustained 50 statements/s; burst 500 statements/s for 10 s; single POST p95 < 150 ms.
// Run by bin/load-test.sh: k6 run -e BASE_URL=... -e LRS_KEY=... -e LRS_SECRET=... tests/load/ingest.js
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import encoding from 'k6/encoding';

const BASE = __ENV.BASE_URL;
const AUTH = 'Basic ' + encoding.b64encode( __ENV.LRS_KEY + ':' + __ENV.LRS_SECRET );
const ACTORS = parseInt( __ENV.ACTORS || '1000', 10 );
const REGISTRATIONS = parseInt( __ENV.REGISTRATIONS || '10000', 10 );
// Sustained phase length in seconds: a k6 duration such as 90s, 2m or 1m30s, or a plain number of seconds.
function seconds( value ) {
	const text = String( value ).trim();
	if ( /^\d+$/.test( text ) ) {
		return parseInt( text, 10 );
	}
	const units = { h: 3600, m: 60, s: 1 };
	let total = 0;
	const rest = text.replace( /(\d+)([hms])/g, ( all, n, unit ) => {
		total += parseInt( n, 10 ) * units[ unit ];
		return '';
	} );
	if ( '' !== rest || 0 === total ) {
		throw new Error( 'SUSTAIN_DURATION must be like 60, 90s, 2m or 1m30s; got ' + text );
	}
	return total;
}
const SUSTAIN = seconds( __ENV.SUSTAIN_DURATION || '60' );
const BURST_BATCH = 100;
const stored = new Counter( 'statements_stored' );

export const options = {
	discardResponseBodies: true,
	scenarios: {
		single: {
			executor: 'constant-arrival-rate',
			rate: 50,
			timeUnit: '1s',
			duration: SUSTAIN + 's',
			preAllocatedVUs: 20,
			maxVUs: 100,
			exec: 'single',
		},
		burst: {
			executor: 'constant-arrival-rate',
			rate: 500 / BURST_BATCH,
			timeUnit: '1s',
			duration: '10s',
			preAllocatedVUs: 10,
			maxVUs: 50,
			startTime: SUSTAIN + 5 + 's',
			exec: 'burst',
		},
	},
	thresholds: {
		'http_req_duration{scenario:single}': [ 'p(95)<150' ],
		// No latency target for a batch; listed so the summary reports it.
		'http_req_duration{scenario:burst}': [ 'max>=0' ],
		'checks{scenario:single}': [ 'rate==1' ],
		'checks{scenario:burst}': [ 'rate==1' ],
		'statements_stored{scenario:burst}': [ 'count>=5000' ],
		dropped_iterations: [ 'count==0' ],
	},
	summaryTrendStats: [ 'avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max', 'count' ],
};

const HEADERS = {
	Authorization: AUTH,
	'Content-Type': 'application/json',
	'X-Experience-API-Version': '2.0.0',
};

function hex12( n ) {
	return ( '000000000000' + n.toString( 16 ) ).slice( -12 );
}

// Same shape as Seed_Command::statement(), on a random registration of the seeded space.
function statement() {
	const r = Math.floor( Math.random() * REGISTRATIONS );
	const course = r % 50;
	const lesson = Math.floor( Math.random() * 20 );
	return {
		actor: { objectType: 'Agent', account: { homePage: 'https://seed.example', name: 'learner-' + ( r % ACTORS ) } },
		verb: { id: 'http://adlnet.gov/expapi/verbs/experienced', display: { 'en-US': 'experienced' } },
		object: { objectType: 'Activity', id: 'https://seed.example/course/' + course + '/lesson/' + lesson },
		result: { completion: true, success: true, duration: 'PT45S', score: { scaled: 0.8 } },
		context: {
			registration: '5eed0000-0000-4000-8000-' + hex12( r ),
			contextActivities: {
				parent: [ { id: 'https://seed.example/course/' + course } ],
				category: [ { id: 'https://w3id.org/xapi/cmi5/context/categories/moveon' } ],
			},
			extensions: { 'https://w3id.org/xapi/cmi5/context/extensions/sessionid': 'load-session-' + r },
		},
	};
}

export function single() {
	const res = http.post( BASE + '/statements', JSON.stringify( statement() ), { headers: HEADERS } );
	if ( check( res, { 'single POST 200': ( x ) => 200 === x.status } ) ) {
		stored.add( 1 );
	}
}

export function burst() {
	const batch = [];
	for ( let i = 0; i < BURST_BATCH; i++ ) {
		batch.push( statement() );
	}
	const res = http.post( BASE + '/statements', JSON.stringify( batch ), { headers: HEADERS, timeout: '30s' } );
	if ( check( res, { 'batch POST 200': ( x ) => 200 === x.status } ) ) {
		stored.add( BURST_BATCH );
	}
}

export function handleSummary( data ) {
	return { '/out/ingest.json': JSON.stringify( data ) };
}
