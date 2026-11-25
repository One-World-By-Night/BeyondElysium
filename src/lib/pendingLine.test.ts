import { pendingLine } from './pendingLine';

describe( 'pendingLine', () => {
	it( 'gives the count, the cost and what is left', () => {
		expect( pendingLine( 3, 6, 4 ) ).toEqual( {
			lead: '3 changes · +6 XP',
			tail: '4 XP left',
			over: false,
		} );
	} );

	it( 'says "change" for one', () => {
		expect( pendingLine( 1, 2, 8 ).lead ).toBe( '1 change · +2 XP' );
	} );

	it( 'says how far over budget the changes run', () => {
		expect( pendingLine( 2, 12, -2 ) ).toEqual( {
			lead: '2 changes · +12 XP',
			tail: '2 XP over',
			over: true,
		} );
	} );

	it( 'shows nothing left to spend as zero left, not over', () => {
		expect( pendingLine( 1, 5, 0 ) ).toEqual( {
			lead: '1 change · +5 XP',
			tail: '0 XP left',
			over: false,
		} );
	} );

	it( 'shows a refund with its own sign', () => {
		expect( pendingLine( 1, -3, 13 ).lead ).toBe( '1 change · -3 XP' );
	} );

	it( 'is the count alone when there is no price to show', () => {
		expect( pendingLine( 3, null, null ) ).toEqual( {
			lead: '3 changes',
			tail: null,
			over: false,
		} );
	} );
} );
