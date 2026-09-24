import type { SheetChange } from '../types/import';

export type SheetChangeKind = 'added' | 'removed' | 'changed';

/**
 * Whether a difference adds something, takes something away, or changes a value.
 */
export function sheetChangeKind( change: SheetChange ): SheetChangeKind {
	if ( change.here === null ) {
		return 'added';
	}
	return change.arriving === null ? 'removed' : 'changed';
}

/**
 * Groups differences by section, in the order each section first appears.
 */
export function groupSheetChanges(
	changes: SheetChange[]
): Array< { section: string; changes: SheetChange[] } > {
	const groups: Array< { section: string; changes: SheetChange[] } > = [];
	for ( const change of changes ) {
		const group = groups.find( ( g ) => g.section === change.section );
		if ( group ) {
			group.changes.push( change );
		} else {
			groups.push( { section: change.section, changes: [ change ] } );
		}
	}
	return groups;
}
