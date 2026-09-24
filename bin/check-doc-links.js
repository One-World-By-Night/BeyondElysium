#!/usr/bin/env node
/**
 * Fails when a relative Markdown link points at a file that isn't there.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const ROOTS = [ 'beyond-elysium/docs', 'Documents', '.' ];
const SKIP_DIRS = new Set( [
	'node_modules',
	'vendor',
	'dist',
	'build',
	'.git',
	'languages',
] );

/**
 * Every .md file under `dir`, recursively.
 *
 * @param dir
 * @param depth
 */
function markdownFiles( dir, depth = 0 ) {
	// The repo root itself is scanned shallowly.
	if ( ! fs.existsSync( dir ) ) {
		return [];
	}
	const out = [];
	for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		if ( entry.name.startsWith( '.' ) || SKIP_DIRS.has( entry.name ) ) {
			continue;
		}
		const full = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			if ( dir !== '.' ) {
				out.push( ...markdownFiles( full, depth + 1 ) );
			}
			continue;
		}
		if ( entry.name.endsWith( '.md' ) ) {
			out.push( full );
		}
	}
	return out;
}

const files = [ ...new Set( ROOTS.flatMap( ( r ) => markdownFiles( r ) ) ) ];
const problems = [];

for ( const file of files ) {
	const text = fs.readFileSync( file, 'utf8' );
	const dir = path.dirname( file );

	// [label](target) - target only, ignoring any "title" after a space.
	const linkPattern = /\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g;
	let match;
	while ( ( match = linkPattern.exec( text ) ) !== null ) {
		const target = match[ 1 ];

		// External, protocol-relative, anchor-only, or mailto - nothing on disk to check.
		if (
			/^[a-z][a-z0-9+.-]*:/i.test( target ) ||
			target.startsWith( '//' ) ||
			target.startsWith( '#' )
		) {
			continue;
		}

		const [ filePart ] = target.split( '#' );
		if ( filePart === '' ) {
			continue;
		}

		const resolved = path.resolve( dir, filePart );
		if ( ! fs.existsSync( resolved ) ) {
			const line = text.slice( 0, match.index ).split( '\n' ).length;
			problems.push( `${ file }:${ line }  ->  ${ target }` );
		}
	}
}

if ( problems.length ) {
	console.error( 'Broken relative links in documentation:\n' );
	problems.forEach( ( p ) => console.error( '  ' + p ) );
	console.error( `\n${ problems.length } broken link(s).` );
	process.exit( 1 );
}

console.log( `doc links ok (${ files.length } files)` );
