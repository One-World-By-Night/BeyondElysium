#!/usr/bin/env node
/**
 * Converts every beyond-elysium/docs/help/*.md file and the four guides into the Gutenberg block HTML beyondelysium.com's
 * BetterDocs "docs" post type expects, resolving every internal cross-reference. Alongside each English page it
 * writes the page's screenshots and its Portuguese translation, paired block by block with the English one.
 *
 * Usage: node bin/sync-help-docs.js [output.json]
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { buildDocs } = require( '../tools/help-docs/build' );

const DOCS_DIR = path.join( __dirname, '..', 'beyond-elysium', 'docs' );
const OUT =
	process.argv[ 2 ] || path.join( __dirname, '..', 'dist', 'help-docs.json' );

function main() {
	const { docs, unresolved, shotProblems, conflicts } = buildDocs( DOCS_DIR );

	fs.mkdirSync( path.dirname( OUT ), { recursive: true } );
	fs.writeFileSync( OUT, JSON.stringify( { pages: docs }, null, 2 ) );

	const paired = docs.filter( ( doc ) => doc.portuguese ).length;
	console.log(
		`Converted ${ docs.length } pages -> ${ OUT } (${ paired } paired with Portuguese)`
	);

	let failed = false;
	if ( unresolved.length ) {
		failed = true;
		console.log(
			'\nUNRESOLVED LINKS (left untouched - check these by hand):'
		);
		for ( const { file, language, missed } of unresolved ) {
			console.log( `  ${ language } ${ file }:`, missed );
		}
	}
	if ( shotProblems.length ) {
		failed = true;
		console.log( '\nSCREENSHOTS THAT FOLLOW NO HEADING:' );
		shotProblems.forEach( ( problem ) => console.log( `  ${ problem }` ) );
	}
	if ( conflicts.length ) {
		failed = true;
		console.log(
			'\nENGLISH STRINGS WITH TWO PORTUGUESE TRANSLATIONS (the website keeps one):'
		);
		for ( const conflict of conflicts ) {
			console.log( `  ${ conflict.original }` );
			conflict.versions.forEach( ( version ) =>
				console.log( `    -> ${ version.translated }` )
			);
		}
	}
	for ( const doc of docs.filter( ( entry ) => ! entry.portuguese ) ) {
		console.log(
			`\nStays English: ${ doc.slug } - ${ doc.unpaired.join( '; ' ) }`
		);
	}
	if ( ! failed ) {
		console.log( '\nEvery internal link resolved; no conflicts.' );
	}
	process.exitCode = failed ? 1 : 0;
}

main();
