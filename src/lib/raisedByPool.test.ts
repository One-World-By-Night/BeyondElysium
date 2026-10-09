import { raiseButtonLabel, spentBadgeLabel } from './raisedByPool';

describe( 'raiseButtonLabel', () => {
	it( 'names the pool spent and the temporary points it costs', () => {
		expect(
			raiseButtonLabel( {
				from: 'hunter-resources.Conviction',
				temporary: 10,
			} )
		).toBe( 'Raise with Conviction (10)' );
	} );

	it( 'falls back to the whole reference if it carries no dot', () => {
		expect(
			raiseButtonLabel( { from: 'Conviction', temporary: 10 } )
		).toBe( 'Raise with Conviction (10)' );
	} );
} );

describe( 'spentBadgeLabel', () => {
	it( 'names how many dots are spent', () => {
		expect( spentBadgeLabel( 3 ) ).toBe( '3 spent' );
		expect( spentBadgeLabel( 1 ) ).toBe( '1 spent' );
	} );

	it( 'shows nothing for zero or an absent value', () => {
		expect( spentBadgeLabel( 0 ) ).toBeNull();
		expect( spentBadgeLabel( undefined ) ).toBeNull();
	} );
} );
