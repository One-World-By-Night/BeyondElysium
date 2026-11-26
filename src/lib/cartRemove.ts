/**
 * Takes one line out of the cart: the rows that line changed go back as the sheet holds them and nothing else moves.
 */
import { computeChanges } from './computeChanges';
import { draftDiffersFrom } from './draftStorage';
import type { SchemaBlock } from '../types';
import type { ChangeRequest, SheetData } from '../types/character';

export interface RemoveResult {
	sheet: SheetData;
	/**
	 * False when no single reversion removed that line alone, so the whole block went back as the sheet holds it.
	 */
	exact: boolean;
}

type Row = Record< string, unknown >;

function copy< T >( value: T ): T {
	return JSON.parse( JSON.stringify( value ) );
}

/**
 * Whether two lists of changes hold the same changes, in any order.
 */
function sameChanges( a: ChangeRequest[], b: ChangeRequest[] ): boolean {
	if ( a.length !== b.length ) {
		return false;
	}
	const keys = ( list: ChangeRequest[] ) =>
		list.map( ( change ) => JSON.stringify( change ) ).sort();
	return ! draftDiffersFrom( { v: keys( a ) }, { v: keys( b ) } );
}

function named( row: unknown, name: unknown ): boolean {
	return (
		typeof row === 'object' && row !== null && ( row as Row ).name === name
	);
}

/**
 * Every way of putting back the rows one change touched in a list block.
 */
function listCandidates(
	change: ChangeRequest,
	original: Row[],
	edited: Row[]
): Row[][] {
	const data = change.change_data as {
		trait?: { name?: unknown };
		previous?: { name?: unknown };
	};
	const candidates: Row[][] = [];

	if ( change.change_type === 'add_trait' ) {
		edited.forEach( ( row, at ) => {
			if ( named( row, data.trait?.name ) ) {
				candidates.push( edited.filter( ( _, i ) => i !== at ) );
			}
		} );
	}

	if ( change.change_type === 'remove_trait' ) {
		original.forEach( ( row, k ) => {
			if ( ! named( row, data.trait?.name ) ) {
				return;
			}
			edited.forEach( ( held, at ) => {
				if ( named( held, row.name ) && held._removed === true ) {
					const next = [ ...edited ];
					next[ at ] = copy( row );
					candidates.push( next );
				}
			} );
			const next = [ ...edited ];
			next.splice( Math.min( k, next.length ), 0, copy( row ) );
			candidates.push( next );
		} );
	}

	if ( change.change_type === 'modify_trait' ) {
		const was = data.previous?.name ?? data.trait?.name;
		original.forEach( ( row ) => {
			if ( ! named( row, was ) ) {
				return;
			}
			edited.forEach( ( held, at ) => {
				if ( named( held, data.trait?.name ) || named( held, was ) ) {
					const next = [ ...edited ];
					next[ at ] = copy( row );
					candidates.push( next );
				}
			} );
		} );
	}

	return candidates;
}

/**
 * Every way of putting back the pools or fields one change touched in a keyed block.
 */
function keyedCandidates(
	change: ChangeRequest,
	original: Row,
	edited: Row
): Row[] {
	const data = change.change_data as {
		values?: Row;
		fields?: Row;
	};
	const next: Row = { ...edited };
	for ( const key of Object.keys( data.values ?? data.fields ?? {} ) ) {
		if ( original[ key ] === undefined ) {
			delete next[ key ];
		} else {
			next[ key ] = copy( original[ key ] );
		}
	}
	return [ next ];
}

/**
 * The edited sheet without cart line `index` (the order `computeChanges()` lists them in).
 */
export function removeLine(
	original: SheetData,
	edited: SheetData,
	blocks: Record< string, SchemaBlock >,
	index: number
): RemoveResult {
	const lines = computeChanges( original, edited, blocks );
	const target = lines[ index ];
	if ( ! target ) {
		return { sheet: edited, exact: true };
	}
	const rest = lines.filter( ( _, i ) => i !== index );
	const slug = target.category;
	const sectionType = blocks[ slug ]?.section_type;

	let candidates: unknown[] = [];
	if ( sectionType === 'trait_list' || sectionType === 'tiered_power' ) {
		candidates = listCandidates(
			target,
			( original[ slug ] as Row[] | undefined ) ?? [],
			( edited[ slug ] as Row[] | undefined ) ?? []
		);
	} else if (
		sectionType === 'resource_pool' ||
		sectionType === 'identity_field'
	) {
		candidates = keyedCandidates(
			target,
			( original[ slug ] as Row | undefined ) ?? {},
			( edited[ slug ] as Row | undefined ) ?? {}
		);
	}

	for ( const candidate of candidates ) {
		const sheet = { ...edited, [ slug ]: candidate };
		if ( sameChanges( computeChanges( original, sheet, blocks ), rest ) ) {
			return { sheet, exact: true };
		}
	}

	const whole = { ...edited };
	if ( original[ slug ] === undefined ) {
		delete whole[ slug ];
	} else {
		whole[ slug ] = copy( original[ slug ] );
	}
	return { sheet: whole, exact: false };
}
