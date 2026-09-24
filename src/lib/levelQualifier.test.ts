import { levelQualifier, familyHasSeam, seamQualifier } from './levelQualifier';
import type { TieredPower } from '../types';

describe( 'levelQualifier', () => {
	it( 'finds nothing in a note that is only a tier', () => {
		// The overwhelmingly common shape - 1,083 `mortal-numina` levels say exactly this.
		for ( const note of [
			'basic',
			'int.',
			'adv.',
			'elder',
			'master',
			'asc.',
			'meth.',
			'innate',
		] ) {
			expect( levelQualifier( note ) ).toBeUndefined();
		}
	} );

	it( 'unwraps a parenthesised tradition', () => {
		expect( levelQualifier( 'Basic (Sabbat)' ) ).toBe( 'Sabbat' );
		expect( levelQualifier( 'Intermediate (Tremere)' ) ).toBe( 'Tremere' );
		expect( levelQualifier( 'Advanced (Setite)' ) ).toBe( 'Setite' );
	} );

	it( 'reads a bare trailing qualifier', () => {
		expect( levelQualifier( 'basic ritual' ) ).toBe( 'ritual' );
		expect( levelQualifier( 'int. ritual' ) ).toBe( 'ritual' );
	} );

	it( 'reads a comma-separated qualifier', () => {
		expect( levelQualifier( 'basic, dark ages' ) ).toBe( 'dark ages' );
		expect( levelQualifier( 'adv., wyld west' ) ).toBe( 'wyld west' );
	} );

	it( 'finds nothing in a note with no tier word at all', () => {
		expect( levelQualifier( 'telepathy + presence' ) ).toBeUndefined();
		expect( levelQualifier( 'legend' ) ).toBeUndefined();
	} );

	it( 'finds nothing in an empty or absent note', () => {
		expect( levelQualifier( '' ) ).toBeUndefined();
		expect( levelQualifier( null ) ).toBeUndefined();
		expect( levelQualifier( undefined ) ).toBeUndefined();
	} );
} );

const bloodsCurse: TieredPower = {
	name: "Path of Blood's Curse",
	levels: [
		{ note: 'Basic (Sabbat)', tier: 'basic', power_name: 'Ravages' },
		{
			note: 'Advanced (Sabbat)',
			tier: 'advanced',
			power_name: 'Withering',
		},
		{ note: 'Basic (Tremere)', tier: 'basic', power_name: 'Stigmatize' },
		{ note: 'Advanced (Tremere)', tier: 'advanced', power_name: 'Fall' },
	],
} as unknown as TieredPower;

const setite: TieredPower = {
	name: 'Path of the Warrior',
	levels: [
		{ note: 'Basic (Setite)', tier: 'basic', power_name: 'One' },
		{
			note: 'Intermediate (Setite)',
			tier: 'intermediate',
			power_name: 'Two',
		},
	],
} as unknown as TieredPower;

const plain: TieredPower = {
	name: 'Celerity',
	levels: [
		{ note: 'basic', tier: 'basic', power_name: 'Alacrity' },
		{ note: 'int.', tier: 'intermediate', power_name: 'Swiftness' },
	],
} as unknown as TieredPower;

const halfVariant: TieredPower = {
	name: 'Quietus',
	levels: [
		{ note: 'basic', tier: 'basic', power_name: 'Silence' },
		{ note: 'basic, dark ages', tier: 'basic', power_name: 'Scorpion' },
	],
} as unknown as TieredPower;

describe( 'familyHasSeam', () => {
	it( 'sees the seam in two concatenated traditions', () => {
		expect( familyHasSeam( bloodsCurse ) ).toBe( true );
	} );

	it( 'sees no seam where every level agrees', () => {
		expect( familyHasSeam( setite ) ).toBe( false );
		expect( familyHasSeam( plain ) ).toBe( false );
	} );

	it( 'counts "no qualifier" as a value, so a half-variant family is a seam', () => {
		expect( familyHasSeam( halfVariant ) ).toBe( true );
	} );

	it( 'handles an absent family', () => {
		expect( familyHasSeam( undefined ) ).toBe( false );
	} );
} );

describe( 'seamQualifier', () => {
	it( 'names the tradition on a merged family', () => {
		expect( seamQualifier( bloodsCurse, bloodsCurse.levels[ 2 ] ) ).toBe(
			'Tremere'
		);
	} );

	it( 'stays quiet on a consistent family, however loud its notes are', () => {
		// 216 levels say `(Setite)`. Printing it on all of them distinguishes nothing.
		expect( seamQualifier( setite, setite.levels[ 0 ] ) ).toBeUndefined();
	} );

	it( 'stays quiet for the unqualified half of a seam', () => {
		expect(
			seamQualifier( halfVariant, halfVariant.levels[ 0 ] )
		).toBeUndefined();
		expect( seamQualifier( halfVariant, halfVariant.levels[ 1 ] ) ).toBe(
			'dark ages'
		);
	} );
} );
