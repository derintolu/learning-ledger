const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: async () => {
		const base =
			typeof defaultConfig.entry === 'function'
				? await defaultConfig.entry()
				: defaultConfig.entry;
		return {
			...base,
			'fields/index': path.resolve( __dirname, 'src-js/fields/index.js' ),
		};
	},
};
