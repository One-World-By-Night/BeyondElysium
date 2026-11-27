/**
 * What the cart shows: each pending change as a line with its price and whether it applies now or waits for a
 * Storyteller, the totals, the one-line meter and the sentences after Submit.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { coordinatorNote } from './coordinatorSend';
import { describeChange } from './describeChange';
import { poolKind } from './poolKind';
import { previewPriceLabel } from './queuePrice';
import type { ResourcePoolDefinition, SchemaBlock } from '../types';
import type {
	ChangePreviewResult,
	ChangeRequest,
	PreviewChangesResponse,
} from '../types/character';

/**
 * Where a line stands: `applies` at once, `waits` for a Storyteller, `refused` as written, or `pricing` while the
 * server has not priced it yet.
 */
export type LineGroup = 'applies' | 'waits' | 'refused' | 'pricing';

export interface CartLine {
	/**
	 * The line's place among the pending changes.
	 */
	index: number;
	change: ChangeRequest;
	text: string;
	group: LineGroup;
	/**
	 * What the line costs in XP; 0 for a line not priced yet or refused.
	 */
	cost: number;
	/**
	 * Words standing in for a price: a refusal or "a Storyteller sets the price"; null when the price is real.
	 */
	priceNote: string | null;
	/**
	 * Why the line waits, when the rule that holds it says.
	 */
	reason: string | null;
	/**
	 * Words telling the player a Storyteller may send the line to a Coordinator; null when it cannot be.
	 */
	coordinatorNote: string | null;
}

/**
 * What a line says it does. A pool with no temporary points reads as its rating alone.
 */
function lineText(
	change: ChangeRequest,
	blocks: Record< string, SchemaBlock > | undefined
): string {
	if ( change.change_type === 'modify_resource' ) {
		const data = change.change_data as {
			block_slug?: string;
			values?: Record< string, { permanent?: number } >;
		};
		const block = blocks?.[ data.block_slug ?? '' ];
		const [ name, value ] = Object.entries( data.values ?? {} )[ 0 ] ?? [];
		const pool =
			block?.section_type === 'resource_pool'
				? ( block.definition as ResourcePoolDefinition ).pools.find(
						( entry ) => entry.name === name
					)
				: undefined;
		if ( pool && poolKind( pool ) === 'rating_only' ) {
			return sprintf(
				/* translators: 1: the name of a trait or pool, 2: its new value */
				__( '%1$s → %2$s', 'beyond-elysium' ),
				name,
				String( value?.permanent ?? '' )
			);
		}
	}
	return describeChange( change.change_type, change.change_data );
}

/**
 * One line for each pending change, in order, from the server's pricing of the same list.
 */
export function cartLines(
	changes: ChangeRequest[],
	results: ChangePreviewResult[] | undefined,
	blocks?: Record< string, SchemaBlock >
): CartLine[] {
	return changes.map( ( change, index ) => {
		const preview = results?.[ index ];
		const text = lineText( change, blocks );
		if ( ! preview ) {
			return {
				index,
				change,
				text,
				group: 'pricing',
				cost: 0,
				priceNote: null,
				reason: null,
				coordinatorNote: null,
			};
		}
		const group: LineGroup = preview.invalid
			? 'refused'
			: preview.approval_level === 'st'
				? 'waits'
				: 'applies';
		return {
			index,
			change,
			text,
			group,
			cost: preview.invalid ? 0 : preview.xp_cost,
			priceNote: previewPriceLabel( preview ),
			reason: group === 'waits' ? preview.approval_reason : null,
			coordinatorNote:
				group === 'waits'
					? coordinatorNote( preview.coordinator )
					: null,
		};
	} );
}

export interface CartTotals {
	count: number;
	/**
	 * The XP every priced line costs together.
	 */
	cost: number;
	/**
	 * What the lines that apply now cost.
	 */
	appliesCost: number;
	/**
	 * What the lines that wait for a Storyteller will hold.
	 */
	waitsCost: number;
	refused: number;
	pricing: number;
}

export function cartTotals( lines: CartLine[] ): CartTotals {
	const totals: CartTotals = {
		count: lines.length,
		cost: 0,
		appliesCost: 0,
		waitsCost: 0,
		refused: 0,
		pricing: 0,
	};
	for ( const line of lines ) {
		if ( line.group === 'refused' ) {
			totals.refused++;
		} else if ( line.group === 'pricing' ) {
			totals.pricing++;
		} else {
			totals.cost += line.cost;
			if ( line.group === 'applies' ) {
				totals.appliesCost += Math.max( 0, line.cost );
			} else {
				totals.waitsCost += Math.max( 0, line.cost );
			}
		}
	}
	return totals;
}

export interface CartMeter {
	lead: string;
	/**
	 * What is left after the cart, or how far over it runs; null with an empty cart.
	 */
	tail: string | null;
	over: boolean;
}

/**
 * The meter's line: the XP free to spend with an empty cart, otherwise the cart's size and price and what it leaves.
 *
 * @param count Lines in the cart.
 * @param cost  What they cost together.
 * @param left  What is free to spend once they are paid for: the XP free to spend now with an empty cart.
 */
export function cartMeter(
	count: number,
	cost: number,
	left: number
): CartMeter {
	if ( count === 0 ) {
		return {
			lead: sprintf(
				/* translators: %d: experience points the character can spend now */
				__( '%d XP to spend', 'beyond-elysium' ),
				Math.max( 0, left )
			),
			tail: null,
			over: false,
		};
	}
	const lead = sprintf(
		/* translators: 1: how many changes are in the cart, 2: what they cost in experience points */
		__( 'Cart: %1$d · %2$d XP', 'beyond-elysium' ),
		count,
		cost
	);
	const over = left < 0;
	return {
		lead,
		tail: over
			? sprintf(
					/* translators: %d: how many XP the cart costs beyond what is free to spend */
					__( '%d XP over', 'beyond-elysium' ),
					-left
				)
			: sprintf(
					/* translators: %d: XP free to spend after the cart is paid for */
					__( '%d left', 'beyond-elysium' ),
					left
				),
		over,
	};
}

/**
 * The chip in the header: the cart's size and price, or null with an empty cart.
 */
export function cartChip( count: number, cost: number ): string | null {
	return count === 0
		? null
		: sprintf(
				/* translators: 1: how many changes are in the cart, 2: what they cost in experience points */
				__( 'Cart: %1$d · %2$d XP', 'beyond-elysium' ),
				count,
				cost
			);
}

/**
 * What Review says the waiting lines will hold, or null when none will.
 */
export function heldLine( waitsCost: number ): string | null {
	return waitsCost > 0
		? sprintf(
				/* translators: %d: experience points held until a Storyteller decides */
				__(
					'%d XP held until a Storyteller decides',
					'beyond-elysium'
				),
				waitsCost
			)
		: null;
}

/**
 * The sentence after Submit: how many lines applied at once and how many wait, with the XP they hold.
 */
export function confirmationLine(
	applied: number,
	waiting: number,
	held: number
): string {
	const parts: string[] = [ __( 'Sent.', 'beyond-elysium' ) ];
	if ( applied > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: how many changes applied at once */
				__( '%d applied now.', 'beyond-elysium' ),
				applied
			)
		);
	}
	if ( waiting > 0 ) {
		parts.push(
			held > 0
				? sprintf(
						/* translators: 1: how many changes wait for a Storyteller, 2: the experience points they hold */
						_n(
							'%1$d is waiting for a Storyteller, with %2$d XP held.',
							'%1$d are waiting for a Storyteller, with %2$d XP held.',
							waiting,
							'beyond-elysium'
						),
						waiting,
						held
					)
				: sprintf(
						/* translators: %d: how many changes wait for a Storyteller */
						_n(
							'%d is waiting for a Storyteller.',
							'%d are waiting for a Storyteller.',
							waiting,
							'beyond-elysium'
						),
						waiting
					)
		);
	}
	return parts.join( ' ' );
}

export interface CartView {
	lines: CartLine[];
	totals: CartTotals;
	/**
	 * XP free to spend before the cart: unspent XP less what waiting changes hold.
	 */
	free: number;
	/**
	 * XP free to spend once the cart is paid for.
	 */
	left: number;
	/**
	 * Whether the cart costs more than is free to spend; never for a Storyteller, who may go negative.
	 */
	over: boolean;
	meter: CartMeter;
	chip: string | null;
}

/**
 * Everything the meter, the header chip and Review show, from the pending changes, the server's pricing of them and
 * the character.
 */
export function cartView(
	changes: ChangeRequest[],
	preview: PreviewChangesResponse | null,
	character: { xp_unspent: number; xp_held?: number } | null,
	isManager: boolean,
	blocks?: Record< string, SchemaBlock >
): CartView {
	// A pricing that does not match the list was made for an older one.
	const current = preview?.results.length === changes.length ? preview : null;
	const lines = cartLines( changes, current?.results, blocks );
	const totals = cartTotals( lines );
	const free =
		Number( character?.xp_unspent ?? 0 ) -
		( isManager ? 0 : Number( character?.xp_held ?? 0 ) );
	const left = current ? current.running_xp_unspent : free - totals.cost;
	return {
		lines,
		totals,
		free,
		left,
		over: ! isManager && left < 0,
		meter: cartMeter(
			changes.length,
			totals.cost,
			changes.length === 0 ? free : left
		),
		chip: cartChip( changes.length, totals.cost ),
	};
}

/**
 * What the cart says about blocks the server changed since the player added them.
 */
export function droppedMessage( names: string[] ): string | null {
	return names.length === 0
		? null
		: sprintf(
				/* translators: %s: the names of the sheet sections that changed, separated by commas */
				__(
					'The sheet changed since you added this: %s. Add it again if you still want it.',
					'beyond-elysium'
				),
				names.join( ', ' )
			);
}
