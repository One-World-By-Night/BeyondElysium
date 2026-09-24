import {
	characterEditorUrl,
	characterSheetUrl,
	isPrintCanvasPath,
	playerTabUrl,
	storytellerTabUrl,
	pluginPageUrl,
	readTabFromUrl,
	writeTabToUrl,
	preselectedChronicle,
	writeGameToUrl,
	sendFileLinkUrl,
	PLAYER_TABS,
	STORYTELLER_TABS,
} from './pluginPages';

const ORIGIN = 'http://localhost';

function setLocation( href: string ): void {
	window.history.replaceState( {}, '', href );
}

beforeEach( () => {
	setLocation( `${ ORIGIN }/` );
} );

describe( 'pluginPageUrl', () => {
	afterEach( () => {
		delete window.beyondElysium;
	} );

	it( 'builds an absolute URL from a slug', () => {
		expect( pluginPageUrl( 'be-player' ) ).toBe( `${ ORIGIN }/be-player/` );
	} );

	/**
	 * A multisite subsite lives under a path of its network's origin.
	 */
	it( 'keeps a multisite subsite path, building from homeUrl rather than the origin', () => {
		window.beyondElysium = {
			restUrl: 'https://chronicles.owbn.net/bbf/wp-json/be/v1/',
			homeUrl: 'https://chronicles.owbn.net/bbf/',
			nonce: 'n',
			version: '1.2.11',
		};

		expect( pluginPageUrl( 'be-player' ) ).toBe(
			'https://chronicles.owbn.net/bbf/be-player/'
		);
		expect( pluginPageUrl( 'be-player' ) ).not.toBe(
			'https://chronicles.owbn.net/be-player/'
		);
	} );

	it( 'tolerates a homeUrl with no trailing slash, and never doubles the separator', () => {
		window.beyondElysium = {
			restUrl: 'https://chronicles.owbn.net/bbf/wp-json/be/v1/',
			homeUrl: 'https://chronicles.owbn.net/bbf',
			nonce: 'n',
			version: '1.2.11',
		};

		expect( pluginPageUrl( 'be-player' ) ).toBe(
			'https://chronicles.owbn.net/bbf/be-player/'
		);
	} );

	/**
	 * A payload from before this field existed must still build a usable link.
	 */
	it( 'falls back to the origin when homeUrl is absent', () => {
		window.beyondElysium = {
			restUrl: `${ ORIGIN }/wp-json/be/v1/`,
			nonce: 'n',
			version: '1.2.11',
		};

		expect( pluginPageUrl( 'be-player' ) ).toBe( `${ ORIGIN }/be-player/` );
	} );
} );

describe( 'playerTabUrl', () => {
	it( 'builds the base URL for one tab, with no selection yet', () => {
		expect( playerTabUrl( PLAYER_TABS.sheet ) ).toBe(
			`${ ORIGIN }/be-player/?tab=sheet`
		);
	} );
} );

describe( 'storytellerTabUrl', () => {
	it( 'builds the base URL for one tab, with no chronicle selection yet', () => {
		expect( storytellerTabUrl( STORYTELLER_TABS.dashboard ) ).toBe(
			`${ ORIGIN }/be-storyteller/?tab=dashboard`
		);
	} );
} );

describe( 'characterSheetUrl', () => {
	it( 'points at the Sheet tab with the character id and URL-encoded game slug', () => {
		expect( characterSheetUrl( 42, 'kony' ) ).toBe(
			`${ ORIGIN }/be-player/?tab=sheet&character_id=42&game_slug=kony`
		);
	} );

	it( 'URL-encodes a game slug with special characters', () => {
		expect( characterSheetUrl( 1, 'a b' ) ).toBe(
			`${ ORIGIN }/be-player/?tab=sheet&character_id=1&game_slug=a%20b`
		);
	} );
} );

describe( 'characterEditorUrl', () => {
	it( 'points at the Edit tab with the character id and URL-encoded game slug', () => {
		expect( characterEditorUrl( 42, 'kony' ) ).toBe(
			`${ ORIGIN }/be-player/?tab=edit&character_id=42&game_slug=kony`
		);
	} );
} );

describe( 'sendFileLinkUrl', () => {
	it( 'points at Send a Grapevine File with the URL-encoded chronicle already picked (F-122)', () => {
		expect( sendFileLinkUrl( 'kings of new york' ) ).toBe(
			`${ ORIGIN }/be-player/?tab=send-file&game_slug=kings%20of%20new%20york`
		);
	} );
} );

describe( 'isPrintCanvasPath', () => {
	it( 'matches the print-canvas path', () => {
		expect( isPrintCanvasPath( '/character-sheet-print/' ) ).toBe( true );
	} );

	it( 'does not match the ordinary player page path', () => {
		expect( isPrintCanvasPath( '/be-player/' ) ).toBe( false );
	} );

	it( 'matches when the print path is a prefix of a longer pathname', () => {
		expect( isPrintCanvasPath( '/character-sheet-print/index.html' ) ).toBe(
			true
		);
	} );
} );

describe( 'readTabFromUrl / writeTabToUrl', () => {
	it( 'reads a present tab param', () => {
		setLocation( `${ ORIGIN }/be-storyteller/?tab=approval-queue` );
		expect( readTabFromUrl( 'dashboard' ) ).toBe( 'approval-queue' );
	} );

	it( 'falls back to the default when absent', () => {
		setLocation( `${ ORIGIN }/be-storyteller/` );
		expect( readTabFromUrl( 'dashboard' ) ).toBe( 'dashboard' );
	} );

	it( 'writes tab without disturbing other params', () => {
		setLocation( `${ ORIGIN }/be-player/?game_slug=kony` );
		writeTabToUrl( 'edit' );
		const params = new URLSearchParams( window.location.search );
		expect( params.get( 'tab' ) ).toBe( 'edit' );
		expect( params.get( 'game_slug' ) ).toBe( 'kony' );
	} );
} );

describe( 'preselectedChronicle', () => {
	const kony = { slug: 'kony', name: 'Kony' };
	const boston = { slug: 'boston', name: 'Boston' };
	const demo = { slug: 'be-demo', name: 'Beyond Elysium Demo' };

	it( 'opens the chronicle the URL names', () => {
		setLocation( `${ ORIGIN }/?game=boston` );
		expect( preselectedChronicle( [ demo, kony, boston ] ) ).toBe( boston );
	} );

	it( 'ignores a chronicle that does not exist and falls back to the first real one', () => {
		setLocation( `${ ORIGIN }/?game=gone` );
		expect( preselectedChronicle( [ demo, kony, boston ] ) ).toBe( kony );
	} );

	it( 'prefers a real chronicle to the demo fixture that sorts first', () => {
		expect( preselectedChronicle( [ demo, kony ] ) ).toBe( kony );
	} );

	it( 'opens the demo when it is the only chronicle', () => {
		expect( preselectedChronicle( [ demo ] ) ).toBe( demo );
	} );

	it( 'returns null when there are no chronicles', () => {
		expect( preselectedChronicle( [] ) ).toBeNull();
	} );
} );

describe( 'writeGameToUrl', () => {
	it( 'writes the chronicle without disturbing the tab or other params', () => {
		setLocation( `${ ORIGIN }/wp-admin/admin.php?page=x&tab=apr` );
		writeGameToUrl( 'boston' );
		const params = new URLSearchParams( window.location.search );
		expect( params.get( 'game' ) ).toBe( 'boston' );
		expect( params.get( 'tab' ) ).toBe( 'apr' );
		expect( params.get( 'page' ) ).toBe( 'x' );
	} );

	it( 'replaces a chronicle already in the URL', () => {
		setLocation( `${ ORIGIN }/?game=kony` );
		writeGameToUrl( 'boston' );
		expect(
			new URLSearchParams( window.location.search ).getAll( 'game' )
		).toEqual( [ 'boston' ] );
	} );

	it( 'is what a later tab switch reads back', () => {
		setLocation( `${ ORIGIN }/?tab=setup` );
		writeGameToUrl( 'boston' );
		writeTabToUrl( 'access' );
		expect(
			preselectedChronicle( [ { slug: 'kony' }, { slug: 'boston' } ] )
				?.slug
		).toBe( 'boston' );
	} );
} );
