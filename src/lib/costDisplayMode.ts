/**
 * A single, player-persisted preference for whether a `count_is_cost`-flagged trait_list block (Combo Disciplines)
 * shows its held entries' flat XP price at all.
 */
import { useEffect, useState } from '@wordpress/element';

const STORAGE_KEY = 'be-cost-visibility';
const listeners = new Set< ( show: boolean ) => void >();

/**
 * Reads the stored preference.
 */
export function readStoredCostVisibility(): boolean {
	try {
		return window.localStorage.getItem( STORAGE_KEY ) !== 'hide';
	} catch {
		return true;
	}
}

/**
 * Writes the preference.
 */
export function writeStoredCostVisibility( show: boolean ): void {
	try {
		window.localStorage.setItem( STORAGE_KEY, show ? 'show' : 'hide' );
	} catch {
		// A per-viewer convenience, never something a real toggle should be blocked on.
	}
}

/**
 * Returns whether the price is currently shown and a setter that persists the choice and immediately updates every
 * other mounted `TraitListEditor` instance.
 */
export function useCostVisibility(): [ boolean, ( next: boolean ) => void ] {
	const [ show, setShow ] = useState( readStoredCostVisibility );

	useEffect( () => {
		listeners.add( setShow );
		return () => {
			listeners.delete( setShow );
		};
	}, [] );

	const setMode = ( next: boolean ) => {
		writeStoredCostVisibility( next );
		listeners.forEach( ( fn ) => fn( next ) );
	};

	return [ show, setMode ];
}
