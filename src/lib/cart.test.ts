import {
	cartChip,
	cartLines,
	cartMeter,
	cartTotals,
	cartView,
	confirmationLine,
	droppedMessage,
	heldLine,
} from './cart';
import type { SchemaBlock } from '../types';
import type { ChangePreviewResult, ChangeRequest } from '../types/character';

const occult: ChangeRequest = {
	change_type: 'modify_trait',
	category: 'abilities',
	change_data: {
		block_slug: 'abilities',
		trait: { name: 'Occult', count: 4 },
		previous: { name: 'Occult', count: 3 },
	},
};
const ritual: ChangeRequest = {
	change_type: 'add_trait',
	category: 'rituals',
	change_data: { block_slug: 'rituals', trait: { name: 'Fire Walk' } },
};
const bad: ChangeRequest = {
	change_type: 'add_trait',
	category: 'merits',
	change_data: { block_slug: 'merits', trait: { name: 'Nope' } },
};

const results: ChangePreviewResult[] = [
	{ xp_cost: 1, approval_level: 'auto', approval_reason: null },
	{
		xp_cost: 3,
		approval_level: 'st',
		approval_reason: 'Rituals need approval.',
	},
	{
		xp_cost: 0,
		approval_level: null,
		approval_reason: null,
		invalid: true,
		error: { code: 'x', message: 'That cannot be bought twice.' },
	} as unknown as ChangePreviewResult,
];

describe( 'cartLines', () => {
	it( 'groups each line by whether it applies now, waits or is refused', () => {
		const lines = cartLines( [ occult, ritual, bad ], results );

		expect( lines.map( ( l ) => l.group ) ).toEqual( [
			'applies',
			'waits',
			'refused',
		] );
		expect( lines[ 0 ].text ).toContain( 'Occult' );
		expect( lines[ 0 ].cost ).toBe( 1 );
		expect( lines[ 1 ].cost ).toBe( 3 );
		expect( lines[ 1 ].reason ).toBe( 'Rituals need approval.' );
		expect( lines[ 2 ].cost ).toBe( 0 );
		expect( lines[ 2 ].priceNote ).toBe( 'That cannot be bought twice.' );
	} );

	it( 'tells a line that a Storyteller may send it to a Coordinator, only while it waits', () => {
		const asked = {
			xp_cost: 3,
			approval_level: 'st',
			approval_reason: 'Coordinator Approval — Assamite Coordinator',
			coordinator: { kind: 'approval', coordinators: [ 'Assamite' ] },
		} as unknown as ChangePreviewResult;
		const applies = {
			xp_cost: 1,
			approval_level: 'auto',
			approval_reason: null,
			coordinator: { kind: 'approval', coordinators: [ 'Assamite' ] },
		} as unknown as ChangePreviewResult;

		const lines = cartLines( [ ritual, occult ], [ asked, applies ] );

		expect( lines[ 0 ].coordinatorNote ).toBe(
			'A Storyteller may send this to the Assamite Coordinator through the Archivist Toolkit.'
		);
		expect( lines[ 1 ].coordinatorNote ).toBeNull();
		expect(
			cartLines( [ ritual ], results )[ 0 ].coordinatorNote
		).toBeNull();
	} );

	it( 'reads a pool with no temporary points as its rating alone', () => {
		const blocks = {
			resources: {
				section_type: 'resource_pool',
				definition: {
					pools: [
						{ name: 'Morality', rating_only: true },
						{ name: 'Blood' },
					],
				},
			},
		} as unknown as Record< string, SchemaBlock >;
		const change = ( pool: string ): ChangeRequest => ( {
			change_type: 'modify_resource',
			category: 'resources',
			change_data: {
				block_slug: 'resources',
				values: { [ pool ]: { permanent: 6, temporary: 6 } },
			},
		} );

		expect(
			cartLines( [ change( 'Morality' ) ], undefined, blocks )[ 0 ].text
		).toBe( 'Morality → 6' );
		expect(
			cartLines( [ change( 'Blood' ) ], undefined, blocks )[ 0 ].text
		).toBe( 'Blood: 6 perm / 6 temp' );
	} );

	it( 'marks every line as being priced before the server has answered', () => {
		const lines = cartLines( [ occult, ritual ], undefined );
		expect( lines.map( ( l ) => l.group ) ).toEqual( [
			'pricing',
			'pricing',
		] );
	} );

	it( 'keeps the place of each line among the pending changes', () => {
		expect(
			cartLines( [ occult, ritual ], results ).map( ( l ) => l.index )
		).toEqual( [ 0, 1 ] );
	} );
} );

describe( 'cartTotals', () => {
	it( 'adds the priced lines and splits what applies from what waits', () => {
		const totals = cartTotals(
			cartLines( [ occult, ritual, bad ], results )
		);

		expect( totals ).toEqual( {
			count: 3,
			cost: 4,
			appliesCost: 1,
			waitsCost: 3,
			refused: 1,
			pricing: 0,
		} );
	} );

	it( 'counts a line not priced yet and adds nothing for it', () => {
		const totals = cartTotals( cartLines( [ occult ], undefined ) );
		expect( totals.pricing ).toBe( 1 );
		expect( totals.cost ).toBe( 0 );
	} );
} );

describe( 'cartMeter', () => {
	it( 'says what is free to spend with an empty cart', () => {
		expect( cartMeter( 0, 0, 10 ) ).toEqual( {
			lead: '10 XP to spend',
			tail: null,
			over: false,
		} );
	} );

	it( 'says the size and price of the cart and what it leaves', () => {
		expect( cartMeter( 3, 6, 4 ) ).toEqual( {
			lead: 'Cart: 3 · 6 XP',
			tail: '4 left',
			over: false,
		} );
	} );

	it( 'says how far over the budget the cart runs', () => {
		expect( cartMeter( 2, 5, -2 ) ).toEqual( {
			lead: 'Cart: 2 · 5 XP',
			tail: '2 XP over',
			over: true,
		} );
	} );
} );

describe( 'cartChip', () => {
	it( 'is empty with an empty cart', () => {
		expect( cartChip( 0, 0 ) ).toBeNull();
	} );

	it( 'names the size and price of the cart', () => {
		expect( cartChip( 3, 6 ) ).toBe( 'Cart: 3 · 6 XP' );
	} );
} );

describe( 'heldLine', () => {
	it( 'names what the waiting lines hold', () => {
		expect( heldLine( 6 ) ).toBe( '6 XP held until a Storyteller decides' );
	} );

	it( 'says nothing when nothing is held', () => {
		expect( heldLine( 0 ) ).toBeNull();
	} );
} );

describe( 'confirmationLine', () => {
	it( 'says what applied and what waits with its held XP', () => {
		expect( confirmationLine( 2, 3, 6 ) ).toBe(
			'Sent. 2 applied now. 3 are waiting for a Storyteller, with 6 XP held.'
		);
	} );

	it( 'says only what happened', () => {
		expect( confirmationLine( 2, 0, 0 ) ).toBe( 'Sent. 2 applied now.' );
		expect( confirmationLine( 0, 1, 3 ) ).toBe(
			'Sent. 1 is waiting for a Storyteller, with 3 XP held.'
		);
		expect( confirmationLine( 0, 2, 0 ) ).toBe(
			'Sent. 2 are waiting for a Storyteller.'
		);
	} );
} );

describe( 'cartView', () => {
	const character = { xp_unspent: 16, xp_held: 6 };

	it( 'reads an empty cart as the XP free to spend', () => {
		const view = cartView( [], null, character, false );

		expect( view.free ).toBe( 10 );
		expect( view.meter.lead ).toBe( '10 XP to spend' );
		expect( view.chip ).toBeNull();
		expect( view.over ).toBe( false );
	} );

	it( 'takes what is left from the server pricing of the same list', () => {
		const view = cartView(
			[ occult, ritual ],
			{ results: results.slice( 0, 2 ), running_xp_unspent: 6 },
			character,
			false
		);

		expect( view.left ).toBe( 6 );
		expect( view.meter.lead ).toBe( 'Cart: 2 · 4 XP' );
		expect( view.meter.tail ).toBe( '6 left' );
		expect( view.chip ).toBe( 'Cart: 2 · 4 XP' );
	} );

	it( 'is over when the cart costs more than is free', () => {
		const view = cartView(
			[ occult ],
			{ results: results.slice( 0, 1 ), running_xp_unspent: -2 },
			character,
			false
		);

		expect( view.over ).toBe( true );
		expect( view.meter.tail ).toBe( '2 XP over' );
	} );

	it( 'is never over for a Storyteller, and ignores what is held', () => {
		const view = cartView(
			[ occult ],
			{ results: results.slice( 0, 1 ), running_xp_unspent: -2 },
			character,
			true
		);

		expect( view.free ).toBe( 16 );
		expect( view.over ).toBe( false );
	} );

	it( 'treats a pricing made for a different list as not priced yet', () => {
		const view = cartView(
			[ occult, ritual ],
			{ results: results.slice( 0, 1 ), running_xp_unspent: 9 },
			character,
			false
		);

		expect( view.lines.map( ( l ) => l.group ) ).toEqual( [
			'pricing',
			'pricing',
		] );
		expect( view.left ).toBe( 10 );
	} );
} );

describe( 'droppedMessage', () => {
	it( 'names the sections that changed meanwhile', () => {
		expect( droppedMessage( [ 'Abilities', 'Merits' ] ) ).toBe(
			'The sheet changed since you added this: Abilities, Merits. Add it again if you still want it.'
		);
	} );

	it( 'says nothing when nothing changed', () => {
		expect( droppedMessage( [] ) ).toBeNull();
	} );
} );
