import { maxLevel, withCustomLadders } from './TieredPowerEditor';
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
