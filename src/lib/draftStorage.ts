/**
 * Saves, loads, and clears a per-character sheet-data draft in the browser's `localStorage`, keyed by character id,
 * as a local backup of in-progress edits.
 */

import type { SheetData } from '../types/character';

const PREFIX = 'be-draft-';

export interface StoredDraft {
	sheetData: SheetData;
	savedAt: number;
}

function storageKey( characterId: number ): string {
	return `${ PREFIX }${ characterId }`;
}

/**
 * Serializes a character's sheet data together with the current timestamp and writes it to `localStorage` under a
 * per-character key.
 */
export function saveDraft( characterId: number, sheetData: SheetData ): void {
	try {
		const payload: StoredDraft = { sheetData, savedAt: Date.now() };
		window.localStorage.setItem(
			storageKey( characterId ),
			JSON.stringify( payload )
		);
	} catch {
		// Autosave is a convenience, never something a real edit should be blocked on.
	}
}

/**
 * Reads the stored draft for a character from `localStorage` and parses it as JSON, validating that the result has
 * the expected `sheetData`/`savedAt` shape before returning it.
 *
 * @return The stored draft, or null if none exists, it's corrupt, or storage itself is
 *         unavailable - every one of those is treated identically: nothing to restore.
 */
export function loadDraft( characterId: number ): StoredDraft | null {
	try {
		const raw = window.localStorage.getItem( storageKey( characterId ) );
		if ( ! raw ) {
			return null;
		}
		const parsed = JSON.parse( raw ) as Partial< StoredDraft >;
		return parsed &&
			typeof parsed === 'object' &&
			parsed.sheetData &&
			typeof parsed.savedAt === 'number'
			? ( parsed as StoredDraft )
			: null;
	} catch {
		return null;
	}
}

/**
 * Removes the stored draft for a character from `localStorage`, if one exists.
 */
export function clearDraft( characterId: number ): void {
	try {
		window.localStorage.removeItem( storageKey( characterId ) );
	} catch {}
}

/**
 * Serializes a value to JSON with object keys sorted recursively.
 */
function stableStringify( value: unknown ): string {
	if ( Array.isArray( value ) ) {
		return `[${ value.map( stableStringify ).join( ',' ) }]`;
	}
	if ( value !== null && typeof value === 'object' ) {
		const keys = Object.keys( value as Record< string, unknown > ).sort();
		const entries = keys.map(
			( k ) =>
				`${ JSON.stringify( k ) }:${ stableStringify(
					( value as Record< string, unknown > )[ k ]
				) }`
		);
		return `{${ entries.join( ',' ) }}`;
	}
	return JSON.stringify( value );
}

/**
 * Deep-equality check between a draft and the current sheet data, comparing them as JSON-serializable structures
 * regardless of object-key order.
 */
export function draftDiffersFrom(
	draft: SheetData,
	sheetData: SheetData
): boolean {
	return stableStringify( draft ) !== stableStringify( sheetData );
}
