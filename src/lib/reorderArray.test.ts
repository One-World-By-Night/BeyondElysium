import { moveUp, moveDown, moveTo, reorderErrorMessage } from './reorderArray';

describe( 'moveUp', () => {
	it( 'swaps an item with its predecessor', () => {
		expect( moveUp( [ 'a', 'b', 'c' ], 1 ) ).toEqual( [ 'b', 'a', 'c' ] );
	} );

	it( 'is a no-op at the front of the list', () => {
		const items = [ 'a', 'b', 'c' ];
		expect( moveUp( items, 0 ) ).toBe( items );
	} );

	it( 'is a no-op for an out-of-range index', () => {
		const items = [ 'a', 'b' ];
		expect( moveUp( items, 5 ) ).toBe( items );
	} );

	it( 'never mutates the input', () => {
		const items = [ 'a', 'b', 'c' ];
		moveUp( items, 2 );
		expect( items ).toEqual( [ 'a', 'b', 'c' ] );
	} );
} );

describe( 'moveDown', () => {
	it( 'swaps an item with its successor', () => {
		expect( moveDown( [ 'a', 'b', 'c' ], 1 ) ).toEqual( [ 'a', 'c', 'b' ] );
	} );

	it( 'is a no-op at the end of the list', () => {
		const items = [ 'a', 'b', 'c' ];
		expect( moveDown( items, 2 ) ).toBe( items );
	} );

	it( 'is a no-op for a negative index', () => {
		const items = [ 'a', 'b' ];
		expect( moveDown( items, -1 ) ).toBe( items );
	} );
} );

describe( 'moveTo', () => {
	it( 'moves an item earlier in the list', () => {
		expect( moveTo( [ 'a', 'b', 'c', 'd' ], 2, 0 ) ).toEqual( [
			'c',
			'a',
			'b',
			'd',
		] );
	} );

	it( 'moves an item later in the list', () => {
		expect( moveTo( [ 'a', 'b', 'c', 'd' ], 0, 2 ) ).toEqual( [
			'b',
			'c',
			'a',
			'd',
		] );
	} );

	it( 'is a no-op when from equals to', () => {
		const items = [ 'a', 'b', 'c' ];
		expect( moveTo( items, 1, 1 ) ).toBe( items );
	} );

	it( 'is a no-op for an out-of-range position', () => {
		const items = [ 'a', 'b', 'c' ];
		expect( moveTo( items, 0, 9 ) ).toBe( items );
		expect( moveTo( items, -1, 1 ) ).toBe( items );
	} );

	it( 'never mutates the input', () => {
		const items = [ 'a', 'b', 'c' ];
		moveTo( items, 0, 2 );
		expect( items ).toEqual( [ 'a', 'b', 'c' ] );
	} );
} );

describe( 'reorderErrorMessage', () => {
	it( "reads the REST error's own message", () => {
		expect(
			reorderErrorMessage( { message: 'This list changed.' }, 'fallback' )
		).toBe( 'This list changed.' );
	} );

	it( 'falls back for an error with no message', () => {
		expect( reorderErrorMessage( {}, 'fallback' ) ).toBe( 'fallback' );
	} );

	it( 'falls back for a non-object error', () => {
		expect( reorderErrorMessage( 'boom', 'fallback' ) ).toBe( 'fallback' );
		expect( reorderErrorMessage( null, 'fallback' ) ).toBe( 'fallback' );
	} );
} );
