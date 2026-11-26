/**
 * What the trait panel says about one held trait: the change a raise by one would be, the catalog's entry for it, and
 * this character's history with it.
 */
import { describeChange } from './describeChange';
import { localizedItemName } from './localizeName';
import { __, sprintf } from '@wordpress/i18n';
import type { HeldTrait } from './heldTraits';
import { addedRowChange } from './optionPrice';
import type {
	CatalogDescription,
	Prerequisite,
	SchemaBlock,
	TieredPower,
	TieredPowerDefinition,
	TraitListDefinition,
	TraitListItem,
} from '../types';
import type { ChangeRequest, CharacterChange } from '../types/character';

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
 * The one change that raises a held trait by one, or adds it with its first dot or level when it is not held: null when
 * the trait cannot be raised that way (an atomic item, a row with no count, a power held as picks).
 */
export function nextChange(
	held: Pick< HeldTrait, 'blockSlug' | 'name' | 'powerName' | 'sectionType' >,
	block: SchemaBlock,
	saved: unknown
): ChangeRequest | null {
	if ( block.section_type === 'trait_list' ) {
		if ( ( block.definition as TraitListDefinition ).atomic ) {
			return null;
		}
		const rows = rowsOf( saved );
		const at = heldRowIndex( rows, held );
		let next: Row[];
		if ( at === -1 ) {
			next = [ ...rows, { name: held.name, count: 1 } ];
		} else {
			const count = Number( rows[ at ].count );
			if ( ! Number.isFinite( count ) ) {
				return null;
			}
			next = rows.map( ( row, i ) =>
				i === at ? { ...row, count: Math.trunc( count ) + 1 } : row
			);
		}
		return addedRowChange(
			'trait_list',
			held.blockSlug,
			block.definition,
			rows,
			next
		);
	}

	if ( block.section_type === 'tiered_power' ) {
		if ( held.powerName ) {
			return null;
		}
		const rows = rowsOf( saved );
		const at = heldRowIndex( rows, held );
		let next: Row[];
		if ( at === -1 ) {
			next = [ ...rows, { name: held.name, level: 1 } ];
		} else {
			const level = Number( rows[ at ].level );
			if ( ! Number.isFinite( level ) ) {
				return null;
			}
			next = rows.map( ( row, i ) =>
				i === at ? { ...row, level: Math.trunc( level ) + 1 } : row
			);
		}
		return addedRowChange(
			'tiered_power',
			held.blockSlug,
			block.definition,
			rows,
			next
		);
	}

	return null;
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
