import {
	barPlaces,
	characterHref,
	footerUnderWidget,
	isStaffRole,
	moreSections,
	otherPage,
	returningTo,
	sectionHref,
	withChronicle,
} from './appNav';

const player = [
	{ key: 'dashboard', label: 'Dashboard' },
	{ key: 'characters', label: 'Characters' },
	{ key: 'sheet', label: 'Sheet' },
	{ key: 'edit', label: 'Edit' },
	{ key: 'plots', label: 'My Plots & Rumors' },
	{ key: 'whos-who', label: "Who's Who" },
];

const playerPlaces = [
	{ key: 'dashboard', label: 'Home' },
	{ key: 'sheet', label: 'Character' },
	{ key: 'plots', label: 'Plots' },
];

describe( 'the bottom bar', () => {
	it( 'holds the preferred places with their own short labels', () => {
		expect( barPlaces( player, playerPlaces, 3 ) ).toEqual( playerPlaces );
	} );

	it( 'gives a place the role lacks to the next section the role has', () => {
		const narrator = [
			{ key: 'my-queue', label: 'My Queue' },
			{ key: 'plots', label: 'Plots & Rumors' },
			{ key: 'secrets', label: 'Secrets' },
			{ key: 'game-nights', label: 'Game Nights' },
		];
		const staffPlaces = [
			{ key: 'dashboard', label: 'Home' },
			{ key: 'approval-queue', label: 'Approvals' },
			{ key: 'characters', label: 'Characters' },
			{ key: 'plots', label: 'Plots' },
		];
		expect( barPlaces( narrator, staffPlaces, 4 ) ).toEqual( [
			{ key: 'plots', label: 'Plots' },
			{ key: 'my-queue', label: 'My Queue' },
			{ key: 'secrets', label: 'Secrets' },
			{ key: 'game-nights', label: 'Game Nights' },
		] );
	} );

	it( 'never holds more places than it has room for, nor more than the page has', () => {
		expect( barPlaces( player, playerPlaces, 2 ) ).toHaveLength( 2 );
		expect( barPlaces( player.slice( 0, 1 ), playerPlaces, 3 ) ).toEqual( [
			{ key: 'dashboard', label: 'Home' },
		] );
		expect( barPlaces( [], playerPlaces, 3 ) ).toEqual( [] );
	} );

	it( 'leaves every other section to More, in the page order', () => {
		expect(
			moreSections( player, barPlaces( player, playerPlaces, 3 ) ).map(
				( s ) => s.key
			)
		).toEqual( [ 'characters', 'edit', 'whos-who' ] );
	} );
} );

describe( 'the addresses', () => {
	const here =
		'https://example.test/kony/be-player/?game_slug=kony&tab=dashboard&character_id=4';

	it( 'opens a section and keeps the chronicle and character', () => {
		const url = new URL( sectionHref( here, 'plots' ) );
		expect( url.searchParams.get( 'tab' ) ).toBe( 'plots' );
		expect( url.searchParams.get( 'game_slug' ) ).toBe( 'kony' );
		expect( url.searchParams.get( 'character_id' ) ).toBe( '4' );
	} );

	it( 'opens another character on its Sheet, or on Edit when Edit is open', () => {
		const sheet = new URL( characterHref( here, 9, 'dashboard' ) );
		expect( sheet.searchParams.get( 'character_id' ) ).toBe( '9' );
		expect( sheet.searchParams.get( 'tab' ) ).toBe( 'sheet' );
		const edit = new URL( characterHref( here, 9, 'edit' ) );
		expect( edit.searchParams.get( 'tab' ) ).toBe( 'edit' );
	} );

	it( 'comes back to this page after signing in or out', () => {
		const signIn = new URL(
			returningTo( 'https://example.test/kony/wp-login.php', here )
		);
		expect( signIn.searchParams.get( 'redirect_to' ) ).toBe( here );
		const signOut = new URL(
			returningTo(
				'https://example.test/kony/wp-login.php?action=logout&_wpnonce=abc',
				here
			)
		);
		expect( signOut.searchParams.get( '_wpnonce' ) ).toBe( 'abc' );
		expect( signOut.searchParams.get( 'redirect_to' ) ).toBe( here );
	} );

	it( 'carries the chronicle to the other page', () => {
		expect(
			new URL(
				withChronicle(
					'https://example.test/kony/be-storyteller/?tab=dashboard',
					'kony'
				)
			).searchParams.get( 'game_slug' )
		).toBe( 'kony' );
	} );
} );

describe( 'the link to the Storyteller Toolkit', () => {
	it( 'shows for staff roles and not for a player', () => {
		expect( isStaffRole( 'hst' ) ).toBe( true );
		expect( isStaffRole( 'narrator' ) ).toBe( true );
		expect( isStaffRole( 'boons' ) ).toBe( true );
		expect( isStaffRole( 'player' ) ).toBe( false );
		expect( isStaffRole( undefined ) ).toBe( false );
	} );

	it( 'leads My Chronicle to the Toolkit for staff only, with the chronicle chosen', () => {
		for ( const role of [ 'hst', 'ast', 'narrator', 'boons' ] ) {
			const link = otherPage( 'player', role, 'kony' );
			expect( link?.label ).toBe( 'Storyteller Toolkit' );
			const url = new URL( link?.href ?? '' );
			expect( url.pathname ).toMatch( /\/be-storyteller\/$/ );
			expect( url.searchParams.get( 'game_slug' ) ).toBe( 'kony' );
		}
		expect( otherPage( 'player', 'player', 'kony' ) ).toBeNull();
		expect( otherPage( 'player', undefined, 'kony' ) ).toBeNull();
	} );

	it( 'leads the Toolkit back to My Chronicle whatever the role', () => {
		const link = otherPage( 'storyteller', undefined, 'kony' );
		expect( link?.label ).toBe( 'My Chronicle' );
		const url = new URL( link?.href ?? '' );
		expect( url.pathname ).toMatch( /\/be-player\/$/ );
		expect( url.searchParams.get( 'tab' ) ).toBe( 'dashboard' );
		expect( url.searchParams.get( 'game_slug' ) ).toBe( 'kony' );
		expect(
			new URL(
				otherPage( 'storyteller', undefined, '' )?.href ?? ''
			).searchParams.has( 'game_slug' )
		).toBe( false );
	} );
} );

describe( 'the Powered by footer under a widget', () => {
	it( 'is added under a page widget off the shell and under any other widget', () => {
		expect( footerUnderWidget( 'my-chronicle', false ) ).toBe( true );
		expect( footerUnderWidget( 'storyteller-toolkit-page', false ) ).toBe(
			true
		);
		expect( footerUnderWidget( 'character-sheet', true ) ).toBe( true );
	} );

	it( 'is left to the frame on the shell, and never added under an admin widget', () => {
		expect( footerUnderWidget( 'my-chronicle', true ) ).toBe( false );
		expect( footerUnderWidget( 'storyteller-toolkit-page', true ) ).toBe(
			false
		);
		expect( footerUnderWidget( 'admin-branding', false ) ).toBe( false );
		expect( footerUnderWidget( 'admin-branding', true ) ).toBe( false );
	} );
} );
