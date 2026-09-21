/**
 * Per-viewer collapsed state for the sheet's panels and sections, persisted so a player
 * who folds something away finds it folded next time. Follows `powerDisplayMode.ts`'s
 * established shape - one storage key, every read and write wrapped, a module-level
 * listener set so instances mounted at the same time stay in step.
 *
 * Why this exists: D74. `CharacterEditor`'s pending-changes panel is
 * `position: sticky; bottom: 0` and grew one row per queued change, reaching **91% of the
 * viewport at 27 changes** and covering the editor it belongs to (owner-reported live,
 * 2026-09-21). A height cap stops the runaway but still decides for the player how much
 * screen the panel takes. Collapsing hands that back, and the owner's ruling was that
 * every panel should offer it, not just the one that broke.
 *
 * State is a **record, not a set of collapsed ids**, so "the viewer expanded this" is
 * distinguishable from "the viewer has never touched this". The character sheet needs that
 * distinction: its sections carry a per-template `collapsed` default, and an untouched
 * section must follow the template while a touched one must not.
 *
 * Storage is a per-viewer convenience and may be absent - a private window, cleared site
 * data, a thumbnail capture. **Every failure degrades to the caller's default**, never to a
 * panel a player cannot find.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';

const STORAGE_KEY = 'be-collapsed-panels';
const listeners = new Set< () => void >();

type CollapseMap = Record< string, boolean >;

/** Reads the whole map. A malformed or unreadable store reads as "nothing touched". */
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

/** Writes the map. Any storage failure is swallowed - never worth blocking a toggle on. */
export function writeCollapsed( state: CollapseMap ): void {
	try {
		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( state ) );
	} catch {
		// Per-viewer convenience only.
	}
}

/**
 * What the viewer chose for this panel, or `undefined` if they never chose - which is the
 * signal to fall back to whatever default the caller carries.
 */
export function storedCollapsed( id: string ): boolean | undefined {
	return readCollapsed()[ id ];
}

/**
 * Folds or unfolds one panel and tells every other mounted instance, so two views of the
 * same panel on one page cannot disagree.
 */
export function setCollapsed( id: string, collapsed: boolean ): void {
	const state = readCollapsed();
	state[ id ] = collapsed;
	writeCollapsed( state );
	listeners.forEach( ( fn ) => fn() );
}

/**
 * Collapsed state for one panel, plus a setter.
 *
 * `forceOpenKey` implements the owner's "re-open when there's something new" rule: when it
 * changes to a value that is truthy and was not seen before, the panel unfolds itself even
 * if the player had folded it. The pending-changes panel is the case that matters - nobody
 * should submit blind to what they changed. It only ever forces a panel **open**, never
 * shut, so it can't fight a player who is deliberately folding things away.
 *
 * `defaultCollapsed` is what applies until the viewer touches the panel themselves. The
 * character sheet passes its template's own `collapsed` flag here.
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
