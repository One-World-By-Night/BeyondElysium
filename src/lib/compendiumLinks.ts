/**
 * Where the compendium shows one entry, and what its address asks it to open.
 */
import { PLAYER_TABS, playerTabUrl } from './pluginPages';

export interface CompendiumTarget {
	stack: string;
	block: string;
	entry: string;
	characterId: number | null;
}

/**
 * The address that opens the compendium on one entry of one section, priced for a character when there is one.
 */
export function compendiumHref(
	gameSlug: string,
	stackSlug: string,
	blockSlug: string,
	entry: string,
	characterId: number | null
): string {
	const parts = [
		`game_slug=${ encodeURIComponent( gameSlug ) }`,
		`stack=${ encodeURIComponent( stackSlug ) }`,
		`block=${ encodeURIComponent( blockSlug ) }`,
		`entry=${ encodeURIComponent( entry ) }`,
	];
	if ( characterId !== null ) {
		parts.push( `character_id=${ characterId }` );
	}
	return `${ playerTabUrl( PLAYER_TABS.compendium ) }&${ parts.join( '&' ) }`;
}

/**
 * What an address asks the compendium to open; null when it asks for nothing.
 */
export function compendiumTarget( search: string ): CompendiumTarget | null {
	const query = new URLSearchParams( search );
	const stack = query.get( 'stack' );
	const block = query.get( 'block' );
	const entry = query.get( 'entry' );
	if ( ! stack || ! block || ! entry ) {
		return null;
	}
	const id = Number( query.get( 'character_id' ) );
	return {
		stack,
		block,
		entry,
		characterId: Number.isFinite( id ) && id > 0 ? id : null,
	};
}
