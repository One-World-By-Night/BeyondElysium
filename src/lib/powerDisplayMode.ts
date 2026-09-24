/**
 * A single, player-persisted preference for how every tiered_power block on a sheet displays a held power: the
 * stepper (default) or a per-level checklist.
 */
import { useEffect, useState } from '@wordpress/element';

const STORAGE_KEY = 'be-power-display-mode';
const listeners = new Set< ( checklist: boolean ) => void >();

/**
 * Reads the stored preference.
 */
export function readStoredDisplayMode(): boolean {
	try {
		return window.localStorage.getItem( STORAGE_KEY ) === 'checklist';
	} catch {
		return false;
	}
}

/**
 * Writes the preference.
 */
export function writeStoredDisplayMode( checklist: boolean ): void {
	try {
		window.localStorage.setItem(
			STORAGE_KEY,
			checklist ? 'checklist' : 'stepper'
		);
	} catch {
		// A per-viewer convenience, never something a real toggle should be blocked on.
	}
}

/**
 * Returns the current display mode (`true` = checklist, `false` = stepper) and a setter that persists the choice and
 * immediately updates every other mounted `TieredPowerEditor` instance.
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
