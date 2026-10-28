/*
 * CATAPULT LTS adapter for Learning Ledger.
 *
 * bin/catapult-lts.sh copies this file to lts/lib/lms.custom.js; the interface is documented in
 * lts/lib/lms.template.js upstream. Every call goes to the test-only routes under
 * /wp-json/learning-ledger/v1/testing/catapult/ (src/Player/Testing/class-catapult-routes.php),
 * which exist only when LLEDGER_CATAPULT_TEST_ROUTES is true and answer only requests whose
 * X-Learning-Ledger-Test header equals LLEDGER_CATAPULT_TEST_SECRET.
 *
 * Environment: LLEDGER_SITE, LLEDGER_LRS_KEY, LLEDGER_LRS_SECRET, LLEDGER_CATAPULT_TEST_SECRET.
 */
const Wreck = require('@hapi/wreck');
const { InvalidPackageError } = require('./errors');

// The LRS's consistent-through lag (1 s) plus a margin.
const CONSISTENCY_MS = 1200;

// The suite gives each hook 5 s. Deleting a big course (101-one-thousand-aus) takes longer, so
// cleanup and teardown wait at most this long for it; the site finishes the deletion anyway.
const CLEANUP_WAIT_MS = 3000;

const imported = [];
let learner = 0;
let pending = Promise.resolve();

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const site = () => (process.env.LLEDGER_SITE || '').replace(/\/+$/, '');

const call = async (route, payload) => {
	const response = await Wreck.request('POST', `${site()}/wp-json/learning-ledger/v1/testing/catapult/${route}`, {
		headers: {
			'Content-Type': 'application/json',
			'X-Learning-Ledger-Test': process.env.LLEDGER_CATAPULT_TEST_SECRET || '',
		},
		payload: JSON.stringify(payload || {}),
	});
	const text = (await Wreck.read(response)).toString();
	let body = null;
	try {
		body = JSON.parse(text);
	} catch (ex) {
		body = null;
	}
	return { status: response.statusCode, body, text };
};

const fail = (route, res) => new Error(`Learning Ledger ${route} answered ${res.status}: ${res.text.slice(0, 500)}`);

module.exports = {
	setup: async () => {
		const res = await call('setup');
		if (res.status !== 200 || !res.body || !res.body.user) {
			throw fail('setup', res);
		}
		learner = res.body.user;
	},

	teardown: async () => {
		await Promise.race([pending, sleep(CLEANUP_WAIT_MS)]);
	},

	// No trailing slash: the suite appends "/statements".
	getLrsEndpoint: () => `${site()}/wp-json/learning-ledger/xapi`,

	getLrsAuthHeader: () => {
		const credentials = `${process.env.LLEDGER_LRS_KEY || ''}:${process.env.LLEDGER_LRS_SECRET || ''}`;
		return `Basic ${Buffer.from(credentials).toString('base64')}`;
	},

	// The package is read by name from the LTS build inside the plugin directory, which the
	// site sees through the wp-env mount; the stream itself is not sent.
	importCourse: async (stream, contentType, filename) => {
		if (stream && typeof stream.destroy === 'function') {
			stream.destroy();
		}
		const res = await call('import', { package: filename, user: learner });
		if (res.status === 422) {
			const errors = (res.body && res.body.errors) || [];
			throw new InvalidPackageError(`Invalid package ${filename}: ${errors.map((e) => `${e.code} ${e.message}`).join('; ')}`);
		}
		if (res.status !== 200 || !res.body || !res.body.course) {
			throw fail('import', res);
		}
		imported.push(res.body.course);
		return res.body.course;
	},

	getLaunchUrl: async (courseId, auIndex, actor, { registration, launchMode } = {}) => {
		const res = await call('launch-url', {
			course: courseId,
			au: auIndex,
			user: learner,
			registration: registration || '',
			launchMode: launchMode || '',
		});
		if (res.status !== 200 || !res.body || !res.body.url) {
			throw fail('launch-url', res);
		}
		return res.body.url;
	},

	// Runs after every test, before the suite reads the test's statements back. The LRS lists
	// statements only once they are a second old (X-Experience-API-Consistent-Through is now
	// minus 1 s), so wait that out here; the suite does not read the header.
	cleanup: async () => {
		const courses = imported.splice(0, imported.length);
		if (courses.length === 0) {
			return;
		}
		const done = call('cleanup', { courses })
			.then((res) => {
				if (res.status !== 200) {
					console.log(`Learning Ledger cleanup failed: ${res.status}`);
				}
			})
			.catch((ex) => console.log(`Learning Ledger cleanup failed: ${ex}`));
		pending = pending.then(() => done);
		await Promise.race([done, sleep(CLEANUP_WAIT_MS)]);
		await sleep(CONSISTENCY_MS);
	},

	hasWaive: () => true,
	waiveAU: async (registration, auIndex, reason) => {
		const res = await call('waive', { registration, au: auIndex, reason });
		if (res.status !== 200) {
			throw fail('waive', res);
		}
	},

	hasAbandon: () => true,
	abandonSession: async (sessionId) => {
		const res = await call('abandon', { session: sessionId });
		if (res.status !== 200) {
			throw fail('abandon', res);
		}
	},
};
