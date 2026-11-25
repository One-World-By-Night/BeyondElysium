/**
 * The price a picker shows beside an option: the hint text, the requests that fetch it, and the one change a pick
 * would submit.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { BlockDefinition, SchemaBlock, SectionType } from '../types';
import type {
	ChangePreviewResult,
	ChangeRequest,
	SheetData,
} from '../types/character';
import { computeChanges } from './computeChanges';

/**
 * The most options priced in one request.
 */
export const PRICE_BATCH_SIZE = 40;

/**
 * Whether a picker shows prices: true for a player adding to a saved character's editable list that costs XP.
 */
export function pricingEnabled( where: {
	gameSlug?: string;
	characterId?: number;
	isManager?: boolean;
	readOnly?: boolean;
	creating?: boolean;
	unpriced?: boolean;
} ): boolean {
	return (
		!! where.gameSlug &&
		!! where.characterId &&
		! where.isManager &&
		! where.readOnly &&
		! where.creating &&
		! where.unpriced
	);
}

/**
 * The text beside an option for its quoted price, or null when the server would refuse the pick. `variable` is true
 * for an item whose cost the player chooses, which the quote prices at its lowest.
 */
export function priceHint(
	quote: ChangePreviewResult,
	variable: boolean
): string | null {
	if ( quote.invalid ) {
		return null;
	}
	if ( quote.priced === false ) {
		return __( 'Storyteller prices it', 'beyond-elysium' );
	}
	const price = variable
		? sprintf(
				/* translators: %d: the lowest XP an item can be bought for */
				__( 'from %d XP', 'beyond-elysium' ),
				quote.xp_cost
			)
		: sprintf(
				/* translators: %d: the XP cost of an item */
				__( '%d XP', 'beyond-elysium' ),
				quote.xp_cost
			);
	return quote.approval_level === 'st'
		? `${ price } · ${ __( 'Storyteller', 'beyond-elysium' ) }`
		: price;
}

/**
 * The values still to price, each once and in the order given, split into requests of at most `size`.
 */
export function priceBatches(
	values: ReadonlyArray< string >,
	known: ReadonlySet< string >,
	size: number = PRICE_BATCH_SIZE
): string[][] {
	const wanted = [ ...new Set( values ) ].filter(
		( value ) => ! known.has( value )
	);
	const batches: string[][] = [];
	for ( let i = 0; i < wanted.length; i += size ) {
		batches.push( wanted.slice( i, i + size ) );
	}
	return batches;
}

/**
 * The one change that turns a block's saved data into `next`, as the editor would submit it, or null when the two
 * differ by anything else or the block type has no add.
 */
export function addedRowChange(
	sectionType: SectionType,
	blockSlug: string,
	definition: BlockDefinition,
	saved: unknown,
	next: unknown
): ChangeRequest | null {
	if ( sectionType !== 'trait_list' && sectionType !== 'tiered_power' ) {
		return null;
	}
	const changes = computeChanges(
		{ [ blockSlug ]: saved } as SheetData,
		{ [ blockSlug ]: next } as SheetData,
		{
			[ blockSlug ]: {
				section_type: sectionType,
				definition,
			} as unknown as SchemaBlock,
		}
	);
	return changes.length === 1 ? changes[ 0 ] : null;
}
