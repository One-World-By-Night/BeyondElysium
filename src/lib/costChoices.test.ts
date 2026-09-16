import { costChoices } from './costChoices';
import cases from '../../tests/fixtures/cost-choices.json';

/**
 * 1.0.0-review F-107: a catalog item priced "1 or 3" or "1-7" takes the player's choice of cost,
 * but nothing on screen let them choose - every one was priced at its lowest. The choices come
 * from the same reading of the cost the server prices by; `CostChoicesParityTest.php` holds the
 * server to the same cases.
 */
describe( 'costChoices', () => {
	cases.forEach( ( { cost, choices } ) => {
		it( `reads "${ cost }"`, () => {
			expect( costChoices( cost ) ).toEqual( choices );
		} );
	} );

	it( 'offers nothing for an item with no cost', () => {
		expect( costChoices( undefined ) ).toBeNull();
	} );
} );
