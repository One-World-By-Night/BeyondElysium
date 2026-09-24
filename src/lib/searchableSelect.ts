/**
 * Pure filtering and custom-entry logic behind a searchable select control.
 */

/**
 * A named section of a pick list.
 */
export interface OptionGroup {
	label: string;
	options: string[];
}

/**
 * One rendered line of the dropdown: a section heading, or a selectable option.
 */
export type OptionRow =
	| { kind: 'heading'; label: string }
	| { kind: 'option'; value: string };

/**
 * Filters `options` down to the entries containing `query` as a case-insensitive substring match.
 */
export function filterOptions( options: string[], query: string ): string[] {
	const q = query.trim().toLowerCase();
	if ( ! q ) {
		return options;
	}
	return options.filter( ( option ) => option.toLowerCase().includes( q ) );
}

/**
 * Determines whether a typed query may be offered as a new custom entry.
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
 * Determines what a blur (leaving the input) should commit, if anything.
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
 * Lays a grouped option list out as the flat row list the dropdown renders, applying the same query filter to each
 * group's options and dropping a group once nothing in it survives.
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
 * The next selectable row at or after `from`, moving by `step`.
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

/**
 * Every option across every group, in order.
 */
export function flattenGroups( groups: OptionGroup[] ): string[] {
	return groups.flatMap( ( group ) => group.options );
}
