// @vitest-environment node

/**
 * A stylesheet a lazily loaded chunk brings is requested with a content hash in its URL, the way the chunk's script
 * is, so a browser or cache holding an older copy of the file never serves it for a newer script.
 */
export {};

type WebpackPlugin = {
	constructor: { name: string };
	options?: Record< string, unknown >;
};

const config = require( '../../webpack.config.js' ) as {
	plugins: WebpackPlugin[];
};

describe( 'the webpack config', () => {
	const extractors = config.plugins.filter(
		( plugin ) => plugin.constructor.name === 'MiniCssExtractPlugin'
	);

	it( 'extracts CSS with exactly one plugin', () => {
		expect( extractors ).toHaveLength( 1 );
	} );

	it( "names a lazy chunk's stylesheet with a content hash", () => {
		expect( extractors[ 0 ].options?.chunkFilename ).toBe(
			'[name].css?ver=[contenthash]'
		);
	} );

	it( "keeps the entry stylesheet's own name", () => {
		expect( extractors[ 0 ].options?.filename ).toBe( '[name].css' );
	} );
} );
