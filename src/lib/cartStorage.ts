/**
 * The cart a player has on one character, kept in the browser's `localStorage`: the sheet blocks edited and, for each,
 * the value the server held when the edit was made. A block comes back only while the server still holds that value.
 */
import { draftDiffersFrom } from './draftStorage';
import type { SheetData } from '../types/character';

const PREFIX = 'be-cart-';

export interface CartBlock {
	/**
	 * What the server held for the block when it was first edited; null when the sheet held none.
	 */
	base: unknown;
	edited: unknown;
}

export interface StoredCart {
	blocks: Record< string, CartBlock >;
	savedAt: number;
}

export interface CartRestore {
	/**
	 * The server's sheet with every block that came back replaced by its edited value.
	 */
	sheet: SheetData;
	/**
	 * Blocks that came back, in the order stored.
	 */
	restored: string[];
	/**
	 * Blocks the server changed since they were edited, left as the server has them.
	 */
	dropped: string[];
}

function storageKey( characterId: number ): string {
	return `${ PREFIX }${ characterId }`;
}

function copy< T >( value: T ): T {
	return JSON.parse( JSON.stringify( value ) );
}

function held( sheet: SheetData, slug: string ): unknown {
	return sheet[ slug ] === undefined ? null : sheet[ slug ];
}

/**
 * The blocks an edited sheet changes from the sheet as the server holds it, each with the value it was made from.
 */
export function cartBlocks(
	original: SheetData,
	edited: SheetData
): Record< string, CartBlock > {
	const blocks: Record< string, CartBlock > = {};
	for ( const slug of Object.keys( edited ) ) {
		const now = held( edited, slug );
		const base = held( original, slug );
		if ( draftDiffersFrom( { v: now }, { v: base } ) ) {
			blocks[ slug ] = { base: copy( base ), edited: copy( now ) };
		}
	}
	return blocks;
}

/**
 * Writes the cart for a character, or removes it when the edited sheet changes nothing.
 */
export function saveCart(
	characterId: number,
	original: SheetData,
	edited: SheetData
): void {
	try {
		const blocks = cartBlocks( original, edited );
		if ( Object.keys( blocks ).length === 0 ) {
			window.localStorage.removeItem( storageKey( characterId ) );
			return;
		}
		const payload: StoredCart = { blocks, savedAt: Date.now() };
		window.localStorage.setItem(
			storageKey( characterId ),
			JSON.stringify( payload )
		);
	} catch {
		// The cart is a convenience kept on the device, never something an edit waits on.
	}
}

/**
 * The stored cart for a character; null when there is none, it is unreadable or storage is unavailable.
 */
export function loadCart( characterId: number ): StoredCart | null {
	try {
		const raw = window.localStorage.getItem( storageKey( characterId ) );
		if ( ! raw ) {
			return null;
		}
		const parsed = JSON.parse( raw ) as Partial< StoredCart >;
		if (
			! parsed ||
			typeof parsed !== 'object' ||
			typeof parsed.savedAt !== 'number' ||
			! parsed.blocks ||
			typeof parsed.blocks !== 'object' ||
			Array.isArray( parsed.blocks )
		) {
			return null;
		}
		const blocks: Record< string, CartBlock > = {};
		for ( const [ slug, block ] of Object.entries( parsed.blocks ) ) {
			if (
				block &&
				typeof block === 'object' &&
				'base' in block &&
				'edited' in block
			) {
				blocks[ slug ] = block as CartBlock;
			}
		}
		return Object.keys( blocks ).length > 0
			? { blocks, savedAt: parsed.savedAt }
			: null;
	} catch {
		return null;
	}
}

export function clearCart( characterId: number ): void {
	try {
		window.localStorage.removeItem( storageKey( characterId ) );
	} catch {}
}

/**
 * Applies a stored cart to the sheet as the server holds it now: a block whose server value still equals the value it
 * was edited from comes back; any other is dropped and named.
 */
export function restoreCart(
	cart: StoredCart,
	server: SheetData
): CartRestore {
	const sheet = copy( server );
	const restored: string[] = [];
	const dropped: string[] = [];
	for ( const [ slug, block ] of Object.entries( cart.blocks ) ) {
		if (
			draftDiffersFrom( { v: block.base }, { v: held( server, slug ) } )
		) {
			dropped.push( slug );
			continue;
		}
		sheet[ slug ] = copy( block.edited );
		restored.push( slug );
	}
	return { sheet, restored, dropped };
}
