import { readdirSync, readFileSync, statSync } from 'fs';
import { join } from 'path';

/**
 * `max-width: 768px` is the one phone breakpoint this plugin's front-end
 * widgets and editors use (mobile-sheet-design.md §5.1) - not WordPress
 * core's 782px, since these are front-end widgets in a theme's own layout,
 * not wp-admin panels. `WorldObjectManager.css` carried `767px`, one pixel
 * off from every other file, which at exactly 768px device width (a common
 * tablet-portrait size) meant it alone stayed two-column while every sibling
 * component had already collapsed. This scans every stylesheet under `src/`
 * so a second breakpoint value can't drift in silently, the same idiom
 * `cssImports.test.ts` and `gridCollapse.test.ts` already established.
 */

const ALLOWED_WIDTH_QUERY = 'max-width: 768px';

function findCssFiles( dir: string, out: string[] ): void {
	for ( const entry of readdirSync( dir ) ) {
		const full = join( dir, entry );
		if ( statSync( full ).isDirectory() ) {
			findCssFiles( full, out );
			continue;
		}
		if ( entry.endsWith( '.css' ) ) {
			out.push( full );
		}
	}
}

test( 'no width-based media query in src/ uses a value other than max-width: 768px', () => {
	const cssFiles: string[] = [];
	findCssFiles( join( __dirname, '..' ), cssFiles );

	const offenders: string[] = [];

	for ( const cssPath of cssFiles ) {
		const css = readFileSync( cssPath, 'utf-8' );
		const widthQueries =
			css.match(
				/@media[^{]*\b(?:min|max)-width\s*:\s*[\d.]+px[^{]*/g
			) ?? [];

		for ( const query of widthQueries ) {
			if ( ! query.includes( ALLOWED_WIDTH_QUERY ) ) {
				offenders.push( `${ cssPath }: "${ query.trim() }"` );
			}
		}
	}

	expect( offenders ).toEqual( [] );
} );
