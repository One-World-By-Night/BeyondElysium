import { readFileSync, readdirSync } from 'fs';
import { join } from 'path';
import { GUIDES, GuideSlug, headingAnchors, helpTarget } from '../lib/helpPage';

/**
 * The help pages' coverage guard: every `?` opens a help page that exists, every link in a help page opens something
 * at a heading that is really there, and every help page is reachable.
 */
const DOCS = join( __dirname, '../../beyond-elysium/docs' );
const HELP = join( DOCS, 'help' );

const helpKeys = readdirSync( HELP )
	.filter( ( file ) => file.endsWith( '.md' ) )
	.map( ( file ) => file.replace( /\.md$/, '' ) );

const read = ( file: string ) => readFileSync( file, 'utf-8' );

function sourceFiles( dir: string ): string[] {
	return readdirSync( dir, { withFileTypes: true } ).flatMap( ( entry ) => {
		const path = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			return sourceFiles( path );
		}
		return /\.tsx?$/.test( entry.name ) &&
			! /\.test\.tsx?$/.test( entry.name )
			? [ path ]
			: [];
	} );
}

/**
 * Every `helpKey="..."` a screen passes to its `?`.
 */
const usedKeys = new Set(
	sourceFiles( join( __dirname, '..' ) ).flatMap( ( file ) =>
		[ ...read( file ).matchAll( /helpKey="([a-z0-9-]+)"/g ) ].map(
			( match ) => match[ 1 ]
		)
	)
);

/**
 * Screens whose `?` is not wired to a help page.
 */
const WAITING_FOR_PILOT_REVIEW = [
	'profile-settings',
	'provisioned-pages',
	'signed-sheets',
];

/**
 * Pages no single screen owns: concepts and reference, reached by links from other pages.
 */
const LINKED_ONLY = [
	'approval-flow',
	'chronicles',
	'grapevine',
	'roles',
	'storyteller-only',
];

test( 'every help key a screen uses has a help page', () => {
	expect( usedKeys.size ).toBeGreaterThan( 0 );
	for ( const key of usedKeys ) {
		expect( helpKeys ).toContain( key );
	}
} );

test( 'every link in a help page opens a page, at a heading it really has', () => {
	const anchors = new Map< string, string[] >();
	const anchorsOf = ( file: string ) => {
		if ( ! anchors.has( file ) ) {
			anchors.set( file, headingAnchors( read( file ) ) );
		}
		return anchors.get( file ) as string[];
	};

	const broken: string[] = [];
	for ( const key of helpKeys ) {
		const file = join( HELP, `${ key }.md` );
		for ( const [ , href ] of read( file ).matchAll(
			/\]\(([^)\s]+)\)/g
		) ) {
			const target = helpTarget( href );
			if ( ! target ) {
				broken.push( `${ key }: ${ href }` );
				continue;
			}
			if ( target.kind === 'external' ) {
				continue;
			}
			let targetFile = file;
			if ( target.kind === 'help' ) {
				if ( ! helpKeys.includes( target.key ) ) {
					broken.push( `${ key }: ${ href }` );
					continue;
				}
				targetFile = join( HELP, `${ target.key }.md` );
			} else if ( target.kind === 'guide' ) {
				targetFile = join( DOCS, `${ target.slug }.md` );
			}
			if (
				target.anchor &&
				! anchorsOf( targetFile ).includes( target.anchor )
			) {
				broken.push( `${ key }: ${ href }` );
			}
		}
	}
	expect( broken ).toEqual( [] );
} );

/**
 * The pages and guides that have a Portuguese translation, as paths under the documentation folder.
 */
const PORTUGUESE = join( DOCS, 'pt_BR' );
const portuguesePages = ( dir: string ): string[] =>
	readdirSync( dir ).filter( ( file ) => file.endsWith( '.md' ) );

test( 'every link in a Portuguese help page opens a heading its target really has', () => {
	const translatedHelp = portuguesePages( join( PORTUGUESE, 'help' ) );
	const translatedGuides = portuguesePages( PORTUGUESE );
	expect( translatedHelp.length ).toBeGreaterThan( 0 );

	const broken: string[] = [];
	const pages = [
		...translatedHelp.map( ( file ) => join( PORTUGUESE, 'help', file ) ),
		...translatedGuides.map( ( file ) => join( PORTUGUESE, file ) ),
	];
	for ( const file of pages ) {
		const own = file.slice( PORTUGUESE.length + 1 );
		for ( const [ , href ] of read( file ).matchAll(
			/\]\(([^)\s]+)\)/g
		) ) {
			const found = helpTarget( href );
			const target =
				found?.kind === 'help' &&
				( GUIDES as readonly string[] ).includes( found.key )
					? {
							kind: 'guide' as const,
							slug: found.key as GuideSlug,
							anchor: found.anchor,
						}
					: found;
			if ( ! target ) {
				// A guide, unlike a help page, also links to files that are not help pages, such as the README.
				if ( ! own.includes( '/' ) ) {
					continue;
				}
				broken.push( `${ own }: ${ href }` );
				continue;
			}
			if ( target.kind === 'external' ) {
				continue;
			}
			let targetFile = file;
			if ( target.kind === 'help' ) {
				if ( ! helpKeys.includes( target.key ) ) {
					broken.push( `${ own }: ${ href }` );
					continue;
				}
				if ( ! translatedHelp.includes( `${ target.key }.md` ) ) {
					continue;
				}
				targetFile = join( PORTUGUESE, 'help', `${ target.key }.md` );
			} else if ( target.kind === 'guide' ) {
				if ( ! translatedGuides.includes( `${ target.slug }.md` ) ) {
					continue;
				}
				targetFile = join( PORTUGUESE, `${ target.slug }.md` );
			}
			if (
				target.anchor &&
				! headingAnchors( read( targetFile ) ).includes( target.anchor )
			) {
				broken.push( `${ own }: ${ href }` );
			}
		}
	}
	expect( broken ).toEqual( [] );
} );

test( 'every help page is reachable from a screen or from another page', () => {
	const linked = new Set(
		helpKeys.flatMap( ( key ) =>
			[
				...read( join( HELP, `${ key }.md` ) ).matchAll(
					/\]\(([a-z0-9-]+)\.md/g
				),
			]
				.map( ( match ) => match[ 1 ] )
				.filter( ( target ) => target !== key )
		)
	);

	for ( const key of LINKED_ONLY ) {
		expect( helpKeys ).toContain( key );
		expect( linked ).toContain( key );
	}
	for ( const key of WAITING_FOR_PILOT_REVIEW ) {
		expect( helpKeys ).toContain( key );
		expect( usedKeys ).not.toContain( key );
	}
	const unreachable = helpKeys.filter(
		( key ) =>
			! usedKeys.has( key ) &&
			! LINKED_ONLY.includes( key ) &&
			! WAITING_FOR_PILOT_REVIEW.includes( key )
	);
	expect( unreachable ).toEqual( [] );
} );
