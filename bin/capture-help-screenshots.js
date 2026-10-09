#!/usr/bin/env node
/**
 * Captures every help screenshot declared in beyond-elysium/docs/help/screenshots.json from the local development
 * site, once with the site in English and once in Portuguese (Brazil), into dist/help-screenshots/{language}/.
 *
 * Usage: node bin/capture-help-screenshots.js [--lang en|pt_BR] [--only key,key] [--out dir]
 *
 * Environment: BE_SITE (default http://localhost:8910), BE_WP_PATH (the WordPress folder, default
 * ~/owbn/_localwp), BE_WP_CLI (default wp), BE_PASSWORD_ADMIN and BE_PASSWORD_PLAYER.
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { chromium } = require( 'playwright' );

const MANIFEST = path.join(
	__dirname,
	'..',
	'beyond-elysium',
	'docs',
	'help',
	'screenshots.json'
);
const LANGUAGES = { en: '', pt_BR: 'pt_BR' };
const SITE = process.env.BE_SITE || 'http://localhost:8910';
const WP_PATH =
	process.env.BE_WP_PATH || path.join( os.homedir(), 'owbn', '_localwp' );
const WP_CLI = process.env.BE_WP_CLI || 'wp';

const ACCOUNTS = {
	admin: [ 'admin', process.env.BE_PASSWORD_ADMIN || 'admin' ],
	storyteller: [ 'admin', process.env.BE_PASSWORD_ADMIN || 'admin' ],
	player: [ 'pw_player', process.env.BE_PASSWORD_PLAYER || 'pw_player' ],
};

// How long a screen must go without a new request to count as settled.
const QUIET_MS = 400;

const HIDDEN_CHROME = [
	'#wpadminbar, .open-accessibility-widget-wrapper, .open-accessibility-skip-to-content-link { display: none !important; }',
	'#wpbody-content > .notice, #wpbody-content .wrap > .notice, #wpbody-content .wrap > .updated, #wpbody-content .wrap > .error { display: none !important; }',
	'html.wp-toolbar { padding-top: 0 !important; } html { margin-top: 0 !important; }',
].join( ' ' );

/**
 * The command-line options.
 */
function options() {
	const args = process.argv.slice( 2 );
	const value = ( flag ) => {
		const at = args.indexOf( flag );
		return at === -1 ? null : args[ at + 1 ];
	};
	const lang = value( '--lang' );
	if ( lang && ! ( lang in LANGUAGES ) ) {
		throw new Error( `--lang is en or pt_BR, not ${ lang }` );
	}
	return {
		languages: lang ? [ lang ] : Object.keys( LANGUAGES ),
		only: value( '--only' ) ? value( '--only' ).split( ',' ) : null,
		out: path.resolve(
			value( '--out' ) ||
				path.join( __dirname, '..', 'dist', 'help-screenshots' )
		),
	};
}

/**
 * Runs WP-CLI against the local site and returns its output lines.
 *
 * @param {string[]} args
 */
function wp( args ) {
	try {
		return execFileSync( WP_CLI, [ `--path=${ WP_PATH }`, ...args ], {
			encoding: 'utf8',
			env: {
				...process.env,
				WP_CLI_PHP_ARGS:
					'-d error_reporting=0 -d display_errors=0 -d memory_limit=2G',
			},
			stdio: [ 'ignore', 'pipe', 'ignore' ],
		} ).split( '\n' );
	} catch ( error ) {
		throw new Error( `wp ${ args.slice( 0, 2 ).join( ' ' ) } failed` );
	}
}

/**
 * The value of the site's language option, or an empty string for English.
 */
function siteLanguage() {
	const lines = wp( [ 'option', 'get', 'WPLANG', '--format=json' ] );
	const found = lines.find( ( line ) => /^"[A-Za-z_]*"$/.test( line ) );
	if ( found === undefined ) {
		throw new Error( 'Could not read the site language' );
	}
	return JSON.parse( found );
}

/**
 * Switches the site to a language ('' is English).
 *
 * @param {string} language
 */
function setSiteLanguage( language ) {
	if ( language !== '' && ! /^[a-z]{2}_[A-Z]{2}$/.test( language ) ) {
		throw new Error( `Not a language: ${ language }` );
	}
	wp( [ 'option', 'update', 'WPLANG', language ] );
}

/**
 * Character ids by name for a chronicle.
 *
 * @param {string} slug
 */
function characterIds( slug ) {
	const prefix = wp( [ 'db', 'prefix' ] ).find( ( line ) =>
		/^[A-Za-z0-9]+_$/.test( line )
	);
	if ( ! prefix ) {
		throw new Error( 'Could not read the table prefix' );
	}
	const rows = wp( [
		'db',
		'query',
		`SELECT id, name FROM ${ prefix }be_characters WHERE owner_slug = '${ slug }'`,
		'--skip-column-names',
	] );
	const ids = {};
	for ( const line of rows ) {
		const match = line.match( /^(\d+)\t(.+)$/ );
		if ( match ) {
			ids[ match[ 2 ] ] = match[ 1 ];
		}
	}
	return ids;
}

/**
 * Puts the demo's NPC casting on its next upcoming session, so the casting brief shows on the cast account's screens.
 *
 * @param {string} slug
 */
function castOnUpcomingSession( slug ) {
	const prefix = wp( [ 'db', 'prefix' ] ).find( ( line ) =>
		/^[A-Za-z0-9]+_$/.test( line )
	);
	wp( [
		'db',
		'query',
		`UPDATE ${ prefix }be_npc_castings c JOIN ${ prefix }be_games g ON g.id = c.game_id SET c.session_id = ( SELECT s.id FROM ${ prefix }be_game_sessions s WHERE s.game_id = g.id AND s.game_date >= CURDATE() ORDER BY s.game_date LIMIT 1 ) WHERE g.slug = '${ slug }'`,
	] );
}

/**
 * Creates each fixture page the manifest names that the site does not have yet.
 *
 * @param {{slug: string, title: string, content: string}[]} fixtures
 */
function ensureFixturePages( fixtures ) {
	for ( const page of fixtures ) {
		const found = wp( [
			'post',
			'list',
			'--post_type=page',
			`--name=${ page.slug }`,
			'--field=ID',
		] ).filter( ( line ) => /^\d+$/.test( line ) );
		if ( found.length === 0 ) {
			wp( [
				'post',
				'create',
				'--post_type=page',
				'--post_status=publish',
				`--post_name=${ page.slug }`,
				`--post_title=${ page.title }`,
				`--post_content=${ page.content }`,
			] );
		}
	}
}

/**
 * Replaces each `{character:Name}` in a path with the character's id.
 *
 * @param {string} target
 * @param {Object} ids
 */
function resolvePath( target, ids ) {
	return target.replace( /\{character:([^}]+)\}/g, ( whole, name ) => {
		if ( ! ids[ name ] ) {
			throw new Error( `No character named ${ name } in the demo` );
		}
		return ids[ name ];
	} );
}

/**
 * A selector, which a step may give per language.
 *
 * @param {string|Object} selector
 * @param {string}        language
 */
function pick( selector, language ) {
	return typeof selector === 'string' ? selector : selector[ language ];
}

/**
 * Signs an account in and returns its browser context.
 *
 * @param {Object} browser
 * @param {string} account
 * @param {Object} viewport
 */
async function signIn( browser, account, viewport ) {
	const [ login, password ] = ACCOUNTS[ account ];
	const context = await browser.newContext( { viewport } );
	await context.route(
		( url ) => /^https?:$/.test( url.protocol ) && url.origin !== SITE,
		( route ) => route.abort()
	);
	const page = await context.newPage();
	await page.goto( `${ SITE }/wp-login.php` );
	await page.fill( '#user_login', login );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'networkidle' );
	if ( page.url().includes( 'wp-login.php' ) ) {
		throw new Error( `Could not sign in as ${ login }` );
	}
	await page.close();
	return context;
}

/**
 * Follows a page's requests, so a screen can be called settled once none is in flight and none has started lately.
 *
 * @param {Object} page
 */
function trackRequests( page ) {
	let pending = 0;
	let lastChange = Date.now();
	const changed = ( delta ) => () => {
		pending += delta;
		lastChange = Date.now();
	};
	page.on( 'request', changed( 1 ) );
	page.on( 'requestfinished', changed( -1 ) );
	page.on( 'requestfailed', changed( -1 ) );
	return async function quiet() {
		while ( pending > 0 || Date.now() - lastChange < QUIET_MS ) {
			await new Promise( ( resolve ) => setTimeout( resolve, 50 ) );
		}
	};
}

/**
 * Waits until the screen has drawn: the mount has content, nothing is loading and no request is in flight.
 *
 * @param {Object}   page
 * @param {Function} quiet
 */
async function settle( page, quiet ) {
	await quiet();
	await page.waitForFunction( () => {
		const mount = document.querySelector( '[data-be-widget]' );
		const drawn = mount
			? mount.children.length > 0
			: !! document.querySelector( '#wpbody-content' );
		return (
			drawn &&
			! /^(Loading|Carregando)[^\n]*…$/m.test(
				document.body.innerText
			) &&
			! document.querySelector(
				'.components-spinner, [aria-busy="true"], .be-loading'
			)
		);
	} );
	await quiet();
	await page.evaluate( () => document.fonts.ready );
}

/**
 * Runs a shot's steps in order.
 *
 * @param {Object}   page
 * @param {Object[]} steps
 * @param {string}   language
 * @param {Function} quiet
 */
async function runSteps( page, steps, language, quiet ) {
	for ( const step of steps ) {
		if ( step.click ) {
			await page.locator( pick( step.click, language ) ).first().click();
		} else if ( step.select ) {
			await page
				.locator( pick( step.select, language ) )
				.first()
				.selectOption( step.value );
		} else if ( step.fill ) {
			await page
				.locator( pick( step.fill, language ) )
				.first()
				.fill( step.value );
		} else if ( step.open ) {
			await page
				.locator( pick( step.open, language ) )
				.first()
				.evaluate( ( el ) => {
					el.open = true;
				} );
		} else if ( step.focus ) {
			await page
				.locator( pick( step.focus, language ) )
				.first()
				.evaluate( ( el ) =>
					el.scrollIntoView( { block: 'start', behavior: 'instant' } )
				);
		} else {
			throw new Error( `Unknown step ${ JSON.stringify( step ) }` );
		}
		await settle( page, quiet );
	}
}

/**
 * Encodes a PNG as WebP with the browser's own encoder.
 *
 * @param {Object} page
 * @param {Buffer} png
 */
async function toWebp( page, png ) {
	const encoded = await page.evaluate( async ( data ) => {
		const image = new Image();
		image.src = `data:image/png;base64,${ data }`;
		await image.decode();
		const canvas = document.createElement( 'canvas' );
		canvas.width = image.width;
		canvas.height = image.height;
		canvas.getContext( '2d' ).drawImage( image, 0, 0 );
		return canvas.toDataURL( 'image/webp', 0.9 ).split( ',' )[ 1 ];
	}, png.toString( 'base64' ) );
	return Buffer.from( encoded, 'base64' );
}

/**
 * Takes one shot: opens the screen, runs the steps and captures the cropped element or the viewport.
 *
 * @param {Object} context
 * @param {Object} shot
 * @param {Object} viewport
 * @param {string} language
 * @param {Object} ids
 */
async function capture( context, shot, viewport, language, ids ) {
	const page = await context.newPage();
	const quiet = trackRequests( page );
	try {
		await page.goto( SITE + resolvePath( shot.path, ids ), {
			waitUntil: 'domcontentloaded',
		} );
		await page.addStyleTag( { content: HIDDEN_CHROME } );
		await settle( page, quiet );
		await runSteps( page, shot.steps || [], language, quiet );

		const { pathname } = new URL( page.url() );
		const widget = ! shot.crop && ! pathname.startsWith( '/wp-admin/' );
		const cropSelector =
			shot.crop ||
			( pathname.startsWith( '/wp-admin/' )
				? '#wpbody-content'
				: '[data-be-widget]' );
		const target = page.locator( pick( cropSelector, language ) ).first();
		await target.evaluate( ( el ) =>
			el.scrollIntoView( { block: 'start', behavior: 'instant' } )
		);
		const box = await target.boundingBox();
		const margin = widget ? 16 : 0;
		const x = Math.max( 0, box.x - margin );
		const y = Math.max( 0, box.y );
		return await page.screenshot( {
			type: 'png',
			clip: {
				x,
				y,
				width: Math.min( viewport.width - x, box.width + 2 * margin ),
				height: Math.min(
					viewport.height - y,
					box.height,
					shot.height || viewport.height
				),
			},
		} );
	} finally {
		await page.close();
	}
}

async function main() {
	const { languages, only, out } = options();
	const manifest = JSON.parse( fs.readFileSync( MANIFEST, 'utf8' ) );
	const viewport = manifest.viewport;
	const keys = Object.keys( manifest.pages ).filter(
		( key ) => ! only || only.includes( key )
	);
	ensureFixturePages( manifest.fixturePages || [] );
	castOnUpcomingSession( manifest.chronicle );
	const ids = characterIds( manifest.chronicle );
	const original = siteLanguage();
	const browser = await chromium.launch( { channel: 'chrome' } );
	const failures = [];
	let taken = 0;

	try {
		for ( const language of languages ) {
			setSiteLanguage( LANGUAGES[ language ] );
			fs.mkdirSync( path.join( out, language ), { recursive: true } );
			const contexts = {};
			const encoder = await ( await browser.newContext() ).newPage();

			for ( const key of keys ) {
				for ( const [ index, shot ] of manifest.pages[
					key
				].entries() ) {
					const file = path.join(
						out,
						language,
						`${ key }-${ index + 1 }.webp`
					);
					const started = Date.now();
					try {
						contexts[ shot.account ] ||= await signIn(
							browser,
							shot.account,
							viewport
						);
						const png = await capture(
							contexts[ shot.account ],
							shot,
							viewport,
							language,
							ids
						);
						fs.writeFileSync( file, await toWebp( encoder, png ) );
						taken++;
						console.log(
							`${ language } ${ key }-${ index + 1 } ${ (
								( Date.now() - started ) /
								1000
							).toFixed( 1 ) }s`
						);
					} catch ( error ) {
						failures.push(
							`${ language } ${ key }-${ index + 1 }: ${
								error.message.split( '\n' )[ 0 ]
							}`
						);
					}
				}
			}
			for ( const context of Object.values( contexts ) ) {
				await context.close();
			}
		}
	} finally {
		setSiteLanguage( original );
		await browser.close();
	}

	console.log( `Captured ${ taken } screenshots into ${ out }` );
	if ( failures.length ) {
		console.log( `\n${ failures.length } failed:` );
		failures.forEach( ( failure ) => console.log( `  ${ failure }` ) );
		process.exitCode = 1;
	}
}

main().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
