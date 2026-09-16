/**
 * The costs a player may choose for a variable-cost catalog item - "1 or 3",
 * "1-7" - read the way `Cost_Engine::parse_cost_rule()` reads them, so the
 * editor offers exactly the prices the server charges (1.0.0-review F-107;
 * `tests/fixtures/cost-choices.json` holds both to the same cases).
 */

/**
 * Every cost this item may be bought at, lowest first as written, or null
 * when its cost is fixed - a single number, unreadable, or a range written
 * high to low, which the server always prices at its first number.
 */
export function costChoices( cost: string | undefined ): number[] | null {
	const trimmed = ( cost ?? '' ).trim();

	if ( /^-?\d+(?:\s+or\s+-?\d+)+$/i.test( trimmed ) ) {
		return trimmed
			.split( /\s+or\s+/i )
			.map( ( value ) => parseInt( value, 10 ) );
	}

	const range = /^(-?\d+)\s*-\s*(-?\d+)$/.exec( trimmed );
	if ( range ) {
		const low = parseInt( range[ 1 ], 10 );
		const high = parseInt( range[ 2 ], 10 );
		if ( low > high ) {
			return null;
		}
		return Array.from( { length: high - low + 1 }, ( _, i ) => low + i );
	}

	return null;
}
