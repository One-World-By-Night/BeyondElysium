import {
	readStoredCostVisibility,
	writeStoredCostVisibility,
} from './costDisplayMode';

beforeEach( () => {
	window.localStorage.clear();
} );

describe( 'readStoredCostVisibility', () => {
	// 1.2.11 D94: the default flipped. Until this release the preference chose between
	// dots and numbers and defaulted to dots, which is what left a price rendering as an
	// unlabelled rating for every viewer who never found the toggle. It now chooses only
	// whether the labelled price is shown, and showing it is the default.
	it( 'defaults to showing the price with nothing stored', () => {
		expect( readStoredCostVisibility() ).toBe( true );
	} );

	it( 'reads a stored hide preference', () => {
		window.localStorage.setItem( 'be-cost-visibility', 'hide' );
		expect( readStoredCostVisibility() ).toBe( false );
	} );

	it( 'reads a stored show preference', () => {
		window.localStorage.setItem( 'be-cost-visibility', 'show' );
		expect( readStoredCostVisibility() ).toBe( true );
	} );

	it( 'treats any other stored value as showing the price', () => {
		window.localStorage.setItem( 'be-cost-visibility', 'garbage' );
		expect( readStoredCostVisibility() ).toBe( true );
	} );

	// The 1.1.0 key is deliberately not read: 'dots' meant "draw this as a rating", a
	// choice that no longer exists, and silently reading it as "hide the price" would
	// take the number away from a viewer who never asked for that.
	it( 'ignores the retired 1.1.0 preference key', () => {
		window.localStorage.setItem( 'be-cost-display-mode', 'dots' );
		expect( readStoredCostVisibility() ).toBe( true );
	} );
} );

describe( 'writeStoredCostVisibility', () => {
	it( 'persists hiding the price', () => {
		writeStoredCostVisibility( false );
		expect( window.localStorage.getItem( 'be-cost-visibility' ) ).toBe(
			'hide'
		);
	} );

	it( 'persists showing the price', () => {
		writeStoredCostVisibility( true );
		expect( window.localStorage.getItem( 'be-cost-visibility' ) ).toBe(
			'show'
		);
	} );

	it( 'round-trips through readStoredCostVisibility', () => {
		writeStoredCostVisibility( false );
		expect( readStoredCostVisibility() ).toBe( false );
		writeStoredCostVisibility( true );
		expect( readStoredCostVisibility() ).toBe( true );
	} );
} );
