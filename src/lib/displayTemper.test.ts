import { displayTemper } from './displayTemper';

describe( 'displayTemper', () => {
	it( 'a full pool with no temp/perm split renders filled dots', () => {
		expect( displayTemper( { permanent: 3, temporary: 3 } ) ).toBe( 'o'.repeat( 3 ) );
	} );

	it( 'zero pool renders empty', () => {
		expect( displayTemper( { permanent: 0, temporary: 0 } ) ).toBe( '' );
	} );

	it( 'temporary below permanent renders spent glyphs for the difference', () => {
		expect( displayTemper( { permanent: 5, temporary: 2 } ) ).toBe( 'o'.repeat( 2 ) + 'ø'.repeat( 3 ) );
	} );

	it( 'temporary above permanent renders overflow glyphs, not clamped', () => {
		expect( displayTemper( { permanent: 3, temporary: 5 } ) ).toBe( 'o'.repeat( 3 ) + 'õ'.repeat( 2 ) );
	} );

	it( 'temporary equal to permanent renders no overflow or spent glyphs', () => {
		expect( displayTemper( { permanent: 4, temporary: 4 } ) ).toBe( 'o'.repeat( 4 ) );
	} );

	it( 'exactly 10 glyphs does not trigger compression', () => {
		expect( displayTemper( { permanent: 10, temporary: 10 } ) ).toBe( 'o'.repeat( 10 ) );
	} );

	it( 'exactly 11 filled glyphs collapses the leftmost run of five, leaving 6 remaining plus the capital', () => {
		expect( displayTemper( { permanent: 11, temporary: 11 } ) ).toBe( 'O' + 'o'.repeat( 6 ) );
	} );

	it( 'a 15-point pool (e.g. Blood) collapses twice down to two capitals plus the remainder', () => {
		// 15 -> collapse leftmost 5 -> 1 capital + 10 o's (11 chars, still >10)
		//    -> collapse leftmost 5 of the remaining o's -> 2 capitals + 5 o's (7 chars)
		expect( displayTemper( { permanent: 15, temporary: 15 } ) ).toBe( 'OO' + 'o'.repeat( 5 ) );
	} );

	it( 'a large uniform pool stops compressing once at or under 10 characters', () => {
		// 14 filled -> one collapse (5->O) leaves 1 capital + 9 o's = 10 chars, loop stops.
		expect( displayTemper( { permanent: 14, temporary: 14 } ) ).toBe( 'O' + 'o'.repeat( 9 ) );
	} );

	it( 'spent runs collapse from the right, leaving the uncollapsed remainder on the left', () => {
		// 12 spent -> one collapse of the rightmost run of 5 -> 7 leftover + 1 capital = 8 chars.
		expect( displayTemper( { permanent: 12, temporary: 0 } ) ).toBe( 'ø'.repeat( 7 ) + 'Ø' );
	} );

	it( 'overflow runs collapse from the right, leaving the uncollapsed remainder on the left', () => {
		expect( displayTemper( { permanent: 0, temporary: 12 } ) ).toBe( 'õ'.repeat( 7 ) + 'Õ' );
	} );

	it( 'spent is collapsed before filled when both are present', () => {
		// permanent 12, temporary 6 -> 6 filled + 6 spent = 12 chars. Spent's rightmost
		// run of 5 collapses first, leaving 6 filled + 1 spent + 1 capital = 8 chars,
		// already under the limit - filled never needs to collapse at all.
		expect( displayTemper( { permanent: 12, temporary: 6 } ) ).toBe( 'o'.repeat( 6 ) + 'ø' + 'Ø' );
	} );
} );
