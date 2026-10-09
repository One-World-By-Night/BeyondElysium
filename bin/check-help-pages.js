#!/usr/bin/env node
/**
 * Reads the help pages on the website, English and Portuguese, and checks each one against what bin/sync-help-docs.js
 * wrote: every heading carries the id the page's links point at, every screenshot sits under its heading with its
 * alt text, and the Portuguese page shows the Portuguese strings, screenshots and alt text and none of the English.
 * Only reads: it sends GET requests and nothing else.
 *
 * Usage: node bin/check-help-pages.js [--site https://beyondelysium.com] [--only slug,slug] [--file help-docs.json]
 *        [--header 'Name: value'] [--prefix trial-]
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const args = process.argv.slice( 2 );
const option = ( flag, fallback ) => {
	const at = args.indexOf( flag );
	return at === -1 ? fallback : args[ at + 1 ];
};
const SITE = option( '--site', 'https://beyondelysium.com' ).replace(
	/\/+$/,
	''
);
const FILE = option(
	'--file',
	path.join( __dirname, '..', 'dist', 'help-docs.json' )
);
const ONLY = option( '--only', null );
const PREFIX = option( '--prefix', '' );
const HEADER = option( '--header', null );
const HEADERS = HEADER
	? Object.fromEntries( [ HEADER.split( /:\s*/, 2 ) ] )
	: {};

// An English string this long showing on a Portuguese page is a string that was not translated.
const LEAK_LENGTH = 25;

/**
 * The words a piece of text or markup shows, with the typography the website adds undone and white space collapsed.
 *
 * @param {string}  value
 * @param {boolean} markup Whether the value is HTML.
 */
function words( value, markup ) {
	const text = markup
		? new JSDOM( `<div>${ value }</div>` ).window.document.body.textContent
		: value;
	return text
		.replace( /[‘’]/g, "'" )
		.replace( /[“”]/g, '"' )
		.replace( /[–—]/g, '-' )
		.replace( /…/g, '...' )
		.replace( /-+/g, '-' )
		.replace( /×/g, 'x' )
		.replace( /[\s ]+/g, ' ' )
		.trim();
}

/**
 * Fetches a page and returns its content region and language, or the reason it could not.
 *
 * @param {string} url
 */
async function readPage( url ) {
	const response = await fetch( url, {
		redirect: 'follow',
		headers: HEADERS,
	} );
	if ( ! response.ok ) {
		return { problem: `${ url } answered ${ response.status }` };
	}
	const { document } = new JSDOM( await response.text(), {
		virtualConsole: new VirtualConsole(),
	} ).window;
	const root =
		document.querySelector( '#betterdocs-single-content' ) ||
		document.querySelector( '.entry-content' );
	if ( ! root ) {
		return { problem: `${ url } has no content region` };
	}
	return { root, language: document.documentElement.lang };
}

/**
 * What is wrong with one page's headings and screenshots in one language.
 *
 * @param {Object} page
 * @param {Object} root
 * @param {string} language
 */
function checkPage( page, root, language ) {
	const problems = [];
	const headings = [ ...root.querySelectorAll( 'h1, h2, h3, h4, h5, h6' ) ];
	const expected = page.blocks.filter( ( block ) =>
		block.startsWith( '<!-- wp:heading' )
	).length;
	const body = headings.filter( ( heading ) =>
		/^\d+-toc-title$/.test( heading.id )
	);
	if ( body.length !== expected ) {
		problems.push(
			`${ body.length } headings carry a numbered id, the page has ${ expected }`
		);
	}
	body.forEach( ( heading, index ) => {
		if ( heading.id !== `${ index }-toc-title` ) {
			problems.push(
				`heading ${ index } has the id ${ heading.id }, not ${ index }-toc-title`
			);
		}
	} );

	const suffix = language === 'en' ? 'en' : 'pt_BR';
	for ( const shot of page.shots ) {
		const named = new RegExp(
			`-${ PREFIX }${ page.slug }-${ shot.number }-${ suffix }(-\\d+)?\\.webp$`
		);
		const image = [ ...root.querySelectorAll( 'img' ) ].find( ( img ) =>
			named.test( img.getAttribute( 'src' ) || '' )
		);
		if ( ! image ) {
			problems.push( `no ${ suffix } screenshot ${ shot.number }` );
			continue;
		}
		const alt = language === 'en' ? shot.alt.en : shot.alt.pt_BR;
		if ( image.getAttribute( 'alt' ) !== alt ) {
			problems.push(
				`screenshot ${ shot.number } has the alt text "${ image.getAttribute(
					'alt'
				) }"`
			);
		}
		const figure = image.closest( 'figure' );
		const before = figure && figure.previousElementSibling;
		if ( ! before || ! /^H[1-6]$/.test( before.tagName ) ) {
			problems.push(
				`screenshot ${ shot.number } is not under a heading`
			);
		}
	}
	return problems;
}

/**
 * What is wrong with a Portuguese page's text: strings missing, or English strings left.
 *
 * @param {Object} page
 * @param {Object} root
 */
function checkPortuguese( page, root ) {
	const problems = [];
	const shown = words( root.textContent, false );
	const lacking = [];
	const leaked = [];
	for ( const pair of page.portuguese.pairs ) {
		const markup = pair.kind === 'block';
		const translated = words( pair.translated, markup );
		const original = words( pair.original, markup );
		if ( translated && ! shown.includes( translated ) ) {
			lacking.push( translated.slice( 0, 60 ) );
		}
		if (
			original.length >= LEAK_LENGTH &&
			shown.includes( original ) &&
			! translated.includes( original )
		) {
			leaked.push( original.slice( 0, 60 ) );
		}
	}
	if ( lacking.length ) {
		problems.push(
			`${ lacking.length } Portuguese strings missing, first: ${ lacking[ 0 ] }`
		);
	}
	if ( leaked.length ) {
		problems.push(
			`${ leaked.length } English strings left, first: ${ leaked[ 0 ] }`
		);
	}
	return problems;
}

async function main() {
	const { pages } = JSON.parse( fs.readFileSync( FILE, 'utf8' ) );
	const wanted = ONLY ? ONLY.split( ',' ) : null;
	const report = [];
	let checked = 0;

	for ( const page of pages ) {
		if ( wanted && ! wanted.includes( page.slug ) ) {
			continue;
		}
		const problems = [];
		const english = await readPage(
			`${ SITE }/docs/${ PREFIX }${ page.slug }/`
		);
		if ( english.problem ) {
			problems.push( english.problem );
		} else {
			problems.push( ...checkPage( page, english.root, 'en' ) );
		}
		if ( page.portuguese ) {
			const portuguese = await readPage(
				`${ SITE }/pt/docs/${ PREFIX }${ page.slug }/`
			);
			if ( portuguese.problem ) {
				problems.push( portuguese.problem );
			} else {
				if ( ! /^pt/i.test( portuguese.language ) ) {
					problems.push(
						`the Portuguese page says its language is "${ portuguese.language }"`
					);
				}
				problems.push(
					...checkPage( page, portuguese.root, 'pt_BR' ),
					...checkPortuguese( page, portuguese.root )
				);
			}
		}
		checked++;
		if ( problems.length ) {
			report.push(
				`${ page.slug }:\n    ${ problems.join( '\n    ' ) }`
			);
		}
	}

	console.log(
		`Checked ${ checked } pages on ${ SITE }: ${ checked - report.length } fine, ${ report.length } with problems`
	);
	report.forEach( ( entry ) => console.log( `  ${ entry }` ) );
	process.exitCode = report.length ? 1 : 0;
}

main().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
