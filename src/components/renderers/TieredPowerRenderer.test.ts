import { elderLabel, numericLabel, namedLabel } from './TieredPowerRenderer';
import type { TieredPowerDefinition } from '../../types';

const DEFINITION: TieredPowerDefinition = {
	powers: [
		{
			name: 'Celerity',
			levels: [
				{ level: 1, tier: 'basic', power_name: 'Alacrity' },
				{ level: 2, tier: 'basic', power_name: 'Rapid Reflexes' },
				{ level: 3, tier: 'intermediate', power_name: 'Fleetness' },
				{ level: 4, tier: 'intermediate', power_name: 'Blurred Motion' },
				{ level: 5, tier: 'advanced', power_name: 'Lightning Reflexes' },
				{ level: null, tier: 'elder', power_name: 'Blink' },
				{ level: null, tier: 'elder', power_name: 'Unerring Aim' },
			],
		},
	],
};

describe( 'elderLabel/numericLabel/namedLabel (Decision 074)', () => {
	it( 'a genuine catalog-matched Elder+ pick shows its real catalog tier', () => {
		const held = { name: 'Celerity', power_name: 'Blink' };
		expect( elderLabel( DEFINITION, held ) ).toBe( 'Celerity: Blink (elder)' );
	} );

	it( 'a keep_custom power with a real derived level shows that level, not "(elder)"', () => {
		// The exact shape a real Dur-An-Ki/Sadhanna path takes after import (real bug:
		// 1506_chase_ashford_.gex's "Blood Magic" paths all rendered as generic "(elder)"
		// despite holding a genuine numbered level 1-5).
		const held = { name: 'Dur-An-Ki', power_name: 'Awakening of the Steel', level: 5 };
		expect( elderLabel( DEFINITION, held ) ).toBe( 'Dur-An-Ki: Awakening of the Steel 5' );
	} );

	it( 'a keep_custom power with no derivable level falls back to its own stored tier text, not the hardcoded default', () => {
		const held = { name: 'Dur-An-Ki', power_name: 'Something Unrecognizable', tier: 'unmatched, imported as-is' };
		expect( elderLabel( DEFINITION, held ) ).toBe( 'Dur-An-Ki: Something Unrecognizable (unmatched, imported as-is)' );
	} );

	it( 'a keep_custom power with neither a level nor a stored tier still falls back to "elder", never undefined', () => {
		const held = { name: 'Dur-An-Ki', power_name: 'Something Unrecognizable' };
		expect( elderLabel( DEFINITION, held ) ).toBe( 'Dur-An-Ki: Something Unrecognizable (elder)' );
	} );

	it( 'numericLabel: an ordinary numbered rung with no power_name just shows the level', () => {
		expect( numericLabel( DEFINITION, { name: 'Celerity', level: 3 } ) ).toBe( 'Celerity 3' );
	} );

	it( 'numericLabel: a power_name entry defers to elderLabel even in numeric mode', () => {
		const held = { name: 'Dur-An-Ki', power_name: 'Awakening of the Steel', level: 5 };
		expect( numericLabel( DEFINITION, held ) ).toBe( 'Dur-An-Ki: Awakening of the Steel 5' );
	} );

	it( 'namedLabel: a power_name entry defers to elderLabel regardless of the level argument', () => {
		const held = { name: 'Dur-An-Ki', power_name: 'Awakening of the Steel', level: 5 };
		expect( namedLabel( DEFINITION, held, 5 ) ).toBe( 'Dur-An-Ki: Awakening of the Steel 5' );
	} );

	it( 'namedLabel: an ordinary numbered rung looks up the real power_name for that level', () => {
		expect( namedLabel( DEFINITION, { name: 'Celerity', level: 1 }, 1 ) ).toBe( 'Alacrity' );
	} );
} );
