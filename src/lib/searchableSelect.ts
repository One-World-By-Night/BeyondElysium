/**
 * Pure filtering and custom-entry logic behind a searchable select control: narrowing
 * an option list by query text, deciding when a typed value may be offered as a new
 * custom entry, deciding what a blur (leaving the input) should commit, and laying a
 * grouped option list out as a flat list of rows.
 */

/**
 * A named section of a pick list - a Werewolf gift's tribe, a Fera species, a Mage rote's
 * theme (1.2.9 U4). Grouping exists because a Fera player currently picks from **865
 * unsorted names in one list** while the catalog has known each one's species all along.
 */
export interface OptionGroup {
	label: string;
	options: string[];
}

/** One rendered line of the dropdown: a section heading, or a selectable option. */
export type OptionRow =
	| { kind: 'heading'; label: string }
	| { kind: 'option'; value: string };

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
export function canUseCustomEntry(
	query: string,
	options: string[],
	allowCustom: boolean
): boolean {
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
	const exact = options.find(
		( option ) => option.toLowerCase() === trimmed.toLowerCase()
	);
	if ( exact !== undefined ) {
		return { value: exact, isCustom: false };
	}
	if ( canUseCustomEntry( query, options, allowCustom ) ) {
		return { value: trimmed, isCustom: true };
	}
	return null;
}

/**
 * Lays a grouped option list out as the flat row list the dropdown renders, applying the
 * same query filter to each group's options and dropping a group once nothing in it
 * survives - so a search still spans every option across every section, and an emptied
 * section simply isn't in the way.
 *
 * **Grouping is a sort, never a filter** (1.2.9 §U4), and this is where that is enforced:
 * every group given is laid out, in the order given. Nothing is hidden, greyed, gated
 * behind a toggle, or excluded from search because it is out of type. An out-of-type Gift
 * is legal - LotW Revised charges **+1** for a Gift outside breed, auspice or tribe, and a
 * surcharge means purchasable, not forbidden. A Homid/Galliard/Fianna character may take a
 * Get of Fenris gift; it costs 4 instead of 3, and pricing is `Cost_Engine`'s job, never
 * this picker's.
 *
 * A group with no label renders its options with no heading - how the ungrouped remainder
 * of a partly-grouped catalog (`mage-rotes` has 134 such) reaches the list without being
 * filed under an invented name.
 */
export function buildOptionRows(
	groups: OptionGroup[],
	query: string
): OptionRow[] {
	const rows: OptionRow[] = [];
	for ( const group of groups ) {
		const matching = filterOptions( group.options, query );
		if ( matching.length === 0 ) {
			continue;
		}
		if ( group.label !== '' ) {
			rows.push( { kind: 'heading', label: group.label } );
		}
		for ( const value of matching ) {
			rows.push( { kind: 'option', value } );
		}
	}
	return rows;
}

/**
 * The next selectable row at or after `from`, moving by `step` - how arrow keys skip past
 * a section heading rather than landing on one. Returns -1 when there is none, so a
 * highlight at the end of the list stays put instead of wrapping onto a heading.
 *
 * `rowCount` may exceed `rows.length` by one, for the custom-entry row the caller appends
 * after every group; that trailing index is always selectable.
 */
export function nextSelectableRow(
	rows: OptionRow[],
	from: number,
	step: 1 | -1,
	rowCount: number
): number {
	for ( let i = from; i >= 0 && i < rowCount; i += step ) {
		if ( i >= rows.length || rows[ i ].kind === 'option' ) {
			return i;
		}
	}
	return -1;
}

/** Every option across every group, in order - what custom-entry and blur logic compare against. */
export function flattenGroups( groups: OptionGroup[] ): string[] {
	return groups.flatMap( ( group ) => group.options );
}
