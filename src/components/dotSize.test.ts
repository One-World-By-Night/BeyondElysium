import { readFileSync } from 'fs';
import { join } from 'path';

/**
 * 1.0.0-review F-016, owner ruling 2026-09-14: every dot the same size. The editor's pool control
 * drew its temporary row a quarter smaller than its permanent row, and the sheet drew a pool's dots
 * bolder and larger than a trait's. A tracker dot takes its size from the base dot rule; a dot in
 * text takes the size of the text around it, the same for a pool as for a trait.
 */
function rule( css: string, selector: string ): string {
	const escaped = selector.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const match = css.match( new RegExp( `${ escaped }\\s*\\{([^}]*)\\}` ) );
	return match ? match[ 1 ] : '';
}

test( 'a temporary dot in the editor is the same size as a permanent one', () => {
	const css = readFileSync(
		join( __dirname, 'shared/DotTracker.css' ),
		'utf-8'
	);

	expect( rule( css, '.be-dot-tracker__dot' ) ).toMatch( /\bwidth\s*:/ );
	expect( rule( css, '.be-dot-tracker__dot--temp' ) ).not.toMatch(
		/\b(?:width|height)\s*:/
	);
} );

test( "a pool's dots on the sheet are sized like the text around them, the same as a trait's", () => {
	const css = readFileSync(
		join( __dirname, 'renderers/ResourcePoolRenderer.css' ),
		'utf-8'
	);

	expect( rule( css, '.be-resource-pool__glyphs' ) ).not.toMatch(
		/\bfont-(?:size|weight)\s*:/
	);
} );

test( 'every dot on the sheet and in the editor draws through the one dot size', () => {
	const dots = readFileSync( join( __dirname, 'shared/Dots.css' ), 'utf-8' );
	const tracker = readFileSync(
		join( __dirname, 'shared/DotTracker.css' ),
		'utf-8'
	);
	const size = ( body: string, property: string ) =>
		body
			.match( new RegExp( `\\b${ property }\\s*:\\s*([^;]+);` ) )?.[ 1 ]
			.trim();

	expect( size( rule( dots, '.be-dot' ), 'width' ) ).toBe( '0.875rem' );
	expect( size( rule( dots, '.be-dot' ), 'height' ) ).toBe( '0.875rem' );
	expect( size( rule( tracker, '.be-dot-tracker__dot' ), 'width' ) ).toBe(
		size( rule( dots, '.be-dot' ), 'width' )
	);
	// The border sits inside that size for both, or a tracker dot comes out two pixels bigger.
	expect(
		size( rule( tracker, '.be-dot-tracker__dot' ), 'box-sizing' )
	).toBe( 'border-box' );
	expect( size( rule( dots, '.be-dot' ), 'box-sizing' ) ).toBe(
		'border-box'
	);
	for ( const state of [ 'filled', 'spent', 'overflow' ] ) {
		expect( rule( dots, `.be-dot--${ state }` ) ).not.toMatch(
			/\b(?:width|height|font-size)\s*:/
		);
	}

	for ( const component of [
		'renderers/TraitListRenderer.tsx',
		'renderers/ResourcePoolRenderer.tsx',
		'editors/TraitListEditor.tsx',
	] ) {
		expect( readFileSync( join( __dirname, component ), 'utf-8' ) ).toMatch(
			/<WithDots\s+text=/
		);
	}
} );
