/**
 * Per-viewer collapsed state for the sheet's panels and sections, persisted.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';

const STORAGE_KEY = 'be-collapsed-panels';
const listeners = new Set< () => void >();

type CollapseMap = Record< string, boolean >;

/**
 * Reads the whole map.
 */
export function readCollapsed(): CollapseMap {
	try {
		const raw = window.localStorage.getItem( STORAGE_KEY );
		if ( ! raw ) {
			return {};
		}
		const parsed: unknown = JSON.parse( raw );
		if (
			! parsed ||
			typeof parsed !== 'object' ||
			Array.isArray( parsed )
		) {
			return {};
		}
		const out: CollapseMap = {};
		for ( const [ key, value ] of Object.entries( parsed ) ) {
			if ( typeof value === 'boolean' ) {
				out[ key ] = value;
			}
		}
		return out;
	} catch {
		return {};
	}
}

/**
 * Writes the map.
 */
export function writeCollapsed( state: CollapseMap ): void {
	try {
		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( state ) );
	} catch {
		// Per-viewer convenience only.
	}
}

/**
 * What the viewer chose for this panel, or `undefined` if they never chose.
 */
export function storedCollapsed( id: string ): boolean | undefined {
	return readCollapsed()[ id ];
}

/**
 * Folds or unfolds one panel and tells every other mounted instance.
 */
export function setCollapsed( id: string, collapsed: boolean ): void {
	const state = readCollapsed();
	state[ id ] = collapsed;
	writeCollapsed( state );
	listeners.forEach( ( fn ) => fn() );
}

/**
 * Collapsed state for one panel, plus a setter.
 */
export function useCollapsed(
	id: string,
	forceOpenKey?: string | number,
	defaultCollapsed = false
): [ boolean, ( next: boolean ) => void ] {
	const resolve = useCallback(
		() => storedCollapsed( id ) ?? defaultCollapsed,
		[ id, defaultCollapsed ]
	);

	const [ collapsed, setLocal ] = useState( resolve );
	const [ lastForce, setLastForce ] = useState( forceOpenKey );

	useEffect( () => {
		const sync = () => setLocal( resolve() );
		sync();
		listeners.add( sync );
		return () => {
			listeners.delete( sync );
		};
	}, [ resolve ] );

	useEffect( () => {
		if ( forceOpenKey === lastForce ) {
			return;
		}
		setLastForce( forceOpenKey );
		if ( forceOpenKey ) {
			setCollapsed( id, false );
		}
	}, [ forceOpenKey, lastForce, id ] );

	const set = useCallback(
		( next: boolean ) => {
			setCollapsed( id, next );
		},
		[ id ]
	);

	return [ collapsed, set ];
}
