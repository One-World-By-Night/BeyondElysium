import { readdirSync, readFileSync, statSync } from 'fs';
import { join } from 'path';

/**
 * Eight editor/shared components (ResourcePoolEditor, TraitListEditor, TieredPowerEditor, IdentityFieldEditor,
 * BlockEditor, SearchableSelect, ConfirmDialog, DotTracker) each had a real, non-trivial CSS file sitting next to
 * them that nothing ever imported.
 */

function findComponentFiles(
	dir: string,
	out: { tsx: string[]; css: string[] }
): void {
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
