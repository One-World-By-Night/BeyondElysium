import { DOT } from './displayTemper';

export type DotState = 'filled' | 'spent' | 'overflow';

/**
 * Each glyph a rating or a pool draws as a dot (`displayTrait()`, `displayTemper()`), by what it means.
 */
export const DOT_STATES: Readonly< Record< string, DotState > > = {
	[ DOT ]: 'filled',
	'○': 'spent',
	'◉': 'overflow',
};

export interface TextRun {
	text: string;
	dots: boolean;
}

/**
 * Splits display text into runs of dots and runs of everything else.
 */
export function splitDots( text: string ): TextRun[] {
	const glyphs = Object.keys( DOT_STATES ).join( '' );
	const pattern = new RegExp( `[${ glyphs }]+(?: [${ glyphs }]+)*`, 'gu' );
	const runs: TextRun[] = [];
	let last = 0;
	for ( const match of text.matchAll( pattern ) ) {
		const start = match.index ?? 0;
		if ( start > last ) {
			runs.push( { text: text.slice( last, start ), dots: false } );
		}
		runs.push( { text: match[ 0 ], dots: true } );
		last = start + match[ 0 ].length;
	}
	if ( last < text.length ) {
		runs.push( { text: text.slice( last ), dots: false } );
	}
	return runs;
}
