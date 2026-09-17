/**
 * A single, player-persisted preference for how a `count_is_cost`-flagged trait_list
 * block (Combo Disciplines) displays a held entry's stored count: dots (default) or a
 * plain "N XP" number - that stored count is a flat XP cost for this block, not an
 * ordinary rating, so drawing it as dots reads as an unreadable wall of pips once the
 * cost climbs past a few points (1.1.0 D3). Shared live across every mounted
 * `TraitListEditor` instance on the page at once via a small module-level listener set,
 * matching `powerDisplayMode.ts`'s own established shape exactly.
 */
import { useEffect, useState } from '@wordpress/element';

const STORAGE_KEY = 'be-cost-display-mode';
const listeners = new Set< ( numbers: boolean ) => void >();

/** Reads the stored preference. Exported for testing the storage logic without a hook-rendering dependency. */
export function readStoredCostDisplayMode(): boolean {
	try {
		return window.localStorage.getItem( STORAGE_KEY ) === 'numbers';
	} catch {
		return false;
	}
}

/** Writes the preference. Any storage failure - a full quota, private browsing - is caught and ignored. */
export function writeStoredCostDisplayMode( numbers: boolean ): void {
	try {
		window.localStorage.setItem(
			STORAGE_KEY,
			numbers ? 'numbers' : 'dots'
		);
	} catch {
		// A per-viewer convenience, never something a real toggle should be blocked on.
	}
}

/**
 * Returns the current display mode (`true` = numbers, `false` = dots) and a setter
 * that persists the choice and immediately updates every other mounted
 * `TraitListEditor` instance, so toggling it on one block changes all of them.
 */
export function useCostDisplayMode(): [ boolean, ( next: boolean ) => void ] {
	const [ numbers, setNumbers ] = useState( readStoredCostDisplayMode );

	useEffect( () => {
		listeners.add( setNumbers );
		return () => {
			listeners.delete( setNumbers );
		};
	}, [] );

	const setMode = ( next: boolean ) => {
		writeStoredCostDisplayMode( next );
		listeners.forEach( ( fn ) => fn( next ) );
	};

	return [ numbers, setMode ];
}
