import { isRowOpen } from './setupRows';

describe( 'isRowOpen', () => {
	it( 'opens a row that needs attention and folds every other row', () => {
		expect( isRowOpen( {}, { id: 'a', status: 'attention' } ) ).toBe(
			true
		);
		expect( isRowOpen( {}, { id: 'a', status: 'ok' } ) ).toBe( false );
		expect( isRowOpen( {}, { id: 'a', status: 'info' } ) ).toBe( false );
	} );

	it( 'lets the viewer close a row that started open and open one that started folded', () => {
		expect(
			isRowOpen( { a: false }, { id: 'a', status: 'attention' } )
		).toBe( false );
		expect( isRowOpen( { a: true }, { id: 'a', status: 'info' } ) ).toBe(
			true
		);
	} );

	it( 'folds a row that was open only because it needed attention once it no longer does', () => {
		expect( isRowOpen( {}, { id: 'a', status: 'attention' } ) ).toBe(
			true
		);
		expect( isRowOpen( {}, { id: 'a', status: 'ok' } ) ).toBe( false );
	} );

	it( 'keeps a row the viewer opened open after it turns green', () => {
		expect( isRowOpen( { a: true }, { id: 'a', status: 'ok' } ) ).toBe(
			true
		);
	} );

	it( 'treats each row on its own', () => {
		expect( isRowOpen( { a: true }, { id: 'b', status: 'ok' } ) ).toBe(
			false
		);
	} );
} );
