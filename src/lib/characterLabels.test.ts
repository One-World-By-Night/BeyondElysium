import {
	characterStatusLabel,
	creatureTypeName,
	factionTypeLabel,
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
