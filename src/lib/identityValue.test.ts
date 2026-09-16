/**
 * 1.0.0-review F-078. A multiselect identity field holds a list. The signed sheet printed it as
 * the word "Array"; the on-screen sheet joined it with a bare comma, and showed an empty choice
 * as nothing at all. Both now print the choices the same way.
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
