/**
 * Renders a resource pool's permanent and temporary point values as a row of dots.
 */

export interface ResourcePoolValue {
	permanent: number;
	temporary: number;
}

/**
 * A point held - also every trait rating's dot (`displayTrait()`).
 */
export const DOT = '●';
const SPENT = '○';
const OVERFLOW = '◉';

/**
 * Dots are grouped, space-separated, in runs of this many.
 */
const GROUP = 5;

/**
 * Renders a resource pool as a row of dots: filled up to the lesser of permanent and temporary.
 */
export function displayTemper( value: ResourcePoolValue ): string {
	const { permanent, temporary } = value;

	const dots =
		temporary >= permanent
			? DOT.repeat( Math.max( 0, permanent ) ) +
			  OVERFLOW.repeat( Math.max( 0, temporary - permanent ) )
			: DOT.repeat( Math.max( 0, temporary ) ) +
			  SPENT.repeat( Math.max( 0, permanent - temporary ) );

	const glyphs = Array.from( dots );
	const groups: string[] = [];
	for ( let i = 0; i < glyphs.length; i += GROUP ) {
		groups.push( glyphs.slice( i, i + GROUP ).join( '' ) );
	}
	const rendered = groups.join( ' ' );

	// Every dot readout also shows its number.
	const suffix =
		temporary === permanent
			? String( permanent )
			: `${ temporary }/${ permanent }`;
	return rendered === '' ? suffix : `${ rendered } ${ suffix }`;
}
