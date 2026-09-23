/**
 * A single, player-persisted preference for whether a `count_is_cost`-flagged trait_list
 * block (Combo Disciplines) shows its held entries' flat XP price at all. Shared live
 * across every mounted `TraitListEditor` instance on the page via a small module-level
 * listener set, matching `powerDisplayMode.ts`'s own established shape exactly.
 *
 * 1.1.0 D3 built this as a dots-or-numbers choice defaulting to dots, which is exactly
 * what 1.2.11 D94 had to undo: the stored count on one of these blocks is a price, and a
 * price drawn as a rating - or as a bare number with no unit - is wrong for every viewer
 * who never found the toggle. The price is now always labelled `(12 XP)` and this
 * preference only chooses whether it appears, defaulting to shown. The old
 * `be-cost-display-mode` key is deliberately not read: `dots` meant "draw this as a
 * rating", a choice that no longer exists, and reading it as "hide the price" would take
 * the number away from a viewer who never asked for that.
 */
import { useEffect, useState } from '@wordpress/element';

const STORAGE_KEY = 'be-cost-visibility';
const listeners = new Set< ( show: boolean ) => void >();

/** Reads the stored preference. Anything but an explicit `hide` shows the price. */
export function readStoredCostVisibility(): boolean {
	try {
		return window.localStorage.getItem( STORAGE_KEY ) !== 'hide';
	} catch {
		return true;
	}
}

/** Writes the preference. Any storage failure - a full quota, private browsing - is caught and ignored. */
export function writeStoredCostVisibility( show: boolean ): void {
	try {
		window.localStorage.setItem( STORAGE_KEY, show ? 'show' : 'hide' );
	} catch {
		// A per-viewer convenience, never something a real toggle should be blocked on.
	}
}

/**
 * Returns whether the price is currently shown and a setter that persists the choice and
 * immediately updates every other mounted `TraitListEditor` instance, so toggling it on
 * one block changes all of them.
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
