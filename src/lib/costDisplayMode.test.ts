import {
	readStoredCostVisibility,
	writeStoredCostVisibility,
} from './costDisplayMode';

beforeEach( () => {
	window.localStorage.clear();
} );

describe( 'readStoredCostVisibility', () => {
	// The default flipped.
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
