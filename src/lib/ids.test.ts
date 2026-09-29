import { idOf, sameId } from './ids';

describe( 'idOf', () => {
	it( 'reads an id sent as text as a number', () => {
		expect( idOf( '1036' ) ).toBe( 1036 );
	} );

	it( 'keeps a number', () => {
		expect( idOf( 12 ) ).toBe( 12 );
	} );

	it( 'reads nothing as null', () => {
		expect( idOf( null ) ).toBeNull();
		expect( idOf( undefined ) ).toBeNull();
		expect( idOf( '' ) ).toBeNull();
	} );
} );

describe( 'sameId', () => {
	it( 'matches an id sent as text and the same id as a number', () => {
		expect( sameId( '12', 12 ) ).toBe( true );
		expect( sameId( 12, '12' ) ).toBe( true );
	} );

	it( 'tells two different ids apart', () => {
		expect( sameId( '12', 13 ) ).toBe( false );
	} );

	it( 'never matches a missing id, not even another missing one', () => {
		expect( sameId( null, null ) ).toBe( false );
		expect( sameId( 12, null ) ).toBe( false );
		expect( sameId( undefined, '' ) ).toBe( false );
	} );
} );
