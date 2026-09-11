import { readdirSync, readFileSync, statSync } from 'fs';
import { join } from 'path';

/**
 * Eight editor/shared components (ResourcePoolEditor, TraitListEditor, TieredPowerEditor,
 * IdentityFieldEditor, BlockEditor, SearchableSelect, ConfirmDialog, DotTracker) each had a
 * real, non-trivial CSS file sitting next to them that nothing ever imported - so none of it
 * ever reached the browser, and the theme's own generic `button`/`select` reset rules won by
 * default instead. Found live 2026-09-09: a resource pool's dot tracker rendered every dot
 * (filled or empty) as an identical unstyled pill button, making a real 5-of-10 Willpower
 * value visually indistinguishable from 0-of-10. `npm run build` reported success throughout,
 * same shape as D10 - a webpack success only proves the files that WERE imported compiled
 * cleanly, not that everything meant to ship actually did. This scans the real component tree
 * so a future component can't repeat it silently.
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

test( 'every component CSS file is imported by its matching .tsx file', () => {
	const files = { tsx: [] as string[], css: [] as string[] };
	findComponentFiles( join( __dirname ), files );

	const unimported: string[] = [];

	for ( const cssPath of files.css ) {
		const tsxPath = cssPath.replace( /\.css$/, '.tsx' );
		if ( ! files.tsx.includes( tsxPath ) ) {
			continue; // no matching component (e.g. a shared stylesheet with several consumers)
		}
		const cssBasename = cssPath.split( '/' ).pop() as string;
		const source = readFileSync( tsxPath, 'utf-8' );
		if ( ! source.includes( cssBasename ) ) {
			unimported.push( tsxPath );
		}
	}

	expect( unimported ).toEqual( [] );
} );
