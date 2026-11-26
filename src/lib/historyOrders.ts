/**
 * A character's change history read as orders: changes sent together are one order, each line with its own status.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { describeChange } from './describeChange';
import { formatStoredTime } from './storedTime';
import type { CharacterChange } from '../types/character';

export type HistoryFilter = 'all' | 'purchases' | 'play';

/**
 * The changes the table writes for itself: a pool spent or regained, a wound, a loss tonight and a night closing.
 */
const PLAY_TYPES = [
	'temporary_pool_spend',
	'wound_taken',
	'wound_healed',
	'night_loss_ability',
	'night_loss_trait',
	'night_losses_cleared',
	'session_closed',
];

export function isPlayChange( change: Pick< CharacterChange, 'change_type' > ) {
	return PLAY_TYPES.includes( change.change_type );
}

export function filterChanges(
	items: CharacterChange[],
	filter: HistoryFilter
): CharacterChange[] {
	if ( filter === 'all' ) {
		return items;
	}
	return items.filter( ( change ) =>
		filter === 'play' ? isPlayChange( change ) : ! isPlayChange( change )
	);
}

export interface HistoryOrder {
	/**
	 * Stable across reloads: the submission's id, or the lone change's own.
	 */
	key: string;
	/**
	 * The shared id of a set sent together; null for a change that stands alone.
	 */
	submissionId: string | null;
	/**
	 * The order's changes in the order they were sent.
	 */
	items: CharacterChange[];
}

/**
 * Orders newest first from a list already newest first: the changes of one submission together, any other change on
 * its own.
 */
export function groupOrders( items: CharacterChange[] ): HistoryOrder[] {
	const orders: HistoryOrder[] = [];
	const bySubmission = new Map< string, HistoryOrder >();
	for ( const change of items ) {
		const id = change.submission_id ?? null;
		if ( id === null || id === '' ) {
			orders.push( {
				key: `change-${ change.id }`,
				submissionId: null,
				items: [ change ],
			} );
			continue;
		}
		const existing = bySubmission.get( id );
		if ( existing ) {
			existing.items.push( change );
			continue;
		}
		const order: HistoryOrder = {
			key: `submission-${ id }`,
			submissionId: id,
			items: [ change ],
		};
		bySubmission.set( id, order );
		orders.push( order );
	}
	for ( const order of orders ) {
		order.items.sort( ( a, b ) => a.id - b.id );
	}
	return orders;
}

/**
 * Where one line stands.
 */
export type LineStatus =
	| 'waiting'
	| 'applied'
	| 'approved'
	| 'refused'
	| 'withdrawn'
	| 'undone'
	| 'forwarded';

export function lineStatus( change: CharacterChange ): LineStatus {
	switch ( change.status ) {
		case 'pending':
			return 'waiting';
		case 'rejected':
			return 'refused';
		case 'withdrawn':
			return 'withdrawn';
		case 'undone':
			return 'undone';
		case 'forwarded':
			return 'forwarded';
		default:
			return change.auto_approved || isPlayChange( change )
				? 'applied'
				: 'approved';
	}
}

export function lineStatusLabel( change: CharacterChange ): string {
	switch ( lineStatus( change ) ) {
		case 'waiting':
			return __( 'Waiting for a Storyteller', 'beyond-elysium' );
		case 'applied':
			return __( 'Applied', 'beyond-elysium' );
		case 'approved':
			return __( 'Approved', 'beyond-elysium' );
		case 'refused':
			return __( 'Refused', 'beyond-elysium' );
		case 'withdrawn':
			return __( 'Withdrawn', 'beyond-elysium' );
		case 'undone':
			return __( 'Undone', 'beyond-elysium' );
		default:
			return __( 'Forwarded home', 'beyond-elysium' );
	}
}

/**
 * Whether a refusal is the sweep's, which answers a change nobody decided.
 */
export function isSweepRefusal( change: CharacterChange ): boolean {
	return change.status === 'rejected' && change.reviewed_by === 0;
}

/**
 * The Storyteller's note on a line as History says it: the sweep's refusal reads as no answer.
 */
export function reviewerNote( change: CharacterChange ): string | null {
	if ( isSweepRefusal( change ) ) {
		return __( 'No answer in 60 days', 'beyond-elysium' );
	}
	return change.review_notes ? change.review_notes : null;
}

/**
 * What an order cost in all: the positive prices of its lines.
 */
export function orderCost( order: HistoryOrder ): number {
	return order.items.reduce(
		( sum, change ) => sum + Math.max( 0, Number( change.xp_cost ) ),
		0
	);
}

/**
 * The order's head: when it was sent, how many changes it holds and what it cost.
 */
export function orderHead( order: HistoryOrder ): string {
	const sent = formatStoredTime( order.items[ 0 ].submitted_at, {
		time: false,
	} );
	const count = sprintf(
		/* translators: %d: how many changes were sent together */
		_n( '%d change', '%d changes', order.items.length, 'beyond-elysium' ),
		order.items.length
	);
	const cost = orderCost( order );
	return [
		sprintf(
			/* translators: %s: the date an order was sent */
			__( 'Sent %s', 'beyond-elysium' ),
			sent
		),
		count,
		...( cost > 0
			? [
					sprintf(
						/* translators: %d: the XP an order cost */
						__( '%d XP', 'beyond-elysium' ),
						cost
					),
				]
			: [] ),
	].join( ' · ' );
}

/**
 * How the order's lines stand, leaving out what is zero: "2 applied · 1 waiting · 1 refused".
 */
export function orderSummary( order: HistoryOrder ): string {
	const counts: Record< LineStatus, number > = {
		applied: 0,
		approved: 0,
		waiting: 0,
		refused: 0,
		withdrawn: 0,
		undone: 0,
		forwarded: 0,
	};
	for ( const change of order.items ) {
		counts[ lineStatus( change ) ]++;
	}
	const parts: string[] = [];
	if ( counts.applied > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: how many lines applied at once */
				__( '%d applied', 'beyond-elysium' ),
				counts.applied
			)
		);
	}
	if ( counts.approved > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: how many lines a Storyteller approved */
				__( '%d approved', 'beyond-elysium' ),
				counts.approved
			)
		);
	}
	if ( counts.waiting > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: how many lines are waiting for a Storyteller */
				__( '%d waiting', 'beyond-elysium' ),
				counts.waiting
			)
		);
	}
	if ( counts.refused > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: how many lines a Storyteller refused */
				__( '%d refused', 'beyond-elysium' ),
				counts.refused
			)
		);
	}
	if ( counts.withdrawn > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: how many lines the player withdrew */
				__( '%d withdrawn', 'beyond-elysium' ),
				counts.withdrawn
			)
		);
	}
	if ( counts.undone > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: how many lines a Storyteller undid */
				__( '%d undone', 'beyond-elysium' ),
				counts.undone
			)
		);
	}
	if ( counts.forwarded > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: how many lines were forwarded to the character's home chronicle */
				__( '%d forwarded', 'beyond-elysium' ),
				counts.forwarded
			)
		);
	}
	return parts.join( ' · ' );
}

/**
 * How many lines of an order are still waiting.
 */
export function waitingCount( order: HistoryOrder ): number {
	return order.items.filter( ( change ) => change.status === 'pending' )
		.length;
}

/**
 * The question a Storyteller is asked before undoing one line, with what it does for the character's XP.
 */
export function undoQuestion(
	change: CharacterChange,
	characterName: string
): string {
	const what = describeChange( change.change_type, change.change_data );
	const amount = Number(
		( change.change_data as { amount?: unknown } )?.amount
	);

	if ( change.change_type === 'xp_earn' && amount > 0 ) {
		return sprintf(
			/* translators: 1: what the change did, 2: the XP an award gave, 3: the character's name */
			__( 'Undo %1$s? %2$d XP comes off %3$s.', 'beyond-elysium' ),
			what,
			amount,
			characterName
		);
	}
	if ( change.change_type === 'xp_adjust' && amount < 0 ) {
		return sprintf(
			/* translators: 1: what the change did, 2: the XP a correction took, 3: the character's name */
			__( 'Undo %1$s? %2$d XP goes back to %3$s.', 'beyond-elysium' ),
			what,
			Math.abs( amount ),
			characterName
		);
	}
	const cost = Math.max( 0, Number( change.xp_cost ) );
	if ( cost > 0 ) {
		return sprintf(
			/* translators: 1: what the change did, 2: the XP it cost, 3: the character's name */
			__( 'Undo %1$s? %2$d XP goes back to %3$s.', 'beyond-elysium' ),
			what,
			cost,
			characterName
		);
	}
	return sprintf(
		/* translators: %s: what the change did */
		__( 'Undo %s?', 'beyond-elysium' ),
		what
	);
}

/**
 * The question asked before undoing a whole set.
 */
export function undoSetQuestion( order: HistoryOrder ): string {
	const count = undoableCount( order );
	return sprintf(
		/* translators: %d: how many changes of the set are undone together */
		_n(
			'Undo this set? %d change is undone.',
			'Undo this set? %d changes are undone, newest first, or none of them are.',
			count,
			'beyond-elysium'
		),
		count
	);
}

/**
 * How many lines of an order a Storyteller can undo.
 */
export function undoableCount( order: HistoryOrder ): number {
	return order.items.filter( ( change ) => change.can_undo === true ).length;
}
