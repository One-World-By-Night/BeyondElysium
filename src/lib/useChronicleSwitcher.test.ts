import { readGameSlugFromUrl, writeGameSlugToUrl } from './useChronicleSwitcher';

function setLocation( href: string ): void {
	window.history.replaceState( {}, '', href );
}

describe( 'readGameSlugFromUrl', () => {
	it( 'reads a present game_slug param', () => {
		setLocation( 'http://localhost/be-player/?game_slug=kony' );
		expect( readGameSlugFromUrl() ).toBe( 'kony' );
	} );

	it( 'returns an empty string when absent', () => {
		setLocation( 'http://localhost/be-player/' );
		expect( readGameSlugFromUrl() ).toBe( '' );
	} );

	it( 'reads correctly alongside other params', () => {
		setLocation( 'http://localhost/be-player/?tab=sheet&game_slug=boston&character_id=42' );
		expect( readGameSlugFromUrl() ).toBe( 'boston' );
	} );
} );

describe( 'writeGameSlugToUrl', () => {
	it( 'adds game_slug when absent', () => {
		setLocation( 'http://localhost/be-player/' );
		writeGameSlugToUrl( 'kony' );
		expect( readGameSlugFromUrl() ).toBe( 'kony' );
	} );

	it( 'replaces an existing game_slug', () => {
		setLocation( 'http://localhost/be-player/?game_slug=kony' );
		writeGameSlugToUrl( 'boston' );
		expect( readGameSlugFromUrl() ).toBe( 'boston' );
	} );

	it( 'preserves other params', () => {
		setLocation( 'http://localhost/be-player/?tab=sheet&character_id=42' );
		writeGameSlugToUrl( 'kony' );
		const params = new URLSearchParams( window.location.search );
		expect( params.get( 'tab' ) ).toBe( 'sheet' );
		expect( params.get( 'character_id' ) ).toBe( '42' );
		expect( params.get( 'game_slug' ) ).toBe( 'kony' );
	} );
} );
