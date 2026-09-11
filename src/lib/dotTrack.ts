/**
 * Pure logic behind an interactive dot track: which of `max` dots is filled, spent, or
 * overflow for a given permanent/temporary pair, and how a click or a +/- step changes
 * the track's value. Exports `computeDotStates()`, `nextTrackValueOnClick()`, and
 * `stepTrackValue()`, plus the shared `DotState` type.
 */

export type DotState = 'filled' | 'spent' | 'overflow' | 'empty';

/**
 * Computes the filled/spent/overflow/empty state of each dot from 1 to `max` for a
 * track showing both a permanent and a temporary value. A temporary value above
 * permanent renders as overflow past the permanent mark rather than being clamped.
 */
export function computeDotStates( permanent: number, temporary: number, max: number ): DotState[] {
	const states: DotState[] = [];

	for ( let i = 1; i <= max; i++ ) {
		if ( temporary >= permanent ) {
			if ( i <= permanent ) {
				states.push( 'filled' );
			} else if ( i <= temporary ) {
				states.push( 'overflow' );
			} else {
				states.push( 'empty' );
			}
		} else if ( i <= temporary ) {
			states.push( 'filled' );
		} else if ( i <= permanent ) {
			states.push( 'spent' );
		} else {
			states.push( 'empty' );
		}
	}

	return states;
}

/**
 * Computes a track's new value from a click on dot `clicked`: sets the value to
 * `clicked`, unless `clicked` already equals the current value, in which case it clears
 * down to `clicked - 1` so a value can be reduced one dot at a time.
 */
export function nextTrackValueOnClick( current: number, clicked: number ): number {
	return current === clicked ? clicked - 1 : clicked;
}

/**
 * Applies one +/- step to a track's current value, moving it by `delta` and clamping
 * the result to the range `[0, max]` so it can never fall below 0 or exceed the track's
 * maximum.
 */
export function stepTrackValue( current: number, delta: 1 | -1, max: number ): number {
	return Math.max( 0, Math.min( max, current + delta ) );
}
