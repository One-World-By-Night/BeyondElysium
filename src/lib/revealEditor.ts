/**
 * Brings an admin editor panel into view when it opens: scrolls it to the top of the viewport and puts keyboard focus
 * on its first usable field.
 */
import { useEffect } from '@wordpress/element';
import type { RefObject } from 'react';

const FIELD_SELECTOR =
	'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])';

/**
 * Scrolls an editor into view and focuses its first enabled field, or the editor itself when it has none.
 *
 * @param el The editor element, or null when none is rendered.
 * @return True when an editor was revealed.
 */
export function revealEditor( el: HTMLElement | null ): boolean {
	if ( ! el ) {
		return false;
	}
	el.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	const field = el.querySelector< HTMLElement >( FIELD_SELECTOR );
	( field ?? el ).focus( { preventScroll: true } );
	return true;
}

/**
 * Reveals the referenced editor each time `openKey` changes to a value naming an open editor.
 *
 * @param ref     The editor element's ref.
 * @param openKey What is being edited (a slug, id or "new"), or null, false or '' while no editor is open.
 */
export function useRevealOnOpen(
	ref: RefObject< HTMLElement >,
	openKey: string | number | boolean | null
): void {
	useEffect( () => {
		if ( openKey === null || openKey === false || openKey === '' ) {
			return undefined;
		}
		const frame = window.requestAnimationFrame( () =>
			revealEditor( ref.current )
		);
		return () => window.cancelAnimationFrame( frame );
	}, [ openKey, ref ] );
}
