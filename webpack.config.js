/**
 * Build output goes into the shippable subfolder, not the repo root.
 *
 * `wp-scripts` defaults to writing `build/` beside its own working directory,
 * which since the Step 10h restructure is the repo root - one level above the
 * plugin. The plugin reads its bundle as BE_PLUGIN_DIR . 'build/index.asset.php',
 * so the output has to land inside `beyond-elysium/` for both the local symlinked
 * install and the shipped artifact to find it.
 *
 * Everything else is wp-scripts' own default config, spread through unchanged -
 * entry resolution, the dependency-extraction and RTL plugins, and the loaders all
 * follow output.path on their own.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'beyond-elysium/build' ),
	},
};
