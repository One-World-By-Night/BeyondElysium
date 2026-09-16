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
		/@container be-table \( width < 960px \) \{\s*\.be-responsive-table thead/
	);
	expect( css ).not.toMatch( /@media[^{]*\{\s*\.be-responsive-table/ );
} );

/**
 * The boundary is exclusive on purpose, and the admin cap must stay clear of it.
 *
 * `.be-admin` used to cap at exactly 960px while the card rule read `max-width: 960px`, which
 * matches *at* 960 - so every wp-admin table pinned itself to the cap and then stacked every
 * row into a card, on every desktop, for the whole of 1.0.0. Two independent changes keep that
 * from recurring; this pins both.
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
