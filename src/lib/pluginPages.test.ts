import {
	characterEditorUrl,
	characterSheetUrl,
	isPrintCanvasPath,
	playerTabUrl,
	storytellerTabUrl,
	pluginPageUrl,
	readTabFromUrl,
	writeTabToUrl,
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
	it( 'builds an absolute URL from a slug', () => {
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
