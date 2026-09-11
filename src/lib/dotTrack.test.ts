import { computeDotStates, nextTrackValueOnClick, stepTrackValue } from './dotTrack';

describe( 'computeDotStates', () => {
	it( 'fills up to permanent when temporary equals permanent', () => {
		expect( computeDotStates( 3, 3, 5 ) ).toEqual( [ 'filled', 'filled', 'filled', 'empty', 'empty' ] );
	} );

	it( 'marks the gap as spent when temporary is below permanent', () => {
		expect( computeDotStates( 4, 2, 5 ) ).toEqual( [ 'filled', 'filled', 'spent', 'spent', 'empty' ] );
	} );

	it( 'marks the excess as overflow when temporary exceeds permanent, never clamping', () => {
		expect( computeDotStates( 2, 5, 5 ) ).toEqual( [ 'filled', 'filled', 'overflow', 'overflow', 'overflow' ] );
	} );

	it( 'handles zero permanent and zero temporary as all empty', () => {
		expect( computeDotStates( 0, 0, 4 ) ).toEqual( [ 'empty', 'empty', 'empty', 'empty' ] );
	} );

	it( 'handles permanent and temporary both at max as all filled', () => {
		expect( computeDotStates( 5, 5, 5 ) ).toEqual( [ 'filled', 'filled', 'filled', 'filled', 'filled' ] );
	} );
} );

describe( 'nextTrackValueOnClick', () => {
	it( 'sets the track to the clicked dot when it differs from the current value', () => {
		expect( nextTrackValueOnClick( 1, 3 ) ).toBe( 3 );
	} );

	it( 'clears down by one when clicking the currently-set dot', () => {
		expect( nextTrackValueOnClick( 3, 3 ) ).toBe( 2 );
	} );

	it( 'clicking dot 1 when already at 1 clears to zero', () => {
		expect( nextTrackValueOnClick( 1, 1 ) ).toBe( 0 );
	} );

	it( 'clicking a lower dot than the current value sets down to it directly, not by one step', () => {
		expect( nextTrackValueOnClick( 5, 2 ) ).toBe( 2 );
	} );
} );

describe( 'stepTrackValue', () => {
	// working.md, user report: "I also hate the add/subtract willpower... All of this
	// should be +/-." The stepper's own pure logic - one +/- step, clamped to [0, max].
	it( 'increases by one', () => {
		expect( stepTrackValue( 3, 1, 20 ) ).toBe( 4 );
	} );

	it( 'decreases by one', () => {
		expect( stepTrackValue( 3, -1, 20 ) ).toBe( 2 );
	} );

	it( 'never decreases below zero', () => {
		expect( stepTrackValue( 0, -1, 20 ) ).toBe( 0 );
	} );

	it( 'never increases past max, including a real Willpower-sized max of 20', () => {
		expect( stepTrackValue( 20, 1, 20 ) ).toBe( 20 );
	} );
} );
