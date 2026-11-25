import {
	approvalLevelLabel,
	changeStatusLabel,
	characterStatusLabel,
	creatureTypeName,
	factionTypeLabel,
	plotBadgeLabel,
} from './characterLabels';

describe( 'characterStatusLabel', () => {
	it( 'names each status a character can have', () => {
		expect( characterStatusLabel( 'active' ) ).toBe( 'Active' );
		expect( characterStatusLabel( 'inactive' ) ).toBe( 'Inactive' );
		expect( characterStatusLabel( 'retired' ) ).toBe( 'Retired' );
		expect( characterStatusLabel( 'dead' ) ).toBe( 'Dead' );
		expect( characterStatusLabel( 'pending' ) ).toBe( 'Pending' );
	} );

	it( 'reads a status it does not know as stored', () => {
		expect( characterStatusLabel( 'missing' ) ).toBe( 'missing' );
	} );
} );

describe( 'creatureTypeName', () => {
	const stacks = [
		{ slug: 'vampire', name: 'Vampire' },
		{ slug: 'kueijin', name: 'Kuei-Jin' },
		{ slug: 'bete', name: 'Bête' },
	];

	it( 'gives the type its own name', () => {
		expect( creatureTypeName( 'kueijin', stacks ) ).toBe( 'Kuei-Jin' );
		expect( creatureTypeName( 'bete', stacks ) ).toBe( 'Bête' );
	} );

	it( 'reads a type that is not listed as stored', () => {
		expect( creatureTypeName( 'mortal', stacks ) ).toBe( 'mortal' );
		expect( creatureTypeName( 'vampire', [] ) ).toBe( 'vampire' );
	} );
} );

describe( 'factionTypeLabel', () => {
	it( 'names each kind of group the picker suggests', () => {
		expect( factionTypeLabel( 'court' ) ).toBe( 'Court' );
		expect( factionTypeLabel( 'chantry' ) ).toBe( 'Chantry' );
		expect( factionTypeLabel( 'other' ) ).toBe( 'Other' );
	} );

	it( 'reads a kind a chronicle typed itself as typed', () => {
		expect( factionTypeLabel( 'Wolf Pack of the North' ) ).toBe(
			'Wolf Pack of the North'
		);
	} );
} );

describe( 'changeStatusLabel', () => {
	it( 'names each status a stored change can have', () => {
		expect( changeStatusLabel( 'pending' ) ).toBe( 'Pending' );
		expect( changeStatusLabel( 'approved' ) ).toBe( 'Approved' );
		expect( changeStatusLabel( 'rejected' ) ).toBe( 'Refused' );
		expect( changeStatusLabel( 'forwarded' ) ).toBe( 'Forwarded' );
	} );

	it( 'reads a status it does not know as stored', () => {
		expect( changeStatusLabel( 'on_hold' ) ).toBe( 'on_hold' );
	} );
} );

describe( 'approvalLevelLabel', () => {
	it( 'says what each approval level means', () => {
		expect( approvalLevelLabel( 'auto' ) ).toBe( 'Automatic' );
		expect( approvalLevelLabel( 'st' ) ).toBe( 'Needs a Storyteller' );
	} );

	it( 'reads a level it does not know as stored', () => {
		expect( approvalLevelLabel( 'council' ) ).toBe( 'council' );
	} );
} );

describe( 'plotBadgeLabel', () => {
	it( 'shows where an active plot stands', () => {
		expect( plotBadgeLabel( 'active', 'pending' ) ).toBe( 'Not started' );
		expect( plotBadgeLabel( 'active', 'active' ) ).toBe( 'Under way' );
		expect( plotBadgeLabel( 'active', 'finished' ) ).toBe( 'Finished' );
	} );

	it( 'shows a resolved or archived plot as that', () => {
		expect( plotBadgeLabel( 'resolved', 'finished' ) ).toBe( 'Resolved' );
		expect( plotBadgeLabel( 'archived', 'active' ) ).toBe( 'Archived' );
	} );

	it( 'reads a status it does not know as stored', () => {
		expect( plotBadgeLabel( 'paused', 'active' ) ).toBe( 'paused' );
	} );
} );
