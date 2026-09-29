/**
 * `@wordpress/scripts`' own lint config, with this project's exceptions.
 */
const wpPlugin = require( '@wordpress/eslint-plugin' );

module.exports = [
	{ ignores: [ '**/build/**', '**/node_modules/**', '**/vendor/**' ] },
	{ files: [ '**/*.jsx', '**/*.ts', '**/*.tsx' ] },
	...wpPlugin.configs.recommended,
	...wpPlugin.configs[ 'test-unit' ].map( ( config ) => ( {
		...config,
		files: [
			'**/@(test|__tests__)/**/*.{js,jsx,ts,tsx}',
			'**/*.test.{js,jsx,ts,tsx}',
		],
	} ) ),
	{
		languageOptions: {
			parserOptions: {
				requireConfigFile: false,
				babelOptions: {
					presets: [
						require.resolve( '@wordpress/babel-preset-default' ),
					],
				},
			},
		},
		rules: {
			// Every label here wraps its control, which associates the two as fully as `htmlFor`.
			'jsx-a11y/label-has-associated-control': [
				'error',
				{ assert: 'either' },
			],
			// `== null` is the one comparison that means null or undefined.
			eqeqeq: [ 'error', 'always', { null: 'ignore' } ],
			// The admin screens confirm a delete with the browser's own dialog, as WordPress's admin does.
			'no-alert': 'off',
			// A loading / empty / content chain reads top to bottom as the formatter lays it out.
			'no-nested-ternary': 'off',
			// A widget that fails to mount says so in the console.
			'no-console': [ 'error', { allow: [ 'warn', 'error' ] } ],
		},
	},
	{
		files: [ '**/*.ts', '**/*.tsx' ],
		rules: {
			// The signature names and types every parameter; a docblock here says what the function does.
			'jsdoc/require-param': 'off',
		},
	},
];
