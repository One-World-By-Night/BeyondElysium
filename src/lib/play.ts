/**
 * What the Play view shows from a character's sheet data: its pools, its health track and what it lost tonight.
 */
import { resolvePoolName } from './resolveCrossBlockRef';
import { localizedPoolLabel } from './localizeName';
import type { ResourcePoolDefinition, SchemaBlock } from '../types';

export type WoundType = 'bashing' | 'lethal' | 'aggravated';

export interface Wounds {
	bashing: number;
	lethal: number;
	aggravated: number;
}

export interface PoolRow {
	blockSlug: string;
	poolName: string;
	label: string;
	permanent: number;
	temporary: number;
	/**
	 * Whether the creature type's books spend this pool permanently.
	 */
	permanentSpend: boolean;
}

function isPair(
	value: unknown
): value is { permanent: number; temporary: number } {
	if ( typeof value !== 'object' || value === null ) {
		return false;
	}
	const pair = value as Record< string, unknown >;
	return (
		typeof pair.permanent === 'number' && typeof pair.temporary === 'number'
	);
}

/**
 * The pools a character holds, in the template's order: each resource-pool block among `blockOrder` and every pool of
 * it the sheet stores a permanent and a temporary rating for.
 */
export function poolRows(
	sheetData: Record< string, unknown >,
	blocks: Record< string, SchemaBlock >,
	blockOrder: string[]
): PoolRow[] {
	const rows: PoolRow[] = [];
	for ( const slug of blockOrder ) {
		const block = blocks[ slug ];
		if ( ! block || block.section_type !== 'resource_pool' ) {
			continue;
		}
		const held = ( sheetData[ slug ] ?? {} ) as Record< string, unknown >;
		for ( const pool of ( block.definition as ResourcePoolDefinition )
			.pools ) {
			const value = held[ pool.name ];
			if ( ! isPair( value ) ) {
				continue;
			}
			const resolved = resolvePoolName( pool, sheetData );
			rows.push( {
				blockSlug: slug,
				poolName: pool.name,
				label:
					resolved === pool.name
						? localizedPoolLabel( pool )
						: resolved,
				permanent: value.permanent,
				temporary: value.temporary,
				permanentSpend: pool.permanent_spend === true,
			} );
		}
	}
	return rows;
}

/**
 * The temporary points of a pool can be spent while any remain, and regained up to its permanent rating.
 */
export function canSpend( row: Pick< PoolRow, 'temporary' > ): boolean {
	return row.temporary > 0;
}

export function canRegain(
	row: Pick< PoolRow, 'temporary' | 'permanent' >
): boolean {
	return row.temporary < row.permanent;
}

/**
 * A permanent spend needs a rating to give up.
 */
export function canSpendPermanent(
	row: Pick< PoolRow, 'permanent' | 'permanentSpend' >
): boolean {
	return row.permanentSpend && row.permanent > 0;
}

/**
 * How many permanent spends wait for a Storyteller on each pool, keyed `block.Pool`, from a character's pending
 * changes.
 */
export function waitingSpends(
	pending: Array< { change_type: string; change_data: unknown } >
): Record< string, number > {
	const counts: Record< string, number > = {};
	for ( const change of pending ) {
		if ( change.change_type !== 'pool_permanent_spent' ) {
			continue;
		}
		const data = ( change.change_data ?? {} ) as {
			block_slug?: unknown;
			pool_name?: unknown;
		};
		if (
			typeof data.block_slug === 'string' &&
			typeof data.pool_name === 'string'
		) {
			const key = `${ data.block_slug }.${ data.pool_name }`;
			counts[ key ] = ( counts[ key ] ?? 0 ) + 1;
		}
	}
	return counts;
}

export interface HealthLevel {
	name: string;
	count: number;
}

/**
 * The health levels a character holds, in order, from its Health section's stored rows; a row with no count is one
 * level and a row of none is left out.
 */
export function healthLevels( rows: unknown ): HealthLevel[] {
	if ( ! Array.isArray( rows ) ) {
		return [];
	}
	const levels: HealthLevel[] = [];
	for ( const row of rows ) {
		if ( typeof row !== 'object' || row === null ) {
			continue;
		}
		const held = row as { name?: unknown; count?: unknown };
		if ( typeof held.name !== 'string' || held.name === '' ) {
			continue;
		}
		const count =
			typeof held.count === 'number' || typeof held.count === 'string'
				? Math.trunc( Number( held.count ) )
				: 1;
		if ( Number.isFinite( count ) && count > 0 ) {
			levels.push( { name: held.name, count } );
		}
	}
	return levels;
}

export type Damage = WoundType | null;

export interface HealthBox {
	level: string;
	damage: Damage;
}

export interface HealthTrack {
	boxes: HealthBox[];
	/**
	 * The level of the first box still undamaged: where the character stands.
	 */
	reached: string;
	/**
	 * Damage past the last box.
	 */
	beyond: number;
}

/**
 * Boxes for a character's health levels, filled from the first with aggravated damage, then lethal, then bashing.
 */
export function healthTrack(
	levels: HealthLevel[],
	wounds: Wounds
): HealthTrack {
	const boxes: HealthBox[] = [];
	for ( const level of levels ) {
		for ( let i = 0; i < level.count; i++ ) {
			boxes.push( { level: level.name, damage: null } );
		}
	}
	const order: Array< [ WoundType, number ] > = [
		[ 'aggravated', wounds.aggravated ],
		[ 'lethal', wounds.lethal ],
		[ 'bashing', wounds.bashing ],
	];
	let next = 0;
	let total = 0;
	for ( const [ type, amount ] of order ) {
		const taken = Math.max( 0, Math.trunc( amount ) );
		total += taken;
		for ( let i = 0; i < taken && next < boxes.length; i++ ) {
			boxes[ next++ ].damage = type;
		}
	}
	return {
		boxes,
		reached:
			boxes.length === 0
				? ''
				: boxes[ Math.min( total, boxes.length - 1 ) ].level,
		beyond: Math.max( 0, total - boxes.length ),
	};
}

/**
 * A character's wounds by type from its sheet data, each zero when never recorded.
 */
export function woundsFrom( sheetData: Record< string, unknown > ): Wounds {
	const state = ( sheetData._wound_state ?? {} ) as Record< string, unknown >;
	const read = ( type: WoundType ) =>
		Math.max( 0, Math.trunc( Number( state[ type ] ?? 0 ) ) || 0 );
	return {
		bashing: read( 'bashing' ),
		lethal: read( 'lethal' ),
		aggravated: read( 'aggravated' ),
	};
}

export interface NightLosses {
	lostTraits: string[];
	expended: Array< { name: string; levels: number } >;
}

/**
 * What a character has lost or expended tonight, from its sheet data.
 */
export function nightLossesFrom(
	sheetData: Record< string, unknown >
): NightLosses {
	const state = ( sheetData._night_losses ?? {} ) as Record<
		string,
		unknown
	>;
	const lost = Array.isArray( state.lost_traits ) ? state.lost_traits : [];
	const expended = Array.isArray( state.expended_abilities )
		? state.expended_abilities
		: [];
	const named = ( entry: unknown ): entry is { name: string } =>
		typeof entry === 'object' &&
		entry !== null &&
		typeof ( entry as { name?: unknown } ).name === 'string';
	return {
		lostTraits: lost.filter( named ).map( ( entry ) => entry.name ),
		expended: expended.filter( named ).map( ( entry ) => ( {
			name: entry.name,
			levels: Math.max(
				1,
				Math.trunc(
					Number( ( entry as { levels?: unknown } ).levels )
				) || 1
			),
		} ) ),
	};
}
