import {
	raiseButtonLabel,
	raisedByLocked,
	showsRaiseButton,
	spentBadgeLabel,
	unbuyableLine,
} from './raisedByPool';

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

describe( 'raisedByLocked', () => {
	const rule = { from: 'hunter-resources.Conviction', temporary: 10 };

	it( 'locks the dots of a character that exists, for a player', () => {
		expect( raisedByLocked( rule, false, false ) ).toBe( true );
	} );

	it( 'leaves them open while a player fills in a new character', () => {
		expect( raisedByLocked( rule, false, true ) ).toBe( false );
	} );

	it( 'leaves them open for a Storyteller', () => {
		expect( raisedByLocked( rule, true, false ) ).toBe( false );
	} );

	it( 'never locks a pool that has no raised_by rule', () => {
		expect( raisedByLocked( undefined, false, false ) ).toBe( false );
	} );
} );

describe( 'showsRaiseButton', () => {
	const rule = { from: 'hunter-resources.Conviction', temporary: 10 };

	it( 'shows on a character that exists', () => {
		expect( showsRaiseButton( rule, false ) ).toBe( true );
	} );

	it( 'is hidden while a new character is being made', () => {
		expect( showsRaiseButton( rule, true ) ).toBe( false );
	} );

	it( 'is hidden for a pool with no raised_by rule', () => {
		expect( showsRaiseButton( undefined, false ) ).toBe( false );
	} );
} );

describe( 'unbuyableLine', () => {
	it( 'names the pool and says how many dots are past the free ones', () => {
		expect(
			unbuyableLine( {
				kind: 'raised_by',
				section: 'hunter-virtues',
				pool: 'Vision',
				dots: 1,
			} )
		).toBe(
			'Vision is set 1 dot past the free dots a new character gets. More are raised in play.'
		);
		expect(
			unbuyableLine( {
				kind: 'raised_by',
				section: 'hunter-virtues',
				pool: 'Mercy',
				dots: 3,
			} )
		).toBe(
			'Mercy is set 3 dots past the free dots a new character gets. More are raised in play.'
		);
	} );

	it( 'names a pool set above the most the book allows', () => {
		expect(
			unbuyableLine( {
				kind: 'book_max',
				section: 'mummy-resources',
				pool: 'Balance',
				value: 6,
				max: 5,
			} )
		).toBe(
			'Balance is set to 6, above the 5 the book allows. A Storyteller sets it higher.'
		);
	} );
} );
