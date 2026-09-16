/**
 * `@wordpress/scripts`' own stylelint config, with this project's exceptions (1.0.0-review F-018).
 */
module.exports = {
	extends: '@wordpress/stylelint-config/scss-stylistic',
	rules: {
		'selector-class-pattern': null,
		// Comments are prose; the 80-column limit is for the rules themselves.
		'@stylistic/max-line-length': [ 80, { ignore: [ 'comments' ] } ],
		// Every rule it flagged here sets different properties, or a state the earlier rule
		// excludes (`:disabled` after `:hover:not(:disabled)`), so source order decides nothing.
		'no-descending-specificity': null,
	},
};
