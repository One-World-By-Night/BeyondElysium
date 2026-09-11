import { describeChange } from './describeChange';

describe( 'describeChange', () => {
	it( 'shows a true before/after for a tiered_power level change', () => {
		expect(
			describeChange( 'modify_trait', { trait: { name: 'Celerity', level: 4 }, previous: { name: 'Celerity', level: 2 } } )
		).toBe( 'Celerity 2 → 4' );
	} );

	it( 'degrades to only the new value when previous is absent', () => {
		expect( describeChange( 'modify_trait', { trait: { name: 'Celerity', level: 4 } } ) ).toBe( 'Celerity → level 4' );
	} );

	it( 'shows a true before/after for a trait_list count change', () => {
		expect(
			describeChange( 'modify_trait', { trait: { name: 'Boon', count: 3 }, previous: { name: 'Boon', count: 1 } } )
		).toBe( 'Boon x1 → x3' );
	} );

	it( 'describes adding a new tiered power', () => {
		expect( describeChange( 'add_trait', { trait: { name: 'Fortitude', level: 1 } } ) ).toBe( 'Added Fortitude 1' );
	} );

	it( 'describes adding a trait_list item with a specialization', () => {
		expect( describeChange( 'add_trait', { trait: { name: 'Contacts', count: 1, specialization: 'Police' } } ) ).toBe(
			'Added Contacts (Police)'
		);
	} );

	it( 'describes removing a trait', () => {
		expect( describeChange( 'remove_trait', { trait: { name: 'Potence' } } ) ).toBe( 'Removed Potence' );
	} );

	it( 'describes a resource pool change', () => {
		expect( describeChange( 'modify_resource', { values: { Blood: { permanent: 10, temporary: 8 } } } ) ).toBe(
			'Blood: 10 perm / 8 temp'
		);
	} );

	it( 'describes an identity field change', () => {
		expect( describeChange( 'modify_identity', { fields: { Clan: 'Toreador' } } ) ).toBe( 'Clan → Toreador' );
	} );

	it( 'describes XP earn with a reason', () => {
		expect( describeChange( 'xp_earn', { amount: 3, reason: 'Game attendance' } ) ).toBe( '+3 XP (Game attendance)' );
	} );
} );
