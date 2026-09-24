import { costChoices } from './costChoices';
import cases from '../../tests/fixtures/cost-choices.json';

/**
 * A catalog item priced "1 or 3" or "1-7" takes the player's choice of cost.
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
