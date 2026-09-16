import {
	readStoredDisplayMode,
	writeStoredDisplayMode,
} from './powerDisplayMode';

beforeEach( () => {
	window.localStorage.clear();
} );

describe( 'readStoredDisplayMode', () => {
	it( 'defaults to stepper mode (false) with nothing stored', () => {
		expect( readStoredDisplayMode() ).toBe( false );
	} );

	it( 'reads a stored checklist preference', () => {
		window.localStorage.setItem( 'be-power-display-mode', 'checklist' );
		expect( readStoredDisplayMode() ).toBe( true );
	} );

	it( 'treats any other stored value as stepper mode', () => {
		window.localStorage.setItem( 'be-power-display-mode', 'garbage' );
		expect( readStoredDisplayMode() ).toBe( false );
	} );
} );

describe( 'writeStoredDisplayMode', () => {
	it( 'persists checklist mode', () => {
		writeStoredDisplayMode( true );
		expect( window.localStorage.getItem( 'be-power-display-mode' ) ).toBe(
			'checklist'
		);
	} );

	it( 'persists stepper mode', () => {
		writeStoredDisplayMode( false );
		expect( window.localStorage.getItem( 'be-power-display-mode' ) ).toBe(
			'stepper'
		);
	} );

	it( 'round-trips through readStoredDisplayMode', () => {
		writeStoredDisplayMode( true );
		expect( readStoredDisplayMode() ).toBe( true );
		writeStoredDisplayMode( false );
		expect( readStoredDisplayMode() ).toBe( false );
	} );
} );
