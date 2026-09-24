/**
 * The costs a player may choose for a variable-cost catalog item.
 */

/**
 * Every cost this item may be bought at, lowest first as written, or null when its cost is fixed.
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
