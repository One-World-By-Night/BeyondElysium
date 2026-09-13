/**
 * A single, player-persisted preference for how every tiered_power block on a sheet
 * displays a held power: the stepper (default) or a per-level checklist. This is a
 * per-sheet-visit preference, not a per-power one - a player who prefers seeing every
 * named rung wants that everywhere they look, not toggled block by block. Shared live
 * across every `TieredPowerEditor` instance mounted on the page at once (one per
 * tiered_power block) via a small module-level listener set, since each is otherwise
 * an independent component with its own local state.
 */
import { useEffect, useState } from '@wordpress/element';

const STORAGE_KEY = 'be-power-display-mode';
const listeners = new Set<( checklist: boolean ) => void>();

/** Reads the stored preference. Exported for testing the storage logic without a hook-rendering dependency. */
export function readStoredDisplayMode(): boolean {
	try {
		return window.localStorage.getItem( STORAGE_KEY ) === 'checklist';
	} catch {
		return false;
	}
}

/** Writes the preference. Any storage failure - a full quota, private browsing - is caught and ignored. */
export function writeStoredDisplayMode( checklist: boolean ): void {
	try {
		window.localStorage.setItem( STORAGE_KEY, checklist ? 'checklist' : 'stepper' );
	} catch {
		// A per-viewer convenience, never something a real toggle should be blocked on.
	}
}

/**
 * Returns the current display mode (`true` = checklist, `false` = stepper) and a
 * setter that persists the choice and immediately updates every other mounted
 * `TieredPowerEditor` instance, so toggling it on one block's row changes all of them.
 */
export function usePowerDisplayMode(): [ boolean, ( next: boolean ) => void ] {
	const [ checklist, setChecklist ] = useState( readStoredDisplayMode );

	useEffect( () => {
		listeners.add( setChecklist );
		return () => {
			listeners.delete( setChecklist );
		};
	}, [] );

	const setMode = ( next: boolean ) => {
		writeStoredDisplayMode( next );
		listeners.forEach( ( fn ) => fn( next ) );
	};

	return [ checklist, setMode ];
}
