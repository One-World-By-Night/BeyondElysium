import { readdirSync, readFileSync, statSync } from 'fs';
import { join, relative } from 'path';

/**
 * Tables too small to need cards: two short columns fit any phone.
 */

/**
 * Tables too small to need cards.
 */
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
		/@container be-table \( width < 960px \) \{\s*\.be-responsive-table thead/
	);
	expect( css ).not.toMatch( /@media[^{]*\{\s*\.be-responsive-table/ );
} );

/**
 * A wp-admin table is never pinned exactly to the card boundary: the boundary is exclusive, and the admin cap stays
 * clear of it.
 */
test( 'a wp-admin table is never pinned exactly to the card boundary', () => {
	const adminCss = readFileSync(
		join( __dirname, 'admin/Admin.css' ),
		'utf8'
	);

	const cap = adminCss.match( /\.be-admin\s*\{[^}]*max-width:\s*(\d+)px/ );
	expect( cap ).not.toBeNull();
	expect( Number( cap![ 1 ] ) ).toBeGreaterThan( 960 );
} );
