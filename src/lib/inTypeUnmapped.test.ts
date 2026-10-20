import { passesUnmapped, withUnmapped } from './inTypeUnmapped';

describe( 'passesUnmapped', () => {
	it( 'is true only for a map that says unmapped: pass', () => {
		expect(
			passesUnmapped( { map: 'a.b', by: [ 'X' ], unmapped: 'pass' } )
		).toBe( true );
		expect( passesUnmapped( { map: 'a.b', by: [ 'X' ] } ) ).toBe( false );
		expect( passesUnmapped( { field: 'a.B' } ) ).toBe( false );
		expect( passesUnmapped( { constant: [ 'Potence' ] } ) ).toBe( false );
		expect( passesUnmapped( undefined ) ).toBe( false );
	} );
} );

describe( 'withUnmapped', () => {
	it( 'adds unmapped: pass to a map and takes it away again', () => {
		const map = { map: 'a.b', by: [ 'X' ] };

		const passing = withUnmapped( map, true );
		expect( passing ).toEqual( {
			map: 'a.b',
			by: [ 'X' ],
			unmapped: 'pass',
		} );
		expect( withUnmapped( passing, false ) ).toEqual( map );
		expect( 'unmapped' in withUnmapped( passing, false ) ).toBe( false );
	} );

	it( 'leaves the map and the fields it is keyed by alone', () => {
		expect(
			withUnmapped(
				{
					map: 'mortal-identity.revenant_disciplines',
					by: [ 'A', 'B' ],
				},
				true
			)
		).toMatchObject( {
			map: 'mortal-identity.revenant_disciplines',
			by: [ 'A', 'B' ],
		} );
	} );

	it( 'returns a field or a constant as it is', () => {
		const field = { field: 'a.B' };
		const constant = { constant: [ 'Potence' ] };

		expect( withUnmapped( field, true ) ).toBe( field );
		expect( withUnmapped( constant, true ) ).toBe( constant );
	} );
} );
