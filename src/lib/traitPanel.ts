/**
 * What the trait panel says about one held trait: the change a raise by one would be, the catalog's entry for it, and
 * this character's history with it.
 */
import { describeChange } from './describeChange';
import { localizedItemName } from './localizeName';
import { __, sprintf } from '@wordpress/i18n';
import type { HeldTrait } from './heldTraits';
import { bookOf } from './catalogBook';
import { addedRowChange } from './optionPrice';
import { allLevels } from './powerLevels';
import { computeChanges } from './computeChanges';
import type {
	CatalogDescription,
	PowerLevel,
	Prerequisite,
	SchemaBlock,
	TieredPower,
	TieredPowerDefinition,
	TraitListDefinition,
	TraitListItem,
} from '../types';
import type {
	ChangeRequest,
	CharacterChange,
	SheetData,
} from '../types/character';

type Row = Record< string, unknown >;

function rowsOf( saved: unknown ): Row[] {
	return Array.isArray( saved )
		? saved.filter(
				( row ): row is Row => typeof row === 'object' && row !== null
			)
		: [];
}

function heldRowIndex(
	rows: Row[],
	held: Pick< HeldTrait, 'name' | 'powerName' >
): number {
	return rows.findIndex(
		( row ) =>
			row.name === held.name &&
			( row.power_name ?? undefined ) === ( held.powerName ?? undefined )
	);
}

/**
 * The rows of a block after one more dot or level of a held trait, or the trait added with its first: null when the
 * trait cannot be raised that way (an atomic item, a row with no count, a power held as picks).
 */
export function nextRows(
	held: Pick< HeldTrait, 'blockSlug' | 'name' | 'powerName' | 'sectionType' >,
	block: SchemaBlock,
	value: unknown
): Row[] | null {
	if ( block.section_type === 'trait_list' ) {
		if ( ( block.definition as TraitListDefinition ).atomic ) {
			return null;
		}
		const rows = rowsOf( value );
		const at = heldRowIndex( rows, held );
		if ( at === -1 ) {
			return [ ...rows, { name: held.name, count: 1 } ];
		}
		const count = Number( rows[ at ].count );
		if ( ! Number.isFinite( count ) ) {
			return null;
		}
		return rows.map( ( row, i ) =>
			i === at ? { ...row, count: Math.trunc( count ) + 1 } : row
		);
	}

	if ( block.section_type === 'tiered_power' ) {
		if ( held.powerName ) {
			return null;
		}
		const rows = rowsOf( value );
		const at = heldRowIndex( rows, held );
		if ( at === -1 ) {
			return [ ...rows, { name: held.name, level: 1 } ];
		}
		const level = Number( rows[ at ].level );
		if ( ! Number.isFinite( level ) ) {
			return null;
		}
		return rows.map( ( row, i ) =>
			i === at ? { ...row, level: Math.trunc( level ) + 1 } : row
		);
	}

	return null;
}

/**
 * The one change that raises a held trait by one, or adds it with its first dot or level when it is not held: null when
 * the trait cannot be raised that way.
 */
export function nextChange(
	held: Pick< HeldTrait, 'blockSlug' | 'name' | 'powerName' | 'sectionType' >,
	block: SchemaBlock,
	saved: unknown
): ChangeRequest | null {
	const next = nextRows( held, block, saved );
	return next === null
		? null
		: addedRowChange(
				block.section_type,
				held.blockSlug,
				block.definition,
				rowsOf( saved ),
				next
			);
}

/**
 * What Add to cart does for a held trait, against what the server holds and what the cart already makes of the block.
 */
export interface CartStep {
	/**
	 * The block's rows once one more is added: what goes into the cart's draft.
	 */
	rows: Row[];
	/**
	 * The line the cart will hold for this trait after the step, as the server is asked to price it.
	 */
	next: ChangeRequest;
	/**
	 * The line the cart holds for this trait now; null when the draft has none.
	 */
	current: ChangeRequest | null;
	/**
	 * The dots or level the trait will stand at.
	 */
	rating: number;
	/**
	 * Whether the trait is already held as the sheet has it.
	 */
	held: boolean;
}

/**
 * The step Add to cart takes: one more dot or level on top of the draft, so a second tap raises one more. Null when the
 * trait cannot be raised that way.
 */
export function cartStep(
	held: Pick< HeldTrait, 'blockSlug' | 'name' | 'powerName' | 'sectionType' >,
	block: SchemaBlock,
	saved: unknown,
	draft: unknown
): CartStep | null {
	const rows = nextRows( held, block, draft );
	if ( rows === null ) {
		return null;
	}
	const savedRows = rowsOf( saved );
	const lineFor = ( target: Row[] ): ChangeRequest | null => {
		const changes = computeChanges(
			{ [ held.blockSlug ]: savedRows } as SheetData,
			{ [ held.blockSlug ]: target } as SheetData,
			{ [ held.blockSlug ]: block }
		);
		return (
			changes.find( ( change ) => {
				const trait = ( change.change_data as { trait?: Row } ).trait;
				return (
					trait?.name === held.name &&
					( trait?.power_name ?? undefined ) ===
						( held.powerName ?? undefined )
				);
			} ) ?? null
		);
	};
	const next = lineFor( rows );
	if ( next === null ) {
		return null;
	}
	const at = heldRowIndex( rows, held );
	const rating = Number(
		block.section_type === 'tiered_power'
			? rows[ at ]?.level
			: rows[ at ]?.count
	);
	return {
		rows,
		next,
		current: lineFor( rowsOf( draft ) ),
		rating: Number.isFinite( rating ) ? rating : 1,
		held: heldRowIndex( savedRows, held ) !== -1,
	};
}

/**
 * The words on the Add to cart button: what it does for the trait and, once the server has priced it, what it costs.
 *
 * @param step  The step the button takes.
 * @param name  The trait's name as the sheet shows it.
 * @param power Whether the trait is a ladder power, whose rating reads as a level.
 * @param price The XP this one step costs; null before it is priced.
 */
export function cartButtonLabel(
	step: Pick< CartStep, 'held' | 'rating' >,
	name: string,
	power: boolean,
	price: number | null
): string {
	let text: string;
	if ( step.held ) {
		text = power
			? sprintf(
					/* translators: %d: the level a power will be raised to */
					__( 'Raise to level %d', 'beyond-elysium' ),
					step.rating
				)
			: sprintf(
					/* translators: %d: the dots a trait will be raised to */
					__( 'Raise to x%d', 'beyond-elysium' ),
					step.rating
				);
	} else {
		text = power
			? sprintf(
					/* translators: 1: the name of a power, 2: the level it is added at */
					__( 'Add %1$s level %2$d', 'beyond-elysium' ),
					name,
					step.rating
				)
			: sprintf(
					/* translators: 1: the name of a trait, 2: the dots it is added with */
					__( 'Add %1$s x%2$d', 'beyond-elysium' ),
					name,
					step.rating
				);
	}
	return price === null
		? text
		: sprintf(
				/* translators: 1: what the cart button does, 2: the XP the one step costs */
				__( '%1$s · %2$d XP', 'beyond-elysium' ),
				text,
				price
			);
}

/**
 * A held trait's name as the sheet shows it: the catalog's translated name where the site is Portuguese and one exists.
 */
export function traitLabel(
	block: SchemaBlock | undefined,
	name: string
): string {
	if ( block?.section_type !== 'trait_list' ) {
		return name;
	}
	const item = ( block.definition as TraitListDefinition ).items?.find(
		( entry: TraitListItem ) => entry.name === name
	);
	return item ? localizedItemName( item ) : name;
}

export interface CatalogEntry {
	description?: CatalogDescription;
	/**
	 * Where the chronicle's own note says the entry is described: its source and reference as entered.
	 */
	citation?: string;
	prerequisites?: Prerequisite[];
	/**
	 * The book the shipped catalog says the entry comes from, when it names one.
	 */
	book?: string | null;
	/**
	 * A power family's levels, ladder then picks.
	 */
	ladder?: PowerLevel[];
}

function citationOf( description: CatalogDescription | undefined ): string {
	return [ description?.source, description?.reference ]
		.filter( ( part ): part is string => !! part )
		.join( ', ' );
}

/**
 * The catalog's own entry for a held trait: a list's item, or a power's family.
 */
export function catalogEntry(
	block: SchemaBlock | undefined,
	held: Pick< HeldTrait, 'name' >
): CatalogEntry | null {
	if ( ! block ) {
		return null;
	}
	if ( block.section_type === 'trait_list' ) {
		const item = ( block.definition as TraitListDefinition ).items?.find(
			( entry: TraitListItem ) => entry.name === held.name
		);
		return item
			? {
					description: item.description,
					citation: citationOf( item.description ),
					prerequisites: item.prerequisites,
					book: bookOf( item.source ),
				}
			: null;
	}
	if ( block.section_type === 'tiered_power' ) {
		const family = (
			block.definition as TieredPowerDefinition
		 ).powers?.find( ( entry: TieredPower ) => entry.name === held.name );
		return family
			? {
					description: family.description,
					citation: citationOf( family.description ),
					book: bookOf( family.source ),
					ladder: allLevels( family ),
				}
			: null;
	}
	return null;
}

/**
 * What a raise costs, said without its approval: the XP, or that a Storyteller sets the price.
 */
export function priceText( result: {
	xp_cost: number;
	priced?: boolean;
} ): string {
	return result.priced === false
		? __( 'Storyteller prices it', 'beyond-elysium' )
		: sprintf(
				/* translators: %d: the XP cost of the next dot or level */
				__( '%d XP', 'beyond-elysium' ),
				result.xp_cost
			);
}

/**
 * A catalog description's text for a viewer: a Storyteller's own `[ST]` passages stay (marked), anyone else's go.
 */
export function visibleDescription(
	html: string,
	canManage: boolean,
	strip: ( text: string, start: string, end: string ) => string,
	highlight: ( text: string ) => string
): string {
	return canManage ? highlight( html ) : strip( html, '[ST]', '[/ST]' );
}

export interface HistoryLine {
	id: number;
	description: string;
	status: string;
	createdAt: string;
}

/**
 * This character's changes that name a trait, newest first, at most `limit`: its own row of a list, or its family of a
 * power.
 */
export function changesNaming(
	changes: CharacterChange[],
	held: Pick< HeldTrait, 'blockSlug' | 'name' >,
	limit = 5
): HistoryLine[] {
	const lines: HistoryLine[] = [];
	for ( const change of changes ) {
		const data = ( change.change_data ?? {} ) as {
			block_slug?: unknown;
			trait?: { name?: unknown };
		};
		if (
			data.block_slug !== held.blockSlug ||
			data.trait?.name !== held.name
		) {
			continue;
		}
		lines.push( {
			id: change.id,
			description: describeChange( change.change_type, data as never ),
			status: change.status,
			createdAt: change.submitted_at,
		} );
		if ( lines.length >= limit ) {
			break;
		}
	}
	return lines;
}
