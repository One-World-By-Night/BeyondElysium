import {
	canApprove,
	costNeededMessage,
	isCostPending,
	MAX_CUSTOM_PRICE,
	parsePrice,
	priceTotal,
	priceUnitLabel,
	previewPriceLabel,
} from './queuePrice';

describe( 'isCostPending', () => {
	it( 'is true only for a change the server flagged as waiting for a price', () => {
		expect( isCostPending( { change_data: { cost_pending: true } } ) ).toBe(
			true
		);
		expect( isCostPending( { change_data: {} } ) ).toBe( false );
		expect(
			isCostPending( { change_data: { cost_pending: false } } )
		).toBe( false );
		expect( isCostPending( {} ) ).toBe( false );
	} );

	it( 'is not fooled by a truthy string', () => {
		expect(
			isCostPending( { change_data: { cost_pending: 'true' } } )
		).toBe( false );
	} );
} );

describe( 'parsePrice', () => {
	it.each( [
		[ '0', 0 ],
		[ '3', 3 ],
		[ ' 7 ', 7 ],
		[ '007', 7 ],
		[ '500', 500 ],
	] )( 'reads %p as %p', ( text, expected ) => {
		expect( parsePrice( text ) ).toBe( expected );
	} );

	it.each( [ '', '   ', 'abc', '-1', '2.5', '1e2', '501', '9999', '+3' ] )(
		'refuses %p',
		( text ) => {
			expect( parsePrice( text ) ).toBeNull();
		}
	);

	it( 'refuses nothing at all', () => {
		expect( parsePrice( undefined ) ).toBeNull();
	} );

	it( "shares the server's ceiling", () => {
		expect( MAX_CUSTOM_PRICE ).toBe( 500 );
	} );
} );

describe( 'priceTotal', () => {
	it( 'multiplies the price by the units it covers', () => {
		expect( priceTotal( '2', { per: 'dot', units: 3 } ) ).toBe( 6 );
		expect( priceTotal( '5', { per: 'pick', units: 1 } ) ).toBe( 5 );
	} );

	it( 'is zero for a price of zero, which is a real answer', () => {
		expect( priceTotal( '0', { per: 'dot', units: 3 } ) ).toBe( 0 );
	} );

	it( 'is null until there is a usable price and a basis to price it on', () => {
		expect( priceTotal( '', { per: 'dot', units: 3 } ) ).toBeNull();
		expect( priceTotal( 'x', { per: 'dot', units: 3 } ) ).toBeNull();
		expect( priceTotal( '2', null ) ).toBeNull();
		expect( priceTotal( '2', undefined ) ).toBeNull();
	} );
} );

describe( 'priceUnitLabel', () => {
	it( 'says per dot for a trait list and plainly XP for a power', () => {
		expect( priceUnitLabel( 'dot' ) ).toBe( 'XP per dot' );
		expect( priceUnitLabel( 'pick' ) ).toBe( 'XP' );
	} );
} );

describe( 'canApprove', () => {
	const waiting = { id: 7, change_data: { cost_pending: true } };
	const ready = { id: 8, change_data: {} };

	it( 'never holds back a change that already has a price', () => {
		expect( canApprove( ready, {} ) ).toBe( true );
	} );

	it( 'holds a waiting change back until its price is a usable number', () => {
		expect( canApprove( waiting, {} ) ).toBe( false );
		expect( canApprove( waiting, { 7: '' } ) ).toBe( false );
		expect( canApprove( waiting, { 7: 'two' } ) ).toBe( false );
		expect( canApprove( waiting, { 7: '2' } ) ).toBe( true );
		expect( canApprove( waiting, { 7: '0' } ) ).toBe( true );
	} );

	it( 'reads the price typed for that change, not another', () => {
		expect( canApprove( waiting, { 8: '2' } ) ).toBe( false );
	} );
} );

describe( 'costNeededMessage', () => {
	it( 'names how many changes need a price, in the right plural', () => {
		expect( costNeededMessage( 1 ) ).toContain( '1 change needs a price' );
		expect( costNeededMessage( 3 ) ).toContain( '3 changes need a price' );
	} );
} );

describe( 'previewPriceLabel', () => {
	it( 'says a Storyteller sets the price when the preview has none', () => {
		expect( previewPriceLabel( { priced: false } ) ).toBe(
			'Price set by a Storyteller on approval'
		);
	} );

	it( 'says nothing when the change is priced, or the server did not say', () => {
		expect( previewPriceLabel( { priced: true } ) ).toBeNull();
		expect( previewPriceLabel( {} ) ).toBeNull();
	} );
} );
