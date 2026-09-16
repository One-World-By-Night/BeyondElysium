import { readdirSync, readFileSync, statSync } from 'fs';
import { join, relative } from 'path';

/**
 * 1.0.0-review F-019. In a theme with a narrow content column, the character list and the
 * approval queue ran hundreds of pixels past their widget into the page around it, and on a phone
 * the roster scrolled the whole page sideways. Owner, 2026-09-15: sideways scrolling on a phone is
 * not an answer either. Every front-end table sits directly inside a `.be-table-box`, and stacks
 * into cards (`be-responsive-table`) whenever that box is narrow - src/styles/breakpoints.css.
 * wp-admin screens are left out: WordPress lays those out, not a theme.
 */

/** Tables too small to need cards - two short columns fit any phone. */
const FITS_ANY_PHONE = [ 'be-import-preview__counts' ];

function frontEndComponents( dir: string, out: string[] ): string[] {
	for ( const entry of readdirSync( dir ) ) {
		const full = join( dir, entry );
		if ( statSync( full ).isDirectory() ) {
			if ( entry !== 'admin' ) {
				frontEndComponents( full, out );
			}
		} else if ( entry.endsWith( '.tsx' ) ) {
			out.push( full );
		}
	}
	return out;
}

test( 'every front-end table sits in a table box and stacks into cards when the box is narrow', () => {
	const problems: string[] = [];

	for ( const file of frontEndComponents( __dirname, [] ) ) {
		const lines = readFileSync( file, 'utf8' ).split( '\n' );
		lines.forEach( ( line, index ) => {
			// A table element, not a comment that mentions one.
			if ( ! /^\s*<table\b/.test( line ) ) {
				return;
			}
			const where = `${ relative( __dirname, file ) }:${ index + 1 }`;
			let previous = index - 1;
			while ( previous >= 0 && lines[ previous ].trim() === '' ) {
				previous--;
			}
			if (
				previous < 0 ||
				! lines[ previous ].includes( 'className="be-table-box"' )
			) {
				problems.push(
					`${ where } is not directly inside a be-table-box`
				);
			}
			if (
				! line.includes( 'be-responsive-table' ) &&
				! FITS_ANY_PHONE.some( ( name ) => line.includes( name ) )
			) {
				problems.push( `${ where } does not stack into cards` );
			}
		} );
	}

	expect( problems ).toEqual( [] );
} );

test( 'cards follow the width of the table box, not the screen', () => {
	const css = readFileSync(
		join( __dirname, '../styles/breakpoints.css' ),
		'utf8'
	);

	expect( css ).toMatch(
		/\.be-table-box\s*\{[^}]*container:\s*be-table\s*\/\s*inline-size/
	);
	expect( css ).toMatch(
		/@container be-table \( max-width: 960px \) \{\s*\.be-responsive-table thead/
	);
	expect( css ).not.toMatch( /@media[^{]*\{\s*\.be-responsive-table/ );
} );
