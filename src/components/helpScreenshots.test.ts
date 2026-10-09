import { readFileSync, readdirSync } from 'fs';
import { join } from 'path';
import { headingText } from '../lib/helpPage';

/**
 * The screenshot manifest's guard: every help page has a shot, each shot follows a heading its page really has, and
 * every shot describes itself in both languages.
 */
const DOCS = join( __dirname, '../../beyond-elysium/docs' );
const MANIFEST = join( DOCS, 'help', 'screenshots.json' );

interface Step {
	click?: string | Record< string, string >;
	select?: string | Record< string, string >;
	fill?: string | Record< string, string >;
	focus?: string | Record< string, string >;
	value?: string;
}

interface Shot {
	heading: string;
	path: string;
	account: string;
	height?: number;
	crop?: string | Record< string, string >;
	steps?: Step[];
	alt: { en: string; pt_BR: string };
}

const manifest = JSON.parse( readFileSync( MANIFEST, 'utf-8' ) ) as {
	chronicle: string;
	viewport: { width: number; height: number };
	pages: Record< string, Shot[] >;
};

const helpKeys = readdirSync( join( DOCS, 'help' ) )
	.filter( ( file ) => file.endsWith( '.md' ) )
	.map( ( file ) => file.replace( /\.md$/, '' ) );

/**
 * The text of every heading in a Markdown file, outside code fences.
 */
function headings( file: string ): string[] {
	let fenced = false;
	const found: string[] = [];
	for ( const line of readFileSync( file, 'utf-8' ).split( '\n' ) ) {
		if ( /^\s*```/.test( line ) ) {
			fenced = ! fenced;
			continue;
		}
		const heading = ! fenced && line.match( /^#{1,6}\s+(.*?)\s*#*\s*$/ );
		if ( heading ) {
			found.push( headingText( heading[ 1 ] ) );
		}
	}
	return found;
}

const shots = Object.entries( manifest.pages ).flatMap( ( [ key, list ] ) =>
	list.map( ( shot, index ) => ( { key, number: index + 1, shot } ) )
);

test( 'the manifest declares the 1280 by 800 viewport and the demo chronicle', () => {
	expect( manifest.viewport ).toEqual( { width: 1280, height: 800 } );
	expect( manifest.chronicle ).toBe( 'be-demo' );
} );

test( 'every key in the manifest is a help page', () => {
	expect(
		Object.keys( manifest.pages ).filter(
			( key ) => ! helpKeys.includes( key )
		)
	).toEqual( [] );
} );

test( 'every help page has at least one screenshot', () => {
	expect(
		helpKeys.filter( ( key ) => ! ( manifest.pages[ key ]?.length > 0 ) )
	).toEqual( [] );
} );

test( 'every screenshot follows a heading its English and Portuguese pages both have', () => {
	const missing: string[] = [];
	for ( const { key, number, shot } of shots ) {
		const english = headings( join( DOCS, 'help', `${ key }.md` ) );
		const portuguese = headings(
			join( DOCS, 'pt_BR', 'help', `${ key }.md` )
		);
		const at = english.indexOf( shot.heading );
		if ( at === -1 || portuguese[ at ] === undefined ) {
			missing.push( `${ key }-${ number }: ${ shot.heading }` );
		}
	}
	expect( missing ).toEqual( [] );
} );

test( 'every screenshot has its own alt text in English and Portuguese', () => {
	const bad: string[] = [];
	for ( const { key, number, shot } of shots ) {
		const { en, pt_BR: pt } = shot.alt ?? {};
		if (
			typeof en !== 'string' ||
			typeof pt !== 'string' ||
			en.trim() === '' ||
			pt.trim() === '' ||
			en === pt ||
			/^(image|screenshot|picture) of/i.test( en )
		) {
			bad.push( `${ key }-${ number }` );
		}
	}
	expect( bad ).toEqual( [] );
} );

test( 'no two shots on one page share alt text', () => {
	const repeated = Object.entries( manifest.pages )
		.filter(
			( [ , list ] ) =>
				new Set( list.map( ( shot ) => shot.alt.en ) ).size !==
				list.length
		)
		.map( ( [ key ] ) => key );
	expect( repeated ).toEqual( [] );
} );

test( 'no two shots on the site share alt text, in either language', () => {
	for ( const language of [ 'en', 'pt_BR' ] as const ) {
		const seen = new Set< string >();
		const repeated = shots
			.map( ( { shot } ) => shot.alt[ language ] )
			.filter( ( alt ) =>
				seen.has( alt ) ? true : ! seen.add( alt )
			);
		expect( repeated ).toEqual( [] );
	}
} );

test( 'every shot names a known account, a local path and valid steps', () => {
	const bad: string[] = [];
	for ( const { key, number, shot } of shots ) {
		const steps = shot.steps ?? [];
		const stepsOk = steps.every( ( step ) => {
			const actions = [ 'click', 'select', 'fill', 'focus' ].filter(
				( action ) => action in step
			);
			const needsValue = 'select' in step || 'fill' in step;
			return (
				actions.length === 1 &&
				( ! needsValue || typeof step.value === 'string' )
			);
		} );
		if (
			! [ 'admin', 'storyteller', 'player' ].includes( shot.account ) ||
			! shot.path.startsWith( '/' ) ||
			! stepsOk ||
			( shot.height !== undefined &&
				! (
					shot.height > 0 && shot.height <= manifest.viewport.height
				) )
		) {
			bad.push( `${ key }-${ number }` );
		}
	}
	expect( bad ).toEqual( [] );
} );
