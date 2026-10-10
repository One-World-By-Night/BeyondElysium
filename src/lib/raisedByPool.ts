/**
 * Display logic for a resource_pool pool governed by `raised_by` (raising it converts another pool's temporary
 * points instead of costing XP) or carrying `spent` dots marked by a `spent_from` purchase elsewhere on the sheet.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import type { CreationTallyUnbuyable } from '../types/character';

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
 * Whether a pool's own dots are locked for the person editing: a `raised_by` pool is raised in play, so only a
 * Storyteller, or a player filling in a new character's free dots, sets it directly.
 */
export function raisedByLocked(
	raisedBy: RaisedByRule | undefined,
	isManager: boolean,
	creating: boolean
): boolean {
	return !! raisedBy && ! isManager && ! creating;
}

/**
 * Whether the "Raise with ..." button shows: only a character that exists has temporary points to convert.
 */
export function showsRaiseButton(
	raisedBy: RaisedByRule | undefined,
	creating: boolean
): boolean {
	return !! raisedBy && ! creating;
}

/**
 * One build line for a pool set past what a new character may start with: past its free dots, or above the book's
 * maximum.
 */
export function unbuyableLine( item: CreationTallyUnbuyable ): string {
	if ( item.kind === 'book_max' ) {
		return sprintf(
			/* translators: 1: a pool's name (Balance), 2: the rating it is set to, 3: the most the book allows */
			__(
				'%1$s is set to %2$d, above the %3$d the book allows. A Storyteller sets it higher.',
				'beyond-elysium'
			),
			item.pool,
			item.value,
			item.max
		);
	}
	return sprintf(
		/* translators: 1: a pool's name (Mercy), 2: how many dots are past the free ones */
		_n(
			'%1$s is set %2$d dot past the free dots a new character gets. More are raised in play.',
			'%1$s is set %2$d dots past the free dots a new character gets. More are raised in play.',
			item.dots,
			'beyond-elysium'
		),
		item.pool,
		item.dots
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
