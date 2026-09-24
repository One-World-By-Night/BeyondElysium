/**
 * What the Character Sheet's action picker offers a viewer.
 */

export type SheetAction =
	| 'print'
	| 'items'
	| 'edit'
	| 'appearance'
	| 'history'
	| 'ledger'
	| 'send'
	| 'audit'
	| 'gex';

export interface SheetActionFlags {
	can_edit?: boolean;
	can_customize_sheet?: boolean;
	can_manage?: boolean;
}

/**
 * The actions this viewer can pick, in the order the picker lists them.
 */
export function sheetActions( flags: SheetActionFlags ): SheetAction[] {
	const offered: Array< [ SheetAction, boolean ] > = [
		[ 'print', true ],
		[ 'items', true ],
		[ 'edit', !! flags.can_edit ],
		[ 'appearance', !! flags.can_customize_sheet ],
		[ 'history', true ],
		[ 'ledger', true ],
		[ 'send', !! flags.can_manage ],
		[ 'audit', !! flags.can_manage ],
		[ 'gex', true ],
	];
	return offered
		.filter( ( [ , shown ] ) => shown )
		.map( ( [ action ] ) => action );
}
