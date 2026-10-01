import {
	applyXpResults,
	buildXpApplyRequest,
	countXpEntries,
	defaultXpReason,
	todayYmd,
	parseXpEntry,
	parseXpRequestAmount,
	xpEntryHint,
	xpRequestAmountHint,
	XP_ENTRY_MAX,
	XP_ENTRY_MIN,
	XP_REQUEST_MAX,
} from './xpEntry';

describe( 'parseXpEntry', () => {
	it( 'reads a positive whole number', () => {
		expect( parseXpEntry( '5' ) ).toEqual( { kind: 'amount', amount: 5 } );
	} );

	it( 'reads a negative whole number', () => {
		expect( parseXpEntry( '-2' ) ).toEqual( {
			kind: 'amount',
			amount: -2,
		} );
	} );

	it( 'treats a blank box as empty', () => {
		expect( parseXpEntry( '' ) ).toEqual( { kind: 'empty' } );
		expect( parseXpEntry( '   ' ) ).toEqual( { kind: 'empty' } );
	} );

	it( 'treats zero as empty', () => {
		expect( parseXpEntry( '0' ) ).toEqual( { kind: 'empty' } );
		expect( parseXpEntry( '-0' ) ).toEqual( { kind: 'empty' } );
	} );

	it( 'rejects a decimal', () => {
		expect( parseXpEntry( '1.5' ) ).toEqual( { kind: 'invalid' } );
	} );

	it( 'rejects non-numeric text', () => {
		expect( parseXpEntry( 'abc' ) ).toEqual( { kind: 'invalid' } );
	} );

	it( 'accepts the exact bounds', () => {
		expect( parseXpEntry( String( XP_ENTRY_MAX ) ) ).toEqual( {
			kind: 'amount',
			amount: XP_ENTRY_MAX,
		} );
		expect( parseXpEntry( String( XP_ENTRY_MIN ) ) ).toEqual( {
			kind: 'amount',
			amount: XP_ENTRY_MIN,
		} );
	} );

	it( 'rejects one past either bound', () => {
		expect( parseXpEntry( String( XP_ENTRY_MAX + 1 ) ) ).toEqual( {
			kind: 'invalid',
		} );
		expect( parseXpEntry( String( XP_ENTRY_MIN - 1 ) ) ).toEqual( {
			kind: 'invalid',
		} );
	} );
} );

describe( 'xpEntryHint', () => {
	it( 'names both bounds', () => {
		expect( xpEntryHint() ).toContain( String( XP_ENTRY_MIN ) );
		expect( xpEntryHint() ).toContain( String( XP_ENTRY_MAX ) );
	} );
} );

describe( 'countXpEntries', () => {
	it( 'counts only the boxes holding a valid non-zero amount', () => {
		expect(
			countXpEntries( {
				1: '5',
				2: '-2',
				3: '',
				4: '0',
				5: 'abc',
				6: '1.5',
			} )
		).toBe( 2 );
	} );

	it( 'counts entries held from a different page', () => {
		expect( countXpEntries( { 101: '7', 202: '3' } ) ).toBe( 2 );
	} );
} );

describe( 'buildXpApplyRequest', () => {
	it( 'builds a request from every valid held amount', () => {
		expect(
			buildXpApplyRequest(
				{ 1: '5', 2: '-2', 3: '7' },
				'XP award, 2026-10-01'
			)
		).toEqual( {
			reason: 'XP award, 2026-10-01',
			awards: [
				{ character_id: 1, amount: 5 },
				{ character_id: 2, amount: -2 },
				{ character_id: 3, amount: 7 },
			],
		} );
	} );

	it( 'leaves out empty and invalid boxes', () => {
		expect(
			buildXpApplyRequest(
				{ 1: '5', 2: '', 3: '0', 4: 'abc' },
				'XP award'
			)
		).toEqual( {
			reason: 'XP award',
			awards: [ { character_id: 1, amount: 5 } ],
		} );
	} );

	it( 'returns null with an empty reason', () => {
		expect( buildXpApplyRequest( { 1: '5' }, '' ) ).toBeNull();
		expect( buildXpApplyRequest( { 1: '5' }, '   ' ) ).toBeNull();
	} );

	it( 'returns null with nothing to send', () => {
		expect(
			buildXpApplyRequest( { 1: '', 2: '0' }, 'XP award' )
		).toBeNull();
	} );

	it( 'trims the reason', () => {
		expect( buildXpApplyRequest( { 1: '5' }, '  XP award  ' ) ).toEqual( {
			reason: 'XP award',
			awards: [ { character_id: 1, amount: 5 } ],
		} );
	} );
} );

describe( 'applyXpResults', () => {
	it( 'clears an applied row and notes the amount', () => {
		const outcome = applyXpResults( { 1: '5' }, [
			{
				character_id: 1,
				applied: true,
				amount: 5,
				xp_earned: 95,
				xp_unspent: 55,
			},
		] );
		expect( outcome.entries ).toEqual( {} );
		expect( outcome.notes[ 1 ] ).toBe( '+5 applied' );
		expect( outcome.totals[ 1 ] ).toEqual( {
			xp_earned: 95,
			xp_unspent: 55,
		} );
	} );

	it( 'notes a negative amount without a doubled sign', () => {
		const outcome = applyXpResults( { 1: '-3' }, [
			{
				character_id: 1,
				applied: true,
				amount: -3,
				xp_earned: 87,
				xp_unspent: 47,
			},
		] );
		expect( outcome.notes[ 1 ] ).toBe( '-3 applied' );
	} );

	it( 'keeps a refused row and shows its message', () => {
		const outcome = applyXpResults( { 1: '-999' }, [
			{
				character_id: 1,
				applied: false,
				code: 'below_zero',
				message: 'That would take XP Earned below zero.',
			},
		] );
		expect( outcome.entries ).toEqual( { 1: '-999' } );
		expect( outcome.notes[ 1 ] ).toBe(
			'That would take XP Earned below zero.'
		);
		expect( outcome.totals[ 1 ] ).toBeUndefined();
	} );

	it( 'leaves an untouched row alone', () => {
		const outcome = applyXpResults( { 1: '5', 2: '3' }, [
			{ character_id: 1, applied: true, amount: 5 },
		] );
		expect( outcome.entries ).toEqual( { 2: '3' } );
	} );
} );

describe( 'todayYmd', () => {
	it( 'formats a date as YYYY-MM-DD', () => {
		expect( todayYmd( new Date( 2026, 9, 1 ) ) ).toBe( '2026-10-01' );
	} );

	it( 'pads a single-digit month and day', () => {
		expect( todayYmd( new Date( 2026, 0, 5 ) ) ).toBe( '2026-01-05' );
	} );
} );

describe( 'defaultXpReason', () => {
	it( 'names today in YYYY-MM-DD', () => {
		expect( defaultXpReason( new Date( 2026, 9, 1 ) ) ).toBe(
			'XP award, 2026-10-01'
		);
	} );

	it( 'pads a single-digit month and day', () => {
		expect( defaultXpReason( new Date( 2026, 0, 5 ) ) ).toBe(
			'XP award, 2026-01-05'
		);
	} );
} );

describe( 'parseXpRequestAmount', () => {
	it( 'accepts a positive whole number', () => {
		expect( parseXpRequestAmount( '5' ) ).toEqual( {
			kind: 'amount',
			amount: 5,
		} );
	} );

	it( 'treats a blank box as empty', () => {
		expect( parseXpRequestAmount( '' ) ).toEqual( { kind: 'empty' } );
	} );

	it( 'rejects zero', () => {
		expect( parseXpRequestAmount( '0' ) ).toEqual( { kind: 'invalid' } );
	} );

	it( 'rejects a negative amount', () => {
		expect( parseXpRequestAmount( '-3' ) ).toEqual( { kind: 'invalid' } );
	} );

	it( 'rejects a decimal', () => {
		expect( parseXpRequestAmount( '2.5' ) ).toEqual( { kind: 'invalid' } );
	} );

	it( 'accepts the exact cap and rejects one past it', () => {
		expect( parseXpRequestAmount( String( XP_REQUEST_MAX ) ) ).toEqual( {
			kind: 'amount',
			amount: XP_REQUEST_MAX,
		} );
		expect( parseXpRequestAmount( String( XP_REQUEST_MAX + 1 ) ) ).toEqual(
			{ kind: 'invalid' }
		);
	} );
} );

describe( 'xpRequestAmountHint', () => {
	it( 'names the cap', () => {
		expect( xpRequestAmountHint() ).toContain( String( XP_REQUEST_MAX ) );
	} );
} );
