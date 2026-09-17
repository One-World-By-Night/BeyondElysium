/**
 * Renders a resource pool's permanent and temporary point values as a row of dots - the same
 * dot a trait's rating uses (1.0.0-review F-016, owner ruling 2026-09-14: every dot the same
 * size). A point held is a filled dot, a permanent point currently spent an empty dot, and a
 * temporary point above permanent a ringed dot; dots group in fives so a large pool stays
 * countable. Grapevine drew letters its own font turned into dots and collapsed each run of five
 * into a capital, which put two dot sizes side by side here. Exports `displayTemper()`, the `DOT`
 * glyph, and the `ResourcePoolValue` type it operates on.
 */

export interface ResourcePoolValue {
	permanent: number;
	temporary: number;
}

/** A point held - also every trait rating's dot (`displayTrait()`). */
export const DOT = '●';
const SPENT = '○';
const OVERFLOW = '◉';

/** Dots are grouped, space-separated, in runs of this many. */
const GROUP = 5;

/**
 * Renders a resource pool as a row of dots: filled up to the lesser of permanent and temporary,
 * then spent or overflow dots for the remainder. A temporary value above permanent renders as
 * overflow past the permanent track rather than being clamped.
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

	// 1.1.0 D1: every dot readout also shows its number - bare when current equals
	// permanent, "current/permanent" otherwise, so an over- or under-spent pool reads
	// unambiguously rather than requiring the viewer to count dots.
	const suffix =
		temporary === permanent
			? String( permanent )
			: `${ temporary }/${ permanent }`;
	return rendered === '' ? suffix : `${ rendered } ${ suffix }`;
}
