/**
 * Renders a resource pool's permanent and temporary point values as a fixed-glyph
 * string for on-screen display, distinct from the configurable-glyph rendering used for
 * template output. Runs of five identical glyphs collapse into a single capital glyph so
 * a large pool stays compact instead of becoming a long wall of dots. Exports
 * `displayTemper()` and the `ResourcePoolValue` type it operates on.
 */

export interface ResourcePoolValue {
	permanent: number;
	temporary: number;
}

const FILLED = 'o';
const OVERFLOW = 'õ';
const SPENT = 'ø';

const CAPITALS: Record<string, string> = {
	[ FILLED ]: 'O',
	[ OVERFLOW ]: 'Õ',
	[ SPENT ]: 'Ø',
};

/**
 * Collapses one run of five identical glyphs into its capital form, or returns null
 * when no such run exists. Searches from the right (the rightmost run) when `fromRight`
 * is true, otherwise from the left (the leftmost run).
 */
function collapseOnce( str: string, glyph: string, fromRight: boolean ): string | null {
	const run = glyph.repeat( 5 );
	const idx = fromRight ? str.lastIndexOf( run ) : str.indexOf( run );
	if ( idx === -1 ) {
		return null;
	}
	return str.slice( 0, idx ) + CAPITALS[ glyph ] + str.slice( idx + 5 );
}

/**
 * Renders a resource pool as a glyph string: filled glyphs up to the lesser of
 * permanent and temporary, then spent or overflow glyphs for the remainder. A temporary
 * value above permanent renders as overflow past the permanent track rather than being
 * clamped, and the result is compressed to stay at or near 10 characters.
 */
export function displayTemper( value: ResourcePoolValue ): string {
	const { permanent, temporary } = value;

	let str: string;
	if ( temporary >= permanent ) {
		str = FILLED.repeat( Math.max( 0, permanent ) ) + OVERFLOW.repeat( Math.max( 0, temporary - permanent ) );
	} else {
		str = FILLED.repeat( Math.max( 0, temporary ) ) + SPENT.repeat( Math.max( 0, permanent - temporary ) );
	}

	// Collapses spent runs first, then filled, then overflow, until no run of five remains.
	while ( str.length > 10 ) {
		const next =
			collapseOnce( str, SPENT, true ) ??
			collapseOnce( str, FILLED, false ) ??
			collapseOnce( str, OVERFLOW, true );

		if ( next === null ) {
			break;
		}
		str = next;
	}

	return str;
}
