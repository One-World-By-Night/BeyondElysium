import { readdirSync, readFileSync, statSync } from 'fs';
import { join } from 'path';

/**
 * `CharacterEditor.tsx` sets an inline desktop `gridColumn: span N` on every section
 * (v0.21.21 Step 8d found this fabricates near-zero-width implicit grid tracks at phone
 * width unless the phone-width media query explicitly overrides it with `!important`).
 * `CharacterSheet.tsx` carries the identical inline style and had no such override until
 * this test was written - the read-only sheet, the single most-used mobile surface this
 * plugin has, was scrolling horizontally by 83% of the screen width on a real phone. This
 * scans the real component tree so a future component copying the same inline-span pattern
 * can't repeat that silently - there is no other automated phone-width check in this repo.
 */

function findComponentFiles( dir: string, out: { tsx: string[]; css: string[] } ): void {
	for ( const entry of readdirSync( dir ) ) {
		const full = join( dir, entry );
		if ( statSync( full ).isDirectory() ) {
			findComponentFiles( full, out );
			continue;
		}
		if ( entry.endsWith( '.tsx' ) && ! entry.endsWith( '.test.tsx' ) ) {
			out.tsx.push( full );
		} else if ( entry.endsWith( '.css' ) ) {
			out.css.push( full );
		}
	}
}

test( 'every component with an inline gridColumn span has a phone-width override', () => {
	const files = { tsx: [] as string[], css: [] as string[] };
	findComponentFiles( join( __dirname ), files );

	const missing: string[] = [];

	for ( const tsxPath of files.tsx ) {
		const source = readFileSync( tsxPath, 'utf8' );
		if ( ! source.includes( 'gridColumn: `span' ) ) {
			continue;
		}

		const cssPath = tsxPath.replace( /\.tsx$/, '.css' );
		if ( ! files.css.includes( cssPath ) ) {
			missing.push( `${ tsxPath } has no matching .css file at all` );
			continue;
		}

		const css = readFileSync( cssPath, 'utf8' );
		const phoneBlockMatch = css.match( /@media\s*\(\s*max-width:\s*768px\s*\)\s*{([\s\S]*?)\n}/ );
		if ( ! phoneBlockMatch ) {
			missing.push( `${ cssPath } has no max-width: 768px media query` );
			continue;
		}

		const phoneBlock = phoneBlockMatch[ 1 ];
		if ( ! /grid-column\s*:\s*1\s*\/\s*-1\s*!important/.test( phoneBlock ) ) {
			missing.push( `${ cssPath }'s phone-width block has no grid-column override with !important` );
		}
	}

	expect( missing ).toEqual( [] );
} );
