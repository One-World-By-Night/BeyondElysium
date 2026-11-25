/**
 * The one line the pending-changes bar shows while its list is closed.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

export interface PendingLine {
	/**
	 * The count of changes and their total cost, when one is known.
	 */
	lead: string;
	/**
	 * What is left to spend after them, or how far over they run; null with no price to show.
	 */
	tail: string | null;
	over: boolean;
}

/**
 * The bar's line for `count` pending changes costing `totalCost` XP, leaving `unspentAfter`. Both are null when there is
 * no price to show.
 */
export function pendingLine(
	count: number,
	totalCost: number | null,
	unspentAfter: number | null
): PendingLine {
	const changes = sprintf(
		/* translators: %d: number of changes waiting to be submitted */
		_n( '%d change', '%d changes', count, 'beyond-elysium' ),
		count
	);
	if ( totalCost === null || unspentAfter === null ) {
		return { lead: changes, tail: null, over: false };
	}
	const cost = sprintf(
		/* translators: %d: the XP cost of an item */
		__( '%d XP', 'beyond-elysium' ),
		totalCost
	);
	const over = unspentAfter < 0;
	return {
		lead: `${ changes } · ${ totalCost >= 0 ? '+' : '' }${ cost }`,
		tail: over
			? sprintf(
					/* translators: %d: how many XP the pending changes cost beyond what is unspent */
					__( '%d XP over', 'beyond-elysium' ),
					-unspentAfter
				)
			: sprintf(
					/* translators: %d: XP left unspent after the pending changes */
					__( '%d XP left', 'beyond-elysium' ),
					unspentAfter
				),
		over,
	};
}
