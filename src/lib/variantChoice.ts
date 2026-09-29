/**
 * A chronicle's choice of book variants for one base block: at most one variant replacing it, and any number adding to
 * it, sent replacing one first and then the adding ones in the book's order.
 */
import type { CatalogVariant } from '../types';

/**
 * The chosen ids in the order they are folded in: the replacing one first, then each adding one as the book lists it.
 */
export function orderedChoice(
	variants: CatalogVariant[],
	chosen: string[]
): string[] {
	const picked = new Set( chosen );
	const replacing = variants.filter(
		( variant ) => variant.mode === 'replace' && picked.has( variant.id )
	);
	const adding = variants.filter(
		( variant ) => variant.mode === 'add' && picked.has( variant.id )
	);
	return [ ...replacing.slice( 0, 1 ), ...adding ].map(
		( variant ) => variant.id
	);
}

/**
 * The choice with one adding variant ticked or unticked.
 */
export function toggleAdding(
	variants: CatalogVariant[],
	chosen: string[],
	id: string
): string[] {
	const next = chosen.includes( id )
		? chosen.filter( ( value ) => value !== id )
		: [ ...chosen, id ];
	return orderedChoice( variants, next );
}

/**
 * The choice with its replacing variant set to one, or to none with an empty id.
 */
export function chooseReplacing(
	variants: CatalogVariant[],
	chosen: string[],
	id: string
): string[] {
	const adding = chosen.filter(
		( value ) =>
			variants.find( ( variant ) => variant.id === value )?.mode === 'add'
	);
	return orderedChoice( variants, id === '' ? adding : [ id, ...adding ] );
}

/**
 * Whether two choices name the same variants.
 */
export function sameChoice( a: string[], b: string[] ): boolean {
	return (
		a.length === b.length &&
		[ ...a ].sort().join( '\n' ) === [ ...b ].sort().join( '\n' )
	);
}
