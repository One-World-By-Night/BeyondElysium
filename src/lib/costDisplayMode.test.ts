import {
	readStoredCostDisplayMode,
	writeStoredCostDisplayMode,
} from './costDisplayMode';

beforeEach( () => {
	window.localStorage.clear();
} );

describe( 'readStoredCostDisplayMode', () => {
	it( 'defaults to dots mode (false) with nothing stored', () => {
		expect( readStoredCostDisplayMode() ).toBe( false );
	} );

	it( 'reads a stored numbers preference', () => {
		window.localStorage.setItem( 'be-cost-display-mode', 'numbers' );
		expect( readStoredCostDisplayMode() ).toBe( true );
	} );

	it( 'treats any other stored value as dots mode', () => {
		window.localStorage.setItem( 'be-cost-display-mode', 'garbage' );
		expect( readStoredCostDisplayMode() ).toBe( false );
	} );
} );

describe( 'writeStoredCostDisplayMode', () => {
	it( 'persists numbers mode', () => {
		writeStoredCostDisplayMode( true );
		expect( window.localStorage.getItem( 'be-cost-display-mode' ) ).toBe(
			'numbers'
		);
	} );

	it( 'persists dots mode', () => {
		writeStoredCostDisplayMode( false );
		expect( window.localStorage.getItem( 'be-cost-display-mode' ) ).toBe(
			'dots'
		);
	} );

	it( 'round-trips through readStoredCostDisplayMode', () => {
		writeStoredCostDisplayMode( true );
		expect( readStoredCostDisplayMode() ).toBe( true );
		writeStoredCostDisplayMode( false );
		expect( readStoredCostDisplayMode() ).toBe( false );
	} );
} );
