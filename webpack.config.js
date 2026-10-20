/**
 * Build output goes into the shippable subfolder.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

/**
 * The stylesheet a lazily loaded chunk brings is requested with a content hash in its URL, the way its script is, so a
 * browser or cache holding an older copy of a chunk's file never serves it for a newer script.
 *
 * @param {Object} plugin A webpack plugin.
 * @return {Object} The plugin, with a hashed chunk file name when it extracts CSS.
 */
function withHashedChunkStyles( plugin ) {
	if ( plugin.constructor.name !== 'MiniCssExtractPlugin' ) {
		return plugin;
	}
	return new plugin.constructor( {
		...plugin.options,
		chunkFilename: '[name].css?ver=[contenthash]',
	} );
}

module.exports = {
	...defaultConfig,
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'beyond-elysium/build' ),
	},
	plugins: defaultConfig.plugins.map( withHashedChunkStyles ),
};
