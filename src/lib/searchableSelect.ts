/**
 * Pure filtering and custom-entry logic behind a searchable select control: narrowing
 * an option list by query text, deciding when a typed value may be offered as a new
 * custom entry, and deciding what a blur (leaving the input) should commit. Exports
 * `filterOptions()`, `canUseCustomEntry()`, and `resolveBlurCommit()`.
 */

/**
 * Filters `options` down to the entries containing `query` as a case-insensitive
 * substring match. Returns the full, unfiltered list when `query` is empty or
 * whitespace-only.
 */
export function filterOptions( options: string[], query: string ): string[] {
	const q = query.trim().toLowerCase();
	if ( ! q ) {
		return options;
	}
	return options.filter( ( option ) => option.toLowerCase().includes( q ) );
}

/**
 * Determines whether a typed query may be offered as a new custom entry: `allowCustom`
 * must be true, the (trimmed) query must be non-empty, and it must not already exactly
 * match an existing option case-insensitively.
 */
export function canUseCustomEntry( query: string, options: string[], allowCustom: boolean ): boolean {
	if ( ! allowCustom ) {
		return false;
	}
	const trimmed = query.trim();
	if ( ! trimmed ) {
		return false;
	}
	const lower = trimmed.toLowerCase();
	return ! options.some( ( option ) => option.toLowerCase() === lower );
}

/**
 * Determines what a blur (leaving the input) should commit, if anything. An exact
 * case-insensitive match to an existing option commits that option as a selection. A
 * non-matching, non-empty value commits as a custom entry only when `allowCustom` is
 * true. Anything else - a blank query, or a non-matching value with custom entries
 * disallowed - commits nothing.
 *
 * @return null when nothing should be committed.
 */
export function resolveBlurCommit(
	query: string,
	options: string[],
	allowCustom: boolean
): { value: string; isCustom: boolean } | null {
	const trimmed = query.trim();
	const exact = options.find( ( option ) => option.toLowerCase() === trimmed.toLowerCase() );
	if ( exact !== undefined ) {
		return { value: exact, isCustom: false };
	}
	if ( canUseCustomEntry( query, options, allowCustom ) ) {
		return { value: trimmed, isCustom: true };
	}
	return null;
}
