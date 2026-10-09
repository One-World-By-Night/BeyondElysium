/**
 * WCAG relative luminance and contrast for hex colors.
 */

const MIN_TEXT_CONTRAST = 4.5;

export interface Rgb {
	r: number;
	g: number;
	b: number;
}

/**
 * Parses `#rgb` or `#rrggbb`; null for anything else.
 */
export function parseHex( color: string | null | undefined ): Rgb | null {
	const match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(
		( color ?? '' ).trim()
	);
	if ( ! match ) {
		return null;
	}
	const hex =
		match[ 1 ].length === 3
			? match[ 1 ]
					.split( '' )
					.map( ( c ) => c + c )
					.join( '' )
			: match[ 1 ];
	return {
		r: parseInt( hex.slice( 0, 2 ), 16 ),
		g: parseInt( hex.slice( 2, 4 ), 16 ),
		b: parseInt( hex.slice( 4, 6 ), 16 ),
	};
}

function channel( value: number ): number {
	const s = value / 255;
	return s <= 0.03928 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
}

export function relativeLuminance( rgb: Rgb ): number {
	return (
		0.2126 * channel( rgb.r ) +
		0.7152 * channel( rgb.g ) +
		0.0722 * channel( rgb.b )
	);
}

/**
 * The WCAG contrast ratio of two hex colors, 1 to 21; null when either isn't a hex color.
 */
export function contrastRatio( a: string, b: string ): number | null {
	const first = parseHex( a );
	const second = parseHex( b );
	if ( ! first || ! second ) {
		return null;
	}
	const la = relativeLuminance( first );
	const lb = relativeLuminance( second );
	return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
}

/**
 * Black or white, whichever reads better on the given background.
 */
export function readableTextFor( background: string ): string {
	const rgb = parseHex( background );
	if ( ! rgb ) {
		return '#000000';
	}
	return relativeLuminance( rgb ) > 0.179 ? '#000000' : '#ffffff';
}

/**
 * Whether two colors read well together as text on a background.
 */
export function readsWell( text: string, background: string ): boolean {
	const ratio = contrastRatio( text, background );
	return ratio === null || ratio >= MIN_TEXT_CONTRAST;
}
