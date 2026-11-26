import { poolCeiling, poolKind, shownPoolValue } from './poolKind';

describe( 'poolKind', () => {
	it( 'is ordinary with no marker', () => {
		expect( poolKind( {} ) ).toBe( 'ordinary' );
	} );

	it( 'reads each marker', () => {
		expect( poolKind( { rating_only: true } ) ).toBe( 'rating_only' );
		expect( poolKind( { accumulates: true } ) ).toBe( 'accumulates' );
	} );

	it( 'ignores a marker that is not true', () => {
		expect( poolKind( { rating_only: false, accumulates: false } ) ).toBe(
			'ordinary'
		);
	} );
} );

describe( 'poolCeiling', () => {
	it( 'is the pool maximum when it has one', () => {
		expect( poolCeiling( { max: 10 } ) ).toBe( 10 );
	} );

	it( 'is undefined for a pool with no maximum', () => {
		expect( poolCeiling( {} ) ).toBeUndefined();
	} );
} );

describe( 'shownPoolValue', () => {
	it( 'shows a rating-only pool as its rating alone', () => {
		expect(
			shownPoolValue( 'rating_only', { permanent: 7, temporary: 4 } )
		).toEqual( { permanent: 7, temporary: 7 } );
	} );

	it( 'leaves every other pool as stored', () => {
		const value = { permanent: 2, temporary: 7 };
		expect( shownPoolValue( 'accumulates', value ) ).toBe( value );
		expect( shownPoolValue( 'ordinary', value ) ).toBe( value );
	} );
} );
