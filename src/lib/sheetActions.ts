/**
 * What the Character Sheet's action buttons offer a viewer.
 */

export type SheetAction =
	| 'print'
	| 'items'
	| 'appearance'
	| 'ledger'
	| 'send'
	| 'audit'
	| 'tally'
	| 'gex';

export interface SheetActionFlags {
	can_customize_sheet?: boolean;
	can_manage?: boolean;
	status?: string;
}

/**
 * The actions this viewer can use, in the order the buttons list them.
 */
export function sheetActions( flags: SheetActionFlags ): SheetAction[] {
	const offered: Array< [ SheetAction, boolean ] > = [
		[ 'print', true ],
		[ 'items', true ],
		[ 'appearance', !! flags.can_customize_sheet ],
		[ 'ledger', true ],
		[ 'send', !! flags.can_manage ],
		[ 'audit', !! flags.can_manage ],
		[ 'tally', !! flags.can_manage && flags.status === 'pending' ],
		[ 'gex', true ],
	];
	return offered
		.filter( ( [ , shown ] ) => shown )
		.map( ( [ action ] ) => action );
}
