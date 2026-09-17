/**
 * What a screen shows depends on what the person can do in the chronicle it
 * shows. My Chronicle and the Storyteller Toolkit resolve that per chronicle
 * as the switcher changes; a screen placed on a page by itself has only the
 * site-wide snapshot of what the person can do anywhere (1.0.0-review F-103).
 * Either way it's display only - every route checks for itself.
 */
import type { MyCapabilities } from '../types';

/**
 * Whether the person holds `capability` for this screen: the chronicle's
 * own answer when the page resolved one, the site-wide snapshot otherwise.
 */
export function canIn(
	capability: keyof MyCapabilities,
	chronicle?: MyCapabilities
): boolean {
	if ( chronicle ) {
		return chronicle[ capability ];
	}
	return !! window.beyondElysium?.capabilities?.[ capability ];
}

/** Whether the person holds any one of `capabilities` for this screen - an OR-gated route. */
export function canAny(
	capabilities: Array< keyof MyCapabilities >,
	chronicle?: MyCapabilities
): boolean {
	return capabilities.some( ( capability ) =>
		canIn( capability, chronicle )
	);
}
