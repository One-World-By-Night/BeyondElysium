import {
	describeTraitListHolding,
	describeTieredPowerHolding,
	describeResourcePoolHolding,
	describeIdentityFieldHolding,
	describeHolding,
} from './describeHolding';

describe( 'describeHolding', () => {
	describe( 'describeTraitListHolding', () => {
		it( 'shows a bare name for a single held count', () => {
			expect( describeTraitListHolding( { name: 'Auspex' } ) ).toBe( 'Auspex' );
			expect( describeTraitListHolding( { name: 'Boon', count: 1 } ) ).toBe( 'Boon' );
		} );

		it( 'shows ×N for a count above one', () => {
			expect( describeTraitListHolding( { name: 'Iron Will', count: 5 } ) ).toBe( 'Iron Will ×5' );
		} );
	} );

	describe( 'describeTieredPowerHolding', () => {
		it( 'shows the bare name when neither level nor power_name is set', () => {
			expect( describeTieredPowerHolding( { name: 'Fortitude' } ) ).toBe( 'Fortitude' );
		} );

		it( 'shows a numbered level', () => {
			expect( describeTieredPowerHolding( { name: 'Celerity', level: 3 } ) ).toBe( 'Celerity 3' );
		} );

		it( 'shows "Name: PowerName" for an Elder-and-above pick', () => {
			expect( describeTieredPowerHolding( { name: 'Thaumaturgy', power_name: 'Blood Rage' } ) ).toBe(
				'Thaumaturgy: Blood Rage'
			);
		} );

		it( 'prefixes a tradition, matching TieredPowerRenderer.tsx\'s withTradition()', () => {
			expect(
				describeTieredPowerHolding( { name: 'Ash Path', level: 2, tradition: 'Necromancy' } )
			).toBe( 'Necromancy: Ash Path 2' );
		} );
	} );

	describe( 'describeResourcePoolHolding', () => {
		it( 'shows the permanent rating from a {permanent,temporary} value', () => {
			expect( describeResourcePoolHolding( 'Willpower', { permanent: 6, temporary: 4 } ) ).toBe( 'Willpower (6)' );
		} );

		it( 'shows a bare-int value directly', () => {
			expect( describeResourcePoolHolding( 'Blood', 10 ) ).toBe( 'Blood (10)' );
		} );
	} );

	describe( 'describeIdentityFieldHolding', () => {
		it( 'shows "Name: value" for a set value', () => {
			expect( describeIdentityFieldHolding( 'Generation', 9 ) ).toBe( 'Generation: 9' );
		} );

		it( 'shows an em-dash for an unset value, never omits the line (§4.4)', () => {
			expect( describeIdentityFieldHolding( 'Sire', null ) ).toBe( 'Sire: —' );
			expect( describeIdentityFieldHolding( 'Sire', '' ) ).toBe( 'Sire: —' );
		} );
	} );

	describe( 'describeHolding dispatch', () => {
		it( 'dispatches trait_list to describeTraitListHolding', () => {
			expect( describeHolding( 'trait_list', { name: 'Boon', count: 2 } ) ).toBe( 'Boon ×2' );
		} );

		it( 'dispatches tiered_power to describeTieredPowerHolding', () => {
			expect( describeHolding( 'tiered_power', { name: 'Auspex', level: 1 } ) ).toBe( 'Auspex 1' );
		} );
	} );
} );
