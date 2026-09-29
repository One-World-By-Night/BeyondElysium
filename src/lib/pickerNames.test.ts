import { pickerNames } from './pickerNames';

describe( 'pickerNames', () => {
	const rows = [
		{ id: 3, name: 'Isolde Marchetti' },
		{ id: 7, name: 'Marcus Vitel' },
		{ id: 9, name: 'Marcus Vitel' },
	];

	it( 'maps each unique name to its id', () => {
		const names = pickerNames( rows, ( r ) => r.name );

		expect( names.get( 'Isolde Marchetti' ) ).toBe( 3 );
	} );

	it( 'gives a name used twice its id, so each still maps to one row', () => {
		const names = pickerNames( rows, ( r ) => r.name );

		expect( names.get( 'Marcus Vitel (#7)' ) ).toBe( 7 );
		expect( names.get( 'Marcus Vitel (#9)' ) ).toBe( 9 );
		expect( names.has( 'Marcus Vitel' ) ).toBe( false );
	} );

	it( 'keeps the rows in the order given', () => {
		const names = pickerNames( rows, ( r ) => r.name );

		expect( Array.from( names.keys() ) ).toEqual( [
			'Isolde Marchetti',
			'Marcus Vitel (#7)',
			'Marcus Vitel (#9)',
		] );
	} );

	it( 'is empty for no rows', () => {
		expect(
			pickerNames( [], ( r: { id: number } ) => String( r.id ) ).size
		).toBe( 0 );
	} );
} );
