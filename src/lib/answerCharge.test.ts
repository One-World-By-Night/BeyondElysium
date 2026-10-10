import {
	chargeComplete,
	chargePayload,
	queueChargeLabel,
	storedChargeLabel,
} from './answerCharge';

describe( 'chargeComplete', () => {
	it( 'is false until a choice is made', () => {
		expect( chargeComplete( null ) ).toBe( false );
	} );

	it( 'is true for "no action charged"', () => {
		expect( chargeComplete( { mode: 'none' } ) ).toBe( true );
	} );

	it( 'needs a background and at least one action for a charge', () => {
		expect( chargeComplete( { mode: 'charge', name: '', cost: 1 } ) ).toBe(
			false
		);
		expect(
			chargeComplete( { mode: 'charge', name: 'Allies', cost: 0 } )
		).toBe( false );
		expect(
			chargeComplete( { mode: 'charge', name: 'Allies', cost: 1 } )
		).toBe( true );
	} );
} );

describe( 'chargePayload', () => {
	it( 'sends nothing while nothing is chosen', () => {
		expect( chargePayload( null ) ).toBeUndefined();
	} );

	it( 'says no action was charged', () => {
		expect( chargePayload( { mode: 'none' } ) ).toEqual( {
			charged: false,
		} );
	} );

	it( 'names the background and the cost of a charge', () => {
		expect(
			chargePayload( { mode: 'charge', name: 'Police', cost: 2 } )
		).toEqual( { charged: true, name: 'Police', cost: 2 } );
	} );
} );

describe( 'storedChargeLabel', () => {
	it( 'says nothing for an answer written before the choice was asked', () => {
		expect( storedChargeLabel( null ) ).toBeNull();
		expect( storedChargeLabel( undefined ) ).toBeNull();
	} );

	it( 'reads each decision', () => {
		expect( storedChargeLabel( { charged: false } ) ).toBe(
			'No action charged'
		);
		expect(
			storedChargeLabel( {
				charged: true,
				name: 'Police',
				cost: 1,
				use_id: 9,
			} )
		).toBe( 'Charged 1 action: Police' );
		expect(
			storedChargeLabel( {
				charged: true,
				name: 'Police',
				cost: 3,
				use_id: 9,
			} )
		).toBe( 'Charged 3 actions: Police' );
	} );
} );

describe( 'queueChargeLabel', () => {
	it( 'says nothing for an unanswered row', () => {
		expect( queueChargeLabel( null ) ).toBeNull();
	} );

	it( 'reads each state the queue reports', () => {
		expect( queueChargeLabel( { state: 'none' } ) ).toBe(
			'No action charged'
		);
		expect(
			queueChargeLabel( { state: 'charged', name: 'Allies', cost: 1 } )
		).toBe( 'Charged 1 action: Allies' );
		expect(
			queueChargeLabel( {
				state: 'charge_removed',
				name: 'Allies',
				cost: 1,
			} )
		).toBe( 'Charged to Allies, use since removed' );
		expect( queueChargeLabel( { state: 'not_recorded' } ) ).toBe(
			'Action charge not recorded'
		);
	} );
} );
