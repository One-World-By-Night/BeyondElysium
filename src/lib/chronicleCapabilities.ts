/**
 * What a screen shows depends on what the person can do in the chronicle it shows.
 */
import type { MyCapabilities } from '../types';

/**
 * Whether the person holds `capability` for this screen: the chronicle's own answer when the page resolved one, the
 * site-wide snapshot.
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

/**
 * Whether the person holds any one of `capabilities` for this screen.
 */
export function canAny(
	capabilities: Array< keyof MyCapabilities >,
	chronicle?: MyCapabilities
): boolean {
	return capabilities.some( ( capability ) =>
		canIn( capability, chronicle )
	);
}
