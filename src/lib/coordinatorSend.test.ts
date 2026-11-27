import type {
	ChangeCoordinator,
	CoordinatorRequest,
} from '../types/coordinator';
import {
	activeRequest,
	choiceFor,
	defaultOffices,
	requestParts,
	sendBody,
	searchTermFor,
	lineForChange,
	lineForPick,
	sendableIds,
	needsCoordinatorMessage,
	coordinatorNote,
} from './coordinatorSend';

function request(
	over: Partial< CoordinatorRequest > = {}
): CoordinatorRequest {
	return {
		id: 1,
		change_id: 10,
		character_id: 5,
		purpose: 'approval',
		state: 'sent',
		oat_entry_id: 33012,
		step: 'staff_review',
		step_label: 'With the chronicle’s Storyteller',
		sent_at: '2026-11-26 14:00:00',
		checked_at: '2026-11-26 14:05:00',
		answered_at: null,
		...over,
	};
}

function coordinator(
	kind: 'approval' | 'notify' | null,
	req: CoordinatorRequest | null = null,
	names: string[] = []
): ChangeCoordinator {
	return { signal: { kind, coordinators: names }, request: req };
}

describe( 'activeRequest', () => {
	it( 'is the request while it is out or waiting', () => {
		for ( const state of [ 'sending', 'unconfirmed', 'sent' ] as const ) {
			const req = request( { state } );
			expect(
				activeRequest( { coordinator: coordinator( 'approval', req ) } )
			).toBe( req );
		}
	} );

	it( 'is nothing once the Toolkit has answered or it was only filed', () => {
		for ( const state of [
			'approved',
			'denied',
			'cancelled',
			'filed',
		] as const ) {
			expect(
				activeRequest( {
					coordinator: coordinator(
						'approval',
						request( { state } )
					),
				} )
			).toBeNull();
		}
	} );

	it( 'is nothing for a change that carries no coordinator field', () => {
		expect( activeRequest( {} ) ).toBeNull();
	} );
} );

describe( 'choiceFor', () => {
	it( 'offers the choice on a Coordinator Approval not yet sent', () => {
		expect( choiceFor( { coordinator: coordinator( 'approval' ) } ) ).toBe(
			'approval'
		);
	} );

	it( 'offers the notification choice on a Notify', () => {
		expect( choiceFor( { coordinator: coordinator( 'notify' ) } ) ).toBe(
			'notify'
		);
	} );

	it( 'says with-coordinator once it is sent, whatever its reason says', () => {
		expect(
			choiceFor( {
				coordinator: coordinator( 'approval', request() ),
			} )
		).toBe( 'sent' );
		expect(
			choiceFor( { coordinator: coordinator( null, request() ) } )
		).toBe( 'sent' );
	} );

	it( 'offers nothing for an ordinary change or a chronicle that does not send', () => {
		expect( choiceFor( { coordinator: coordinator( null ) } ) ).toBe(
			'none'
		);
		expect( choiceFor( {} ) ).toBe( 'none' );
	} );

	it( 'offers the choice again after an answer that left the change waiting', () => {
		expect(
			choiceFor( {
				coordinator: coordinator(
					'approval',
					request( { state: 'approved' } )
				),
			} )
		).toBe( 'approval' );
	} );
} );

describe( 'requestParts', () => {
	it( 'reads where it is, its number, its step and when it was read', () => {
		expect( requestParts( request() ) ).toEqual( [
			'With the Coordinator',
			'R&U #33012',
			'With the chronicle’s Storyteller',
		] );
	} );

	it( 'says it is not confirmed when no answer came', () => {
		expect(
			requestParts(
				request( {
					state: 'unconfirmed',
					oat_entry_id: null,
					step: null,
					step_label: '',
				} )
			)
		).toEqual( [ 'Sent, not confirmed' ] );
	} );

	it( 'leaves out a step it does not know', () => {
		expect( requestParts( request( { step_label: '' } ) ) ).toEqual( [
			'With the Coordinator',
			'R&U #33012',
		] );
	} );
} );

describe( 'defaultOffices', () => {
	const offices = [
		{ slug: 'assamite', name: 'Assamite Coordinator' },
		{ slug: 'brujah', name: 'Brujah Coordinator' },
		{ slug: 'gangrel', name: 'Gangrel' },
	];

	it( 'ticks the offices the reason names, by name or slug', () => {
		expect( defaultOffices( [ 'Assamite', 'brujah' ], offices ) ).toEqual( [
			'assamite',
			'brujah',
		] );
	} );

	it( 'matches a name that already says Coordinator', () => {
		expect( defaultOffices( [ 'Gangrel Coordinator' ], offices ) ).toEqual(
			[ 'gangrel' ]
		);
	} );

	it( 'ticks nothing for a name it cannot place', () => {
		expect(
			defaultOffices( [ 'Varies appropriate genre' ], offices )
		).toEqual( [] );
	} );

	it( 'ticks each office once', () => {
		expect( defaultOffices( [ 'Assamite', 'assamite' ], offices ) ).toEqual(
			[ 'assamite' ]
		);
	} );
} );

describe( 'searchTermFor', () => {
	it( 'is the trait or power a change is about', () => {
		expect(
			searchTermFor( {
				change_type: 'add_trait',
				change_data: {
					trait: { name: 'Obtenebration', power_name: 'Shadow Step' },
				},
			} )
		).toBe( 'Shadow Step' );
		expect(
			searchTermFor( {
				change_type: 'add_trait',
				change_data: { trait: { name: 'Dual-Blooded' } },
			} )
		).toBe( 'Dual-Blooded' );
	} );

	it( 'is the pool or identity field of those changes', () => {
		expect(
			searchTermFor( {
				change_type: 'modify_resource',
				change_data: { values: { Balance: { permanent: 6 } } },
			} )
		).toBe( 'Balance' );
		expect(
			searchTermFor( {
				change_type: 'modify_identity',
				change_data: { fields: { Generation: 9 } },
			} )
		).toBe( 'Generation' );
	} );

	it( 'is empty when there is nothing to search for', () => {
		expect(
			searchTermFor( { change_type: 'xp_earn', change_data: {} } )
		).toBe( '' );
	} );
} );

describe( 'sendableIds', () => {
	it( 'takes the waiting changes of one character that ask for a Coordinator and are not sent', () => {
		const rows = [
			{ id: 1, character_id: 5, coordinator: coordinator( 'approval' ) },
			{ id: 2, character_id: 5, coordinator: coordinator( null ) },
			{
				id: 3,
				character_id: 5,
				coordinator: coordinator( 'approval', request() ),
			},
			{ id: 4, character_id: 6, coordinator: coordinator( 'approval' ) },
		];

		expect( sendableIds( rows, 5 ) ).toEqual( [ 1 ] );
	} );
} );

describe( 'sendBody', () => {
	it( 'is what the send route takes', () => {
		expect(
			sendBody( {
				characterId: 5,
				changeIds: [ 1, 2 ],
				rules: [
					{
						id: 4125,
						label: 'Assamite - Merits - Dual-Blooded',
						genre: 'assamite',
						category: 'Merits',
						condition: 'Dual-Blooded',
						pc_level: 'coordinator_approval',
						npc_level: 'coordinator_approval',
						coordinator: 'assamite',
					},
				],
				coordinators: [ 'assamite' ],
				note: '  Please look.  ',
				purpose: 'approval',
			} )
		).toEqual( {
			character_id: 5,
			change_ids: [ 1, 2 ],
			rules: [
				{
					id: 4125,
					pc_level: 'coordinator_approval',
					npc_level: 'coordinator_approval',
				},
			],
			coordinators: [ 'assamite' ],
			note: 'Please look.',
			purpose: 'approval',
		} );
	} );
} );

describe( 'needsCoordinatorMessage', () => {
	it( 'says how many changes were left for a Storyteller to decide', () => {
		expect( needsCoordinatorMessage( 1 ) ).toContain(
			'1 change needs a Coordinator'
		);
		expect( needsCoordinatorMessage( 3 ) ).toContain(
			'3 changes need a Coordinator'
		);
	} );
} );

describe( 'lines', () => {
	it( 'reads a waiting change as a line to send', () => {
		expect(
			lineForChange( {
				id: 12,
				change_type: 'add_trait',
				change_data: { trait: { name: 'Dual-Blooded' } },
				reason: 'Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]\nSecond line',
				coordinator: coordinator( 'approval', null, [ 'Assamite' ] ),
			} )
		).toEqual( {
			id: 12,
			label: 'Dual-Blooded',
			reason: 'Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]',
			coordinators: [ 'Assamite' ],
			term: 'Dual-Blooded',
		} );
	} );

	it( 'reads a pick of a new character as a line to send', () => {
		expect(
			lineForPick( {
				key: 'abc123def456',
				section: 'vampire-merits',
				section_label: 'Merits',
				name: 'Dual-Blooded',
				reason: 'Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]',
				signal: { kind: 'approval', coordinators: [ 'Assamite' ] },
				request: null,
			} )
		).toEqual( {
			key: 'abc123def456',
			label: 'Dual-Blooded',
			reason: 'Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]',
			coordinators: [ 'Assamite' ],
			term: 'Dual-Blooded',
		} );
	} );

	it( 'has no reason line when there is none', () => {
		expect(
			lineForChange( {
				id: 1,
				change_type: 'add_trait',
				change_data: { trait: { name: 'X' } },
				reason: null,
			} ).reason
		).toBeNull();
	} );
} );

describe( 'sendBody for picks', () => {
	it( 'names the picks and no changes', () => {
		const body = sendBody( {
			characterId: 5,
			pickKeys: [ 'abc123def456' ],
			rules: [],
			coordinators: [],
			note: '',
			purpose: 'notify',
		} );

		expect( body.pick_keys ).toEqual( [ 'abc123def456' ] );
		expect( body.change_ids ).toBeUndefined();
		expect( body.purpose ).toBe( 'notify' );
	} );
} );

describe( 'coordinatorNote', () => {
	it( 'tells a player a Storyteller may send an approval on, naming the office', () => {
		expect(
			coordinatorNote( {
				kind: 'approval',
				coordinators: [ 'Assamite' ],
			} )
		).toBe(
			'A Storyteller may send this to the Assamite Coordinator through the Archivist Toolkit.'
		);
	} );

	it( 'says a Coordinator when none is named', () => {
		expect(
			coordinatorNote( { kind: 'approval', coordinators: [] } )
		).toBe(
			'A Storyteller may send this to a Coordinator through the Archivist Toolkit.'
		);
	} );

	it( 'says nothing for a notification, a plain change or no signal', () => {
		expect(
			coordinatorNote( { kind: 'notify', coordinators: [ 'Hunter' ] } )
		).toBeNull();
		expect(
			coordinatorNote( { kind: null, coordinators: [] } )
		).toBeNull();
		expect( coordinatorNote( undefined ) ).toBeNull();
	} );
} );
