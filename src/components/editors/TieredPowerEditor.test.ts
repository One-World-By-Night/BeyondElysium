import { maxLevel, withCustomLadders, traditionOptionsFor, levelName, elderPickOptions } from './TieredPowerEditor';
import type { EditableHeldPower } from './TieredPowerEditor';
import type { TieredPowerDefinition } from '../../types';

function definition( levels: Array<{ level: number | null; power_name: string; tier: string }> ): TieredPowerDefinition {
	return {
		powers: [ { name: 'Celerity', levels: levels.map( ( l ) => ( { ...l, tier: l.tier as never } ) ) } ],
		sequential: false,
	};
}

describe( 'maxLevel (Decision 037)', () => {
	it( 'defaults to 5 when the data has at least 5 real numbered levels', () => {
		const def = definition( [
			{ level: 1, power_name: 'A', tier: 'basic' },
			{ level: 2, power_name: 'B', tier: 'basic' },
			{ level: 3, power_name: 'C', tier: 'intermediate' },
			{ level: 4, power_name: 'D', tier: 'intermediate' },
			{ level: 5, power_name: 'E', tier: 'advanced' },
			{ level: 6, power_name: 'F', tier: 'elder' },
			{ level: null, power_name: 'G', tier: 'elder' },
		] );

		expect( maxLevel( def, 'Celerity' ) ).toBe( 5 );
	} );

	it( 'never claims a level the data does not have, even under the default of 5', () => {
		const def = definition( [
			{ level: 1, power_name: 'A', tier: 'basic' },
			{ level: 2, power_name: 'B', tier: 'basic' },
			{ level: 3, power_name: 'C', tier: 'intermediate' },
		] );

		expect( maxLevel( def, 'Celerity' ) ).toBe( 3 );
	} );

	it( 'a trueMaxFor override wins over the default entirely, even past what the data shows', () => {
		const def = definition( [
			{ level: 1, power_name: 'A', tier: 'basic' },
			{ level: 2, power_name: 'B', tier: 'basic' },
		] );

		expect( maxLevel( def, 'Celerity', () => 9 ) ).toBe( 9 );
	} );

	it( 'a trueMaxFor that returns undefined for this power falls through to the default', () => {
		const def = definition( [
			{ level: 1, power_name: 'A', tier: 'basic' },
			{ level: 2, power_name: 'B', tier: 'basic' },
		] );

		expect( maxLevel( def, 'Celerity', ( name ) => ( name === 'Someone Else' ? 9 : undefined ) ) ).toBe( 2 );
	} );

	it( 'workflow-0.9.md Step 0c-2: an unknown power name with no levels at all defaults to the real 1-5 ladder, not a pinned 1', () => {
		const def = definition( [] );
		expect( maxLevel( def, 'Nonexistent' ) ).toBe( 5 );
	} );
} );

describe( 'withCustomLadders (workflow-0.9.md Step 0c)', () => {
	const baseDefinition: TieredPowerDefinition = { powers: [], sequential: false };

	function row( overrides: Partial<EditableHeldPower> ): EditableHeldPower {
		return { name: 'My Homebrew Path', level: 1, ...overrides };
	}

	it( 'gives a held custom power a real five-level placeholder ladder', () => {
		const withLadders = withCustomLadders( baseDefinition, [ row( { custom: true } ) ] );

		expect( maxLevel( withLadders, 'My Homebrew Path' ) ).toBe( 5 );

		const power = withLadders.powers.find( ( p ) => p.name === 'My Homebrew Path' );
		expect( power?.levels.map( ( l ) => l.power_name ) ).toEqual( [ 'One', 'Two', 'Three', 'Four', 'Five' ] );
		// Matches the real seeded 1-5 tier mapping (vampire-disciplines' own data).
		expect( power?.levels.map( ( l ) => l.tier ) ).toEqual( [
			'basic', 'basic', 'intermediate', 'intermediate', 'advanced',
		] );
	} );

	it( 'leaves the definition untouched when nothing held is custom', () => {
		const withLadders = withCustomLadders( baseDefinition, [ row( { custom: false } ) ] );
		expect( withLadders ).toBe( baseDefinition );
	} );

	it( 'does not shadow a real seeded power of the same name', () => {
		const seeded: TieredPowerDefinition = {
			powers: [ { name: 'Celerity', levels: [ { level: 1, tier: 'basic', power_name: 'Alacrity' } ] } ],
			sequential: false,
		};
		const withLadders = withCustomLadders( seeded, [ row( { name: 'Celerity', custom: true } ) ] );

		// Both entries exist - a real family a player also (incorrectly) marked custom
		// still finds its real seeded ladder first via Array.prototype.find().
		expect( maxLevel( withLadders, 'Celerity' ) ).toBe( 1 );
	} );
} );

describe( 'traditionOptionsFor (0.99.2 Blood magic, BM-4)', () => {
	const bloodMagic: TieredPowerDefinition = {
		blood_magic: true,
		traditions: [ 'Akhu', 'Bacaban', 'Necromancy', 'Sadhana', 'Wanga' ],
		powers: [
			{
				name: 'Path of Blood',
				traditions: { Akhu: null, Necromancy: null, Wanga: null },
				levels: [ { level: 1, tier: 'basic', power_name: 'Taste for Blood' } ],
			},
		],
		sequential: false,
	};

	it( "narrows to a power's own real offering traditions, not the whole block's list", () => {
		expect( traditionOptionsFor( bloodMagic, 'Path of Blood' ) ).toEqual( [ 'Akhu', 'Necromancy', 'Wanga' ] );
	} );

	it( "falls back to the block's own traditions list for a power not in the catalog (a custom pick)", () => {
		expect( traditionOptionsFor( bloodMagic, 'Some Homebrew Path' ) ).toEqual( [
			'Akhu', 'Bacaban', 'Necromancy', 'Sadhana', 'Wanga',
		] );
	} );

	it( 'falls back to harvesting "X: " prefixes for a block with no traditions list at all (pre-Blood-Magic convention)', () => {
		const legacy: TieredPowerDefinition = {
			powers: [
				{ name: 'Akhu: Path of Blood', levels: [] },
				{ name: 'Wanga: Path of Blood', levels: [] },
				{ name: 'Fortitude', levels: [] },
			],
			sequential: false,
		};

		expect( traditionOptionsFor( legacy, 'Fortitude' ) ).toEqual( [ 'Akhu', 'Wanga' ] );
	} );

	it( 'returns an empty list for an ordinary block with neither a traditions list nor any "X: " prefixed name', () => {
		const ordinary: TieredPowerDefinition = {
			powers: [ { name: 'Fortitude', levels: [] } ],
			sequential: false,
		};

		expect( traditionOptionsFor( ordinary, 'Fortitude' ) ).toEqual( [] );
	} );
} );

describe( 'levelName (checklist view - "the gap": some players want every named rung listed)', () => {
	it( 'looks up the real catalog name for a numbered rung', () => {
		const def = definition( [
			{ level: 1, power_name: 'Alacrity', tier: 'basic' },
			{ level: 2, power_name: 'Rapid Reflexes', tier: 'basic' },
		] );

		expect( levelName( def, 'Celerity', 2 ) ).toBe( 'Rapid Reflexes' );
	} );

	it( 'falls back to a plain "{name} {level}" label when the catalog has no entry there', () => {
		const def = definition( [ { level: 1, power_name: 'Alacrity', tier: 'basic' } ] );

		expect( levelName( def, 'Celerity', 3 ) ).toBe( 'Celerity 3' );
	} );

	it( 'falls back the same way for a power the catalog does not have at all (a custom power)', () => {
		const def = definition( [ { level: 1, power_name: 'Alacrity', tier: 'basic' } ] );

		expect( levelName( def, 'Nonexistent Power', 1 ) ).toBe( 'Nonexistent Power 1' );
	} );
} );

describe( 'elderPickOptions (0.99.2-workflow.md "Cost_Engine cannot price an Elder-tier purchase" - the picker UI)', () => {
	const MULTI_FAMILY_DEFINITION: TieredPowerDefinition = {
		sequential: false,
		powers: [
			{
				name: 'Celerity',
				levels: [
					{ level: 1, power_name: 'Alacrity', tier: 'basic' },
					{ level: 2, power_name: 'Rapid Reflexes', tier: 'basic' },
					{ level: 6, power_name: 'Precision', tier: 'elder' },
					{ level: null, power_name: 'Velocity', tier: 'master' },
				],
			},
			{
				name: 'Fortitude',
				levels: [
					{ level: 1, power_name: 'Toughness', tier: 'basic' },
					{ level: null, power_name: 'Draught of Endurance', tier: 'elder' },
				],
			},
		],
	};

	it( 'offers only Elder-and-above entries beyond the checklist range, for families already held', () => {
		const data: EditableHeldPower[] = [ { name: 'Celerity', level: 2 } ];

		const options = elderPickOptions( MULTI_FAMILY_DEFINITION, data );

		expect( options.map( ( o ) => o.value ) ).toEqual( [ 'Celerity: Precision', 'Celerity: Velocity' ] );
	} );

	it( 'does not offer a family the character does not hold at all yet', () => {
		const data: EditableHeldPower[] = [ { name: 'Celerity', level: 2 } ];

		const options = elderPickOptions( MULTI_FAMILY_DEFINITION, data );

		expect( options.some( ( o ) => o.family === 'Fortitude' ) ).toBe( false );
	} );

	it( 'excludes a pick already held, but still offers a sibling pick in the same family', () => {
		const data: EditableHeldPower[] = [
			{ name: 'Celerity', level: 2 },
			{ name: 'Celerity', power_name: 'Precision' },
		];

		const options = elderPickOptions( MULTI_FAMILY_DEFINITION, data );

		expect( options.map( ( o ) => o.value ) ).toEqual( [ 'Celerity: Velocity' ] );
	} );

	it( 'respects a trueMaxFor override when deciding what counts as "beyond the checklist"', () => {
		const data: EditableHeldPower[] = [ { name: 'Celerity', level: 2 } ];

		// Raising the true max to 6 makes Precision (level 6) reachable via the
		// checklist/stepper instead, so the picker should no longer offer it.
		const options = elderPickOptions( MULTI_FAMILY_DEFINITION, data, () => 6 );

		expect( options.map( ( o ) => o.value ) ).toEqual( [ 'Celerity: Velocity' ] );
	} );

	it( 'returns nothing for a family with no Elder-and-above entries in the catalog at all', () => {
		const data: EditableHeldPower[] = [ { name: 'Fortitude', level: 1 } ];
		const def: TieredPowerDefinition = {
			sequential: false,
			powers: [ { name: 'Fortitude', levels: [ { level: 1, power_name: 'Toughness', tier: 'basic' } ] } ],
		};

		expect( elderPickOptions( def, data ) ).toEqual( [] );
	} );
} );
