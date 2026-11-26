import cases from '../../tests/fixtures/catalog-book.json';
import { bookOf } from './catalogBook';

describe( 'bookOf', () => {
	it( 'reads every case of the fixture the server reads too', () => {
		for ( const { source, book } of cases ) {
			expect( bookOf( source ) ).toBe( book );
		}
	} );

	it( 'gives nothing for what is not text', () => {
		expect( bookOf( undefined ) ).toBeNull();
		expect( bookOf( 42 ) ).toBeNull();
		expect( bookOf( [ 'Laws of the Night' ] ) ).toBeNull();
	} );
} );
