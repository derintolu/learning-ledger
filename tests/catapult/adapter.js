/*
 * CATAPULT LTS adapter for Learning Ledger. Task 2 ships the shape only; Task 64 fills it in.
 *
 * bin/catapult-lts.sh copies this file to lts/lib/lms.custom.js. The interface is documented
 * in lts/lib/lms.template.js upstream. Environment: LLEDGER_SITE, LLEDGER_CONTENT_HOST,
 * LLEDGER_LRS_KEY, LLEDGER_LRS_SECRET, LLEDGER_CATAPULT_TEST_SECRET.
 */
const notImplemented = (name) => {
	throw new Error(`Learning Ledger CATAPULT adapter: ${name} is not implemented yet (Task 64)`);
};

module.exports = {
	setup: async () => notImplemented('setup'),
	teardown: async () => {},
	getLrsEndpoint: () => `${process.env.LLEDGER_SITE || ''}/wp-json/learning-ledger/xapi/`,
	getLrsAuthHeader: () => {
		const credentials = `${process.env.LLEDGER_LRS_KEY || ''}:${process.env.LLEDGER_LRS_SECRET || ''}`;
		return `Basic ${Buffer.from(credentials).toString('base64')}`;
	},
	importCourse: async () => notImplemented('importCourse'),
	getLaunchUrl: async () => notImplemented('getLaunchUrl'),
	cleanup: async () => {},
	hasWaive: () => false,
	waiveAU: async () => notImplemented('waiveAU'),
	hasAbandon: () => false,
	abandonSession: async () => notImplemented('abandonSession'),
};
