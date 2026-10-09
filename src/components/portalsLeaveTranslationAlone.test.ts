import { readdirSync, readFileSync, statSync } from 'fs';
import { join } from 'path';

/**
 * Anything a component draws outside its widget's mount point (a portal into the page's body) is out of reach of the
 * mount point's own translation marker, so each portal's root carries one.
 */

function findFiles( dir: string, out: string[] ): void {
	for ( const entry of readdirSync( dir ) ) {
		const full = join( dir, entry );
		if ( statSync( full ).isDirectory() ) {
			findFiles( full, out );
		} else if (
			entry.endsWith( '.tsx' ) &&
			! entry.endsWith( '.test.tsx' )
		) {
			out.push( full );
		}
	}
}

describe( 'a portal leaves translation plugins alone', () => {
	const files: string[] = [];
	findFiles( __dirname, files );
	const withPortal = files.filter( ( file ) =>
		/createPortal\s*\(/.test( readFileSync( file, 'utf8' ) )
	);

	it( 'finds the components that draw into the page body', () => {
		expect( withPortal.length ).toBeGreaterThanOrEqual( 3 );
	} );

	it( 'marks the root of every portal', () => {
		const unmarked = withPortal.filter(
			( file ) =>
				! /data-no-translation/.test( readFileSync( file, 'utf8' ) )
		);

		expect(
			unmarked.map( ( file ) => file.split( '/src/' ).pop() )
		).toEqual( [] );
	} );
} );
