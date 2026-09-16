/**
 * `@wordpress/scripts`' own lint config, with this project's exceptions. Each one was decided
 * against the code it would have flagged (1.0.0-review F-018).
 */
module.exports = {
	root: true,
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	parserOptions: {
		requireConfigFile: false,
		babelOptions: {
			presets: [ require.resolve( '@wordpress/babel-preset-default' ) ],
		},
	},
	rules: {
		// Every label here wraps its control, which associates the two as fully as `htmlFor`.
		'jsx-a11y/label-has-associated-control': [ 'error', { assert: 'either' } ],
		// `== null` is the one comparison that means null or undefined.
		eqeqeq: [ 'error', 'always', { null: 'ignore' } ],
		// The admin screens confirm a delete with the browser's own dialog, as WordPress's admin does.
		'no-alert': 'off',
		// A loading / empty / content chain reads top to bottom as the formatter lays it out.
		'no-nested-ternary': 'off',
		// A widget that fails to mount says so in the console.
		'no-console': [ 'error', { allow: [ 'warn', 'error' ] } ],
	},
	overrides: [
		{
			files: [ '**/*.ts', '**/*.tsx' ],
			rules: {
				// The signature names and types every parameter; a docblock here says what the function does.
				'jsdoc/require-param': 'off',
			},
		},
		{
			files: [ '**/@(test|__tests__)/**/*.[jt]s?(x)', '**/?(*.)test.[jt]s?(x)' ],
			extends: [ 'plugin:@wordpress/eslint-plugin/test-unit' ],
		},
	],
};
