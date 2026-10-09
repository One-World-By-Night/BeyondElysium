/**
 * Display logic for a resource_pool pool governed by `raised_by` (raising it converts another pool's temporary
 * points instead of costing XP) or carrying `spent` dots marked by a `spent_from` purchase elsewhere on the sheet.
 */
import { __, sprintf } from '@wordpress/i18n';

export interface RaisedByRule {
	from: string;
	temporary: number;
}

/**
 * "Raise with Conviction (10)" from a `{from: "hunter-resources.Conviction", temporary: 10}` rule.
 */
export function raiseButtonLabel( raisedBy: RaisedByRule ): string {
	const poolName = raisedBy.from.split( '.' ).pop() ?? raisedBy.from;
	return sprintf(
		/* translators: 1: the pool spent to raise this one (e.g. Conviction), 2: how many temporary points it costs */
		__( 'Raise with %1$s (%2$s)', 'beyond-elysium' ),
		poolName,
		String( raisedBy.temporary )
	);
}

/**
 * "3 spent" for a pool's own marked-spent dot count; null when there is nothing to show.
 */
export function spentBadgeLabel( spent: number | undefined ): string | null {
	if ( ! spent ) {
		return null;
	}
	return sprintf(
		/* translators: %d: how many dots are marked spent */
		__( '%d spent', 'beyond-elysium' ),
		spent
	);
}
