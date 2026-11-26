import {
	filterChanges,
	groupOrders,
	isPlayChange,
	lineStatus,
	lineStatusLabel,
	orderCost,
	orderHead,
	orderSummary,
	reviewerNote,
	undoQuestion,
	undoSetQuestion,
	undoableCount,
	waitingCount,
} from './historyOrders';
import type { CharacterChange } from '../types/character';

function change( overrides: Partial< CharacterChange > ): CharacterChange {
	return {
		id: 1,
		character_id: 1,
		change_type: 'add_trait',
		category: 'abilities',
		change_data: {},
		xp_cost: 1,
		status: 'approved',
		submitted_by: 1,
		reviewed_by: 2,
		submitted_at: '2026-11-26 10:00:00',
		reviewed_at: null,
		notes: null,
		reason: null,
		...overrides,
	};
}

describe( 'filterChanges', () => {
	const items = [
		change( { id: 1, change_type: 'modify_trait' } ),
		change( { id: 2, change_type: 'temporary_pool_spend' } ),
		change( { id: 3, change_type: 'wound_taken' } ),
		change( { id: 4, change_type: 'night_loss_trait' } ),
		change( { id: 5, change_type: 'session_closed' } ),
		change( { id: 6, change_type: 'xp_earn' } ),
	];

	it( 'keeps every change for All', () => {
		expect( filterChanges( items, 'all' ) ).toHaveLength( 6 );
	} );

	it( 'leaves spends, wounds, losses and night resets out of Purchases', () => {
		expect(
			filterChanges( items, 'purchases' ).map( ( c ) => c.id )
		).toEqual( [ 1, 6 ] );
	} );

	it( 'keeps only the table play for Play', () => {
		expect( filterChanges( items, 'play' ).map( ( c ) => c.id ) ).toEqual( [
			2, 3, 4, 5,
		] );
		expect( isPlayChange( items[ 1 ] ) ).toBe( true );
		expect( isPlayChange( items[ 0 ] ) ).toBe( false );
	} );
} );

describe( 'groupOrders', () => {
	it( 'puts the changes of one submission together, newest order first', () => {
		const orders = groupOrders( [
			change( { id: 5, submission_id: 'b' } ),
			change( { id: 4, change_type: 'xp_earn' } ),
			change( { id: 3, submission_id: 'a' } ),
			change( { id: 2, submission_id: 'a' } ),
			change( { id: 1, submission_id: 'a' } ),
		] );

		expect( orders.map( ( o ) => o.key ) ).toEqual( [
			'submission-b',
			'change-4',
			'submission-a',
		] );
		expect( orders[ 2 ].items.map( ( c ) => c.id ) ).toEqual( [ 1, 2, 3 ] );
	} );

	it( 'stands a change with no submission alone, even when its id is empty', () => {
		const orders = groupOrders( [
			change( { id: 1, submission_id: null } ),
			change( { id: 2, submission_id: '' } ),
		] );
		expect( orders ).toHaveLength( 2 );
		expect( orders[ 0 ].submissionId ).toBeNull();
	} );
} );

describe( 'a line', () => {
	it( 'reads each status in the words History uses', () => {
		const label = ( overrides: Partial< CharacterChange > ) =>
			lineStatusLabel( change( overrides ) );

		expect( label( { status: 'pending' } ) ).toBe(
			'Waiting for a Storyteller'
		);
		expect( label( { status: 'approved', auto_approved: true } ) ).toBe(
			'Applied'
		);
		expect( label( { status: 'approved' } ) ).toBe( 'Approved' );
		expect( label( { status: 'rejected' } ) ).toBe( 'Refused' );
		expect( label( { status: 'withdrawn' } ) ).toBe( 'Withdrawn' );
		expect( label( { status: 'undone' } ) ).toBe( 'Undone' );
		expect( label( { status: 'forwarded' } ) ).toBe( 'Forwarded home' );
	} );

	it( 'reads a play log as applied, since the table applies it itself', () => {
		expect(
			lineStatus( change( { change_type: 'temporary_pool_spend' } ) )
		).toBe( 'applied' );
	} );

	it( "reads the sweep's refusal as no answer, and any other as the Storyteller's note", () => {
		expect(
			reviewerNote(
				change( {
					status: 'rejected',
					reviewed_by: 0,
					review_notes: 'Unanswered for 60 days.',
				} )
			)
		).toBe( 'No answer in 60 days' );
		expect(
			reviewerNote(
				change( {
					status: 'rejected',
					reviewed_by: 7,
					review_notes: 'Not this chronicle.',
				} )
			)
		).toBe( 'Not this chronicle.' );
		expect( reviewerNote( change( {} ) ) ).toBeNull();
	} );
} );

describe( 'an order', () => {
	const order = groupOrders( [
		change( {
			id: 1,
			submission_id: 's',
			xp_cost: 2,
			status: 'approved',
			auto_approved: true,
		} ),
		change( { id: 2, submission_id: 's', xp_cost: 3, status: 'pending' } ),
		change( { id: 3, submission_id: 's', xp_cost: 4, status: 'rejected' } ),
		change( { id: 4, submission_id: 's', xp_cost: 0, status: 'pending' } ),
	] )[ 0 ];

	it( 'is headed by when it was sent, how many changes and what it cost', () => {
		expect( orderCost( order ) ).toBe( 9 );
		expect( orderHead( order ) ).toBe(
			'Sent Nov 26, 2026 · 4 changes · 9 XP'
		);
	} );

	it( 'leaves the price off an order that cost nothing', () => {
		const free = groupOrders( [
			change( { id: 1, submission_id: 'f', xp_cost: 0 } ),
		] )[ 0 ];
		expect( orderHead( free ) ).toBe( 'Sent Nov 26, 2026 · 1 change' );
	} );

	it( 'summarises how its lines stand, leaving out what is zero', () => {
		expect( orderSummary( order ) ).toBe(
			'1 applied · 2 waiting · 1 refused'
		);
	} );

	it( 'counts the lines still waiting', () => {
		expect( waitingCount( order ) ).toBe( 2 );
	} );
} );

describe( 'the question before an undo', () => {
	it( 'says what a purchase gives back', () => {
		const text = undoQuestion(
			change( {
				change_type: 'modify_trait',
				xp_cost: 1,
				change_data: {
					block_slug: 'abilities',
					trait: { name: 'Occult', count: 4 },
					previous: { name: 'Occult', count: 3 },
				},
			} ),
			'Isolde Marchetti'
		);

		expect( text ).toContain( '1 XP goes back to Isolde Marchetti.' );
		expect( text ).toContain( 'Occult' );
	} );

	it( 'says what an award takes off', () => {
		expect(
			undoQuestion(
				change( {
					change_type: 'xp_earn',
					xp_cost: 0,
					change_data: { amount: 3, reason: 'Game night' },
				} ),
				'Isolde Marchetti'
			)
		).toContain( '3 XP comes off Isolde Marchetti.' );
	} );

	it( 'says what taking XP back gives again', () => {
		expect(
			undoQuestion(
				change( {
					change_type: 'xp_adjust',
					xp_cost: 0,
					change_data: { amount: -4, reason: 'Correction' },
				} ),
				'Isolde Marchetti'
			)
		).toContain( '4 XP goes back to Isolde Marchetti.' );
	} );

	it( 'asks plainly for a change that cost nothing', () => {
		expect(
			undoQuestion(
				change( { xp_cost: 0, change_data: {} } ),
				'Isolde Marchetti'
			)
		).not.toContain( 'XP' );
	} );

	it( 'counts the lines of a set a Storyteller can undo', () => {
		const order = groupOrders( [
			change( { id: 1, submission_id: 's', can_undo: true } ),
			change( { id: 2, submission_id: 's', can_undo: true } ),
			change( { id: 3, submission_id: 's', status: 'rejected' } ),
		] )[ 0 ];

		expect( undoableCount( order ) ).toBe( 2 );
		expect( undoSetQuestion( order ) ).toBe(
			'Undo this set? 2 changes are undone, newest first, or none of them are.'
		);
	} );
} );
