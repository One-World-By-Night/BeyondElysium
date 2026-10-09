import { readdirSync, readFileSync, statSync } from 'fs';
import { join } from 'path';

/**
 * Every stylesheet that reads a Storyteller Toolkit token (`--be-st-*`) with no fallback finds it declared, either
 * in the same stylesheet or in the global theme every page loads.
 */
const SRC = join( __dirname, '..' );
const THEME = join( SRC, 'styles', 'theme.css' );

function stylesheets( dir: string ): string[] {
	return readdirSync( dir ).flatMap( ( entry ) => {
		const full = join( dir, entry );
		if ( statSync( full ).isDirectory() ) {
			return stylesheets( full );
		}
		return entry.endsWith( '.css' ) ? [ full ] : [];
	} );
}

const declared = ( css: string ) =>
	new Set(
		[ ...css.matchAll( /(--be-st-[a-z-]+)\s*:/g ) ].map(
			( match ) => match[ 1 ]
		)
	);

test( 'every bare --be-st-* read is declared in its own stylesheet or the theme', () => {
	const global = declared( readFileSync( THEME, 'utf-8' ) );
	const missing: string[] = [];
	for ( const file of stylesheets( SRC ) ) {
		const css = readFileSync( file, 'utf-8' );
		const own = declared( css );
		for ( const [ , token ] of css.matchAll(
			/var\(\s*(--be-st-[a-z-]+)\s*\)/g
		) ) {
			if ( ! own.has( token ) && ! global.has( token ) ) {
				missing.push( `${ file.slice( SRC.length + 1 ) }: ${ token }` );
			}
		}
	}
	expect( [ ...new Set( missing ) ] ).toEqual( [] );
} );
