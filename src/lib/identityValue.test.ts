/**
 * A multiselect identity field prints its choices the same way on the signed sheet and the on-screen sheet.
 */
import { filledIdentityFields, identityValueText } from './identityValue';

describe( 'identityValueText', () => {
	it( "joins a multiselect's choices", () => {
		expect( identityValueText( [ 'Celerity', 'Fortitude' ] ) ).toBe(
			'Celerity, Fortitude'
		);
	} );

	it( 'has nothing to show for an empty choice', () => {
		expect( identityValueText( [] ) ).toBeNull();
		expect( identityValueText( '' ) ).toBeNull();
		expect( identityValueText( null ) ).toBeNull();
		expect( identityValueText( undefined ) ).toBeNull();
	} );

	it( 'shows a plain value as it is', () => {
		expect( identityValueText( 'Toreador' ) ).toBe( 'Toreador' );
		expect( identityValueText( 9 ) ).toBe( '9' );
	} );
} );

describe( 'filledIdentityFields', () => {
	const fields = [
		{ name: 'clan' },
		{ name: 'sect' },
		{ name: 'disciplines' },
		{ name: 'generation' },
	];

	it( 'keeps the fields that hold something, in the order given', () => {
		expect(
			filledIdentityFields( fields, {
				generation: 9,
				clan: 'Tremere',
				sect: '',
				disciplines: [],
			} ).map( ( field ) => field.name )
		).toEqual( [ 'clan', 'generation' ] );
	} );

	it( 'keeps a multiselect that holds a choice', () => {
		expect(
			filledIdentityFields( fields, { disciplines: [ 'Auspex' ] } ).map(
				( field ) => field.name
			)
		).toEqual( [ 'disciplines' ] );
	} );

	it( 'keeps a zero', () => {
		expect(
			filledIdentityFields( [ { name: 'blood' } ], { blood: 0 } )
		).toHaveLength( 1 );
	} );

	it( 'has nothing when the character has no values at all', () => {
		expect( filledIdentityFields( fields, undefined ) ).toEqual( [] );
		expect( filledIdentityFields( fields, {} ) ).toEqual( [] );
	} );
} );
