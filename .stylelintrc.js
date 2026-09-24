/**
 * `@wordpress/scripts`' own stylelint config, with this project's exceptions.
 */
module.exports = {
	extends: '@wordpress/stylelint-config/scss-stylistic',
	rules: {
		'selector-class-pattern': null,
		// Comments are prose; the 80-column limit is for the rules themselves.
		'@stylistic/max-line-length': [ 80, { ignore: [ 'comments' ] } ],
		'no-descending-specificity': null,
	},
};
