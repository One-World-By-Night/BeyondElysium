/**
 * A multiselect identity field prints its choices the same way on the signed sheet and the on-screen sheet.
 */
import { identityValueText } from './identityValue';

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
