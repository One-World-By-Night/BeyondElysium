import {
	ladderCeiling,
	incrementLevel,
	decrementLevel,
	clampToCeiling,
	ladderRungLabel,
	ladderRung,
	traditionOptionsFor,
	pickOptionsFor,
	groupPickOptions,
	orderRanks,
	pickRankOf,
} from './TieredPowerEditor';
import type { EditableHeldPower } from './TieredPowerEditor';
import type { TieredPower, TieredPowerDefinition } from '../../types';

/** A vampire-disciplines-shaped block: 2/2/1 ladder, real `_meta`. */
function metaDefinition( powers: TieredPower[] ): TieredPowerDefinition {
	return {
		powers,
		sequential: false,
		_meta: {
			ranks: [
				'basic',
				'intermediate',
				'advanced',
				'elder',
				'master',
				'ascended',
				'methuselah',
			],
			ladder: { basic: 2, intermediate: 2, advanced: 1 },
			costs: {
				basic: 3,
				intermediate: 6,
				advanced: 9,
				elder: 12,
				master: 15,
				ascended: 18,
				methuselah: 21,
			},
		},
	};
}

function animalism(): TieredPower {
	return {
		name: 'Animalism',
		levels: [
			{ level: 1, tier: 'basic', power_name: 'Feral Whispers' },
			{ level: 2, tier: 'basic', power_name: 'Beckoning' },
			{ level: 3, tier: 'intermediate', power_name: 'Quell the Beast' },
			{
				level: 4,
				tier: 'intermediate',
				power_name: 'Subsume the Spirit',
			},
			{
				level: 5,
				tier: 'advanced',
				power_name: 'Drawing Out the Beast',
			},
		],
		elder: {
			elder: [
				{ level: null, tier: 'elder', power_name: 'Animal Succulence' },
				{
					level: null,
					tier: 'elder',
					power_name: 'Eye of the Szlachta',
				},
			],
			master: [
				{
					level: null,
					tier: 'master',
					power_name: 'Conquer the Beast',
				},
				{ level: null, tier: 'master', power_name: 'Stampede' },
			],
			ascended: [
				{ level: null, tier: 'ascended', power_name: 'Crimson Fury' },
			],
			methuselah: [
				{
					level: null,
					tier: 'methuselah',
					power_name: 'Unchain the Beast',
				},
			],
		},
	};
}

describe( 'ladderCeiling (1.2.10 §A - the D68 fix)', () => {
	it( 'sums _meta.ladder when the block declares one', () => {
		const def = metaDefinition( [ animalism() ] );
		expect( ladderCeiling( def ) ).toBe( 5 );
	} );

	it( 'sums a non-standard ladder exactly as declared, not assumed at 5', () => {
		const def: TieredPowerDefinition = {
			powers: [],
			sequential: false,
			_meta: {
				ranks: [ 'basic', 'intermediate' ],
				ladder: { basic: 1, intermediate: 1 },
				costs: { basic: 3, intermediate: 6 },
			},
		};
		expect( ladderCeiling( def ) ).toBe( 2 );
	} );

	it( 'falls back to 5 when the block carries no _meta at all', () => {
		const def: TieredPowerDefinition = {
			powers: [ animalism() ],
			sequential: false,
		};
		expect( ladderCeiling( def ) ).toBe( 5 );
	} );

	it( 'is unaffected by how many elder or overflow entries a family carries', () => {
		const bloated: TieredPower = {
			...animalism(),
			overflow: [
				{ level: 6, tier: 'basic', power_name: 'Merged Rung A' },
				{ level: 7, tier: 'intermediate', power_name: 'Merged Rung B' },
			],
		};
		const def = metaDefinition( [ bloated ] );
		expect( ladderCeiling( def ) ).toBe( 5 );
	} );

	/**
	 * 1.3.2 regression, watched failing first against the unfixed function (which read
	 * `ladder: {}` the same as "no ladder at all" and fell back to 5). Werewolf/Fera Gifts
	 * are declared exactly this way (`reference/CATALOG-JSON-FORMAT.md` §4.2, "a pick-only
	 * track... says so by declaring `ladder` as an explicit empty object") - every power is
	 * bought by name from `elder`, never rated on a stepper, so the ceiling must be 0, not a
	 * phantom 5-rung stepper nothing in the catalog prices.
	 */
	it( 'ceilings at 0 for a declared pick-only track, never the 5 fallback', () => {
		const def: TieredPowerDefinition = {
			powers: [],
			sequential: false,
			_meta: {
				ranks: [ 'basic', 'intermediate', 'advanced' ],
				ladder: {},
				costs: { basic: 3, intermediate: 6, advanced: 9 },
			},
		};
		expect( ladderCeiling( def ) ).toBe( 0 );
	} );

	it( 'still falls back to 5 when _meta exists but declares no ladder key at all', () => {
		// A pre-1.2.10 block re-emitted with a partial `_meta` and no `ladder` key -
		// distinct from `ladder: {}`, which is a real declaration. `ladder` is normally
		// required on `TieredPowerMeta`; the cast below constructs the "not even attempted"
		// shape this branch exists to distinguish from "attempted and empty".
		const def = {
			powers: [],
			sequential: false,
			_meta: {
				ranks: [ 'basic', 'intermediate', 'advanced' ],
				costs: { basic: 3, intermediate: 6, advanced: 9 },
			},
		} as unknown as TieredPowerDefinition;
		expect( ladderCeiling( def ) ).toBe( 5 );
	} );
} );

describe( 'incrementLevel / decrementLevel / clampToCeiling (E1 - a pick is never reachable from the stepper)', () => {
	it( 'increments normally below the ceiling', () => {
		expect( incrementLevel( 2, 5 ) ).toBe( 3 );
	} );

	it( 'refuses to increment past the ceiling', () => {
		expect( incrementLevel( 5, 5 ) ).toBe( 5 );
	} );

	it( 'repeated increments can never exceed the ceiling, however many times called', () => {
		let level = 1;
		const ceiling = 5;
		for ( let i = 0; i < 20; i++ ) {
			level = incrementLevel( level, ceiling );
		}
		expect( level ).toBe( 5 );
	} );

	it( 'decrements one step at a time and floors at 1', () => {
		expect( decrementLevel( 3 ) ).toBe( 2 );
		expect( decrementLevel( 1 ) ).toBe( 1 );
	} );

	it( 'a legacy total above the ceiling steps down one rung at a time, never jumping straight to the ceiling', () => {
		// 1.2.10-design-workflow.md §A′: Celerity 9 on a 5-rung ladder is a real,
		// approved total (5 rungs + 4 unnamed picks). Clicking "-" once must land on
		// 8, not silently collapse to the ceiling and discard the picks it represents.
		expect( decrementLevel( 9 ) ).toBe( 8 );
	} );

	it( 'clampToCeiling bounds an explicit target into [1, ceiling], used only by the checklist', () => {
		expect( clampToCeiling( 0, 5 ) ).toBe( 1 );
		expect( clampToCeiling( 3, 5 ) ).toBe( 3 );
		expect( clampToCeiling( 9, 5 ) ).toBe( 5 );
	} );
} );

describe( 'ladderRungLabel (checklist labels, no synthetic ladder for a custom power)', () => {
	it( 'looks up the real catalog name for a rung', () => {
		const def = metaDefinition( [ animalism() ] );
		expect( ladderRungLabel( def, 'Animalism', 2 ) ).toBe( 'Beckoning' );
	} );

	it( 'falls back to a plain "{name} {rung}" label for a custom power with no catalog entry at all', () => {
		const def: TieredPowerDefinition = { powers: [], sequential: false };
		expect( ladderRungLabel( def, 'My Homebrew Path', 1 ) ).toBe(
			'My Homebrew Path 1'
		);
	} );

	/**
	 * 1.2.11 D93, amending this case. It previously asserted the comma-join
	 * (`'Feral Claws, Eyes of the Beast'`) - one checkbox whose label was every name at
	 * the rank run together, which the owner reported from the real sheet. A rung is one
	 * thing you buy, so it gets one name; the others are carried as alternates rather
	 * than dropped. D66's "never roll up to a placeholder" still holds - no name is lost,
	 * and nothing renders as a bare `Protean 1`.
	 */
	it( 'names a tied rung once and carries the rest as alternates, never comma-joined (D66/D93)', () => {
		const tied: TieredPower = {
			name: 'Protean',
			levels: [
				{ level: null, tier: 'basic', power_name: 'Feral Claws' },
				{ level: null, tier: 'basic', power_name: 'Eyes of the Beast' },
				{
					level: 3,
					tier: 'intermediate',
					power_name: 'Earth Meld',
				},
				{ level: 4, tier: 'intermediate', power_name: 'Shapechange' },
				{ level: 5, tier: 'advanced', power_name: 'Metamorphosis' },
			],
		};
		const def = metaDefinition( [ tied ] );

		expect( ladderRungLabel( def, 'Protean', 1 ) ).toBe( 'Feral Claws' );
		expect( ladderRung( def, 'Protean', 1 ).alternates ).toEqual( [
			'Eyes of the Beast',
		] );
	} );
} );

/**
 * 1.2.11 D93 - the owner's own report, from a real sheet: the "List each level" view ran
 * every name at a rank together with commas. On pre-1.2.10 (production-shaped) data,
 * Animalism's rung 1 rendered
 * `Feral Whispers, Beckoning, Beast Within (2nd ed), Feral Speech (dark ages), Noah's Call (dark ages)`
 * in a single checkbox label.
 *
 * A rung is one purchase, so it shows one name: the line in play, which is the unqualified
 * printing. `seamQualifier()` is what says which that is - a level whose note carries
 * nothing beyond its tier word is the base printing, and an edition or tradition variant
 * carries `2nd ed` / `dark ages` / `Sabbat`.
 */
describe( 'ladderRung (D93 - one name per rung, the rest as alternates)', () => {
	/** The real pre-split shape: every basic-tier name tied at rung 1, variants noted. */
	function preSplitAnimalism(): TieredPower {
		return {
			name: 'Animalism',
			levels: [
				{
					level: null,
					tier: 'basic',
					power_name: 'Feral Whispers',
					note: 'basic',
				},
				{
					level: null,
					tier: 'basic',
					power_name: 'Beckoning',
					note: 'basic',
				},
				{
					level: null,
					tier: 'basic',
					power_name: 'Beast Within',
					note: 'basic, 2nd ed',
				},
				{
					level: null,
					tier: 'basic',
					power_name: 'Feral Speech',
					note: 'basic, dark ages',
				},
				{
					level: null,
					tier: 'basic',
					power_name: "Noah's Call",
					note: 'basic, dark ages',
				},
				{
					level: 3,
					tier: 'intermediate',
					power_name: 'Quell the Beast',
					note: 'int.',
				},
			],
		};
	}

	it( 'shows the unqualified printing and carries every variant as an alternate', () => {
		const def = metaDefinition( [ preSplitAnimalism() ] );

		const rung = ladderRung( def, 'Animalism', 1 );

		expect( rung.label ).toBe( 'Feral Whispers' );
		expect( rung.label ).not.toContain( ',' );
		expect( rung.alternates ).toEqual( [
			'Beckoning',
			'Beast Within (2nd ed)',
			'Feral Speech (dark ages)',
			"Noah's Call (dark ages)",
		] );
	} );

	it( 'falls back to source order when every name at the rank is qualified', () => {
		const allQualified: TieredPower = {
			name: "Path of Blood's Curse",
			levels: [
				{
					level: null,
					tier: 'basic',
					power_name: 'Stigmatize',
					note: 'basic, Tremere',
				},
				{
					level: null,
					tier: 'basic',
					power_name: 'Ravages of the Beast',
					note: 'basic, Sabbat',
				},
			],
		};
		const def = metaDefinition( [ allQualified ] );

		const rung = ladderRung( def, "Path of Blood's Curse", 1 );

		expect( rung.label ).toBe( 'Stigmatize (Tremere)' );
		expect( rung.alternates ).toEqual( [
			'Ravages of the Beast (Sabbat)',
		] );
	} );

	it( 'leaves an ordinary single-name rung exactly as it was', () => {
		const def = metaDefinition( [ animalism() ] );

		const rung = ladderRung( def, 'Animalism', 2 );

		expect( rung.label ).toBe( 'Beckoning' );
		expect( rung.alternates ).toEqual( [] );
	} );

	it( 'still falls back to "{name} {rung}" when the catalog has no entry at all', () => {
		const def: TieredPowerDefinition = { powers: [], sequential: false };

		const rung = ladderRung( def, 'My Homebrew Path', 1 );

		expect( rung.label ).toBe( 'My Homebrew Path 1' );
		expect( rung.alternates ).toEqual( [] );
	} );
} );

describe( 'pickOptionsFor / groupPickOptions (E2 - rank-grouped, elder container only)', () => {
	it( 'offers every not-yet-held pick from a held family, one entry per rank', () => {
		const def = metaDefinition( [ animalism() ] );
		const data: EditableHeldPower[] = [ { name: 'Animalism', level: 3 } ];

		const options = pickOptionsFor( def, data );

		expect( options.map( ( o ) => o.value ).sort() ).toEqual(
			[
				'Animalism: Animal Succulence',
				'Animalism: Eye of the Szlachta',
				'Animalism: Conquer the Beast',
				'Animalism: Stampede',
				'Animalism: Crimson Fury',
				'Animalism: Unchain the Beast',
			].sort()
		);
	} );

	it( 'does not offer a family the character does not hold at all yet', () => {
		const def = metaDefinition( [
			animalism(),
			{
				name: 'Fortitude',
				levels: [
					{ level: 1, tier: 'basic', power_name: 'Toughness' },
				],
				elder: {
					elder: [
						{
							level: null,
							tier: 'elder',
							power_name: 'Draught of Endurance',
						},
					],
				},
			},
		] );
		const data: EditableHeldPower[] = [ { name: 'Animalism', level: 2 } ];

		expect(
			pickOptionsFor( def, data ).some(
				( o ) => o.family === 'Fortitude'
			)
		).toBe( false );
	} );

	it( 'excludes a pick already held, but still offers a sibling pick in the same family and rank', () => {
		const def = metaDefinition( [ animalism() ] );
		const data: EditableHeldPower[] = [
			{ name: 'Animalism', level: 2 },
			{ name: 'Animalism', power_name: 'Animal Succulence' },
		];

		const options = pickOptionsFor( def, data );

		expect(
			options.some( ( o ) => o.powerName === 'Animal Succulence' )
		).toBe( false );
		expect(
			options.some( ( o ) => o.powerName === 'Eye of the Szlachta' )
		).toBe( true );
	} );

	it( 'never offers an overflow entry - overflow is never a pick', () => {
		const family: TieredPower = {
			...animalism(),
			overflow: [
				{ level: 6, tier: 'basic', power_name: 'Merged Rung' },
			],
		};
		const def = metaDefinition( [ family ] );
		const data: EditableHeldPower[] = [ { name: 'Animalism', level: 5 } ];

		const options = pickOptionsFor( def, data );

		expect( options.some( ( o ) => o.powerName === 'Merged Rung' ) ).toBe(
			false
		);
	} );

	it( 'returns nothing for a family with no elder container at all', () => {
		const def: TieredPowerDefinition = {
			sequential: false,
			powers: [
				{
					name: 'Fortitude',
					levels: [
						{ level: 1, tier: 'basic', power_name: 'Toughness' },
					],
				},
			],
		};
		const data: EditableHeldPower[] = [ { name: 'Fortitude', level: 1 } ];

		expect( pickOptionsFor( def, data ) ).toEqual( [] );
	} );

	it( 'groups options by rank in _meta.ranks order, and a rank with picks held while a lower rank has none is legal and unflagged', () => {
		// Elder has nothing left to offer (both already held); Master and above do.
		const def = metaDefinition( [ animalism() ] );
		const data: EditableHeldPower[] = [
			{ name: 'Animalism', level: 2 },
			{ name: 'Animalism', power_name: 'Animal Succulence' },
			{ name: 'Animalism', power_name: 'Eye of the Szlachta' },
		];

		const groups = groupPickOptions( def, data );

		expect( groups.map( ( g ) => g.rank ) ).toEqual( [
			'master',
			'ascended',
			'methuselah',
		] );
		// 'elder' never appears - not as an empty/disabled section, simply absent.
		expect( groups.some( ( g ) => g.rank === 'elder' ) ).toBe( false );
		const master = groups.find( ( g ) => g.rank === 'master' );
		expect( master?.options.map( ( o ) => o.powerName ).sort() ).toEqual(
			[ 'Conquer the Beast', 'Stampede' ].sort()
		);
	} );

	it( 'works for a block with no _meta at all - grouping falls back to encounter order', () => {
		const def: TieredPowerDefinition = {
			sequential: false,
			powers: [ animalism() ],
		};
		const data: EditableHeldPower[] = [ { name: 'Animalism', level: 5 } ];

		const groups = groupPickOptions( def, data );

		expect( groups.length ).toBeGreaterThan( 0 );
		expect( groups.every( ( g ) => g.options.length > 0 ) ).toBe( true );
	} );
} );

describe( 'orderRanks', () => {
	it( 'sorts present ranks by their position in the declared vocabulary', () => {
		expect(
			orderRanks(
				[ 'methuselah', 'elder', 'master' ],
				[
					'basic',
					'intermediate',
					'advanced',
					'elder',
					'master',
					'ascended',
					'methuselah',
				]
			)
		).toEqual( [ 'elder', 'master', 'methuselah' ] );
	} );

	it( 'places an undeclared rank after every declared one, without dropping it', () => {
		expect(
			orderRanks( [ 'mystery', 'elder' ], [ 'elder', 'master' ] )
		).toEqual( [ 'elder', 'mystery' ] );
	} );

	it( 'keeps the given order when nothing is declared', () => {
		expect( orderRanks( [ 'master', 'elder' ] ) ).toEqual( [
			'master',
			'elder',
		] );
	} );
} );

describe( 'pickRankOf', () => {
	const def = metaDefinition( [ animalism() ] );

	it( 'resolves a held pick to its real rank via the elder container', () => {
		expect(
			pickRankOf( def, {
				name: 'Animalism',
				power_name: 'Conquer the Beast',
			} )
		).toBe( 'master' );
	} );

	it( 'falls back to the overflow container’s own tier for a merged-but-held entry', () => {
		const family: TieredPower = {
			...animalism(),
			overflow: [
				{ level: 6, tier: 'basic', power_name: 'Merged Rung' },
			],
		};
		const withOverflow = metaDefinition( [ family ] );

		expect(
			pickRankOf( withOverflow, {
				name: 'Animalism',
				power_name: 'Merged Rung',
			} )
		).toBe( 'basic' );
	} );

	it( 'falls back to the row’s own stored tier when the catalog has no match', () => {
		expect(
			pickRankOf( def, {
				name: 'Animalism',
				power_name: 'Some Untracked Pick',
				tier: 'methuselah',
			} )
		).toBe( 'methuselah' );
	} );

	it( 'falls back to "elder" as the last resort', () => {
		expect(
			pickRankOf( def, {
				name: 'Animalism',
				power_name: 'Truly Unknown',
			} )
		).toBe( 'elder' );
	} );

	it( 'never reads the placeholder tier "***" as a real rank', () => {
		expect(
			pickRankOf( def, {
				name: 'Animalism',
				power_name: 'Imported Unresolved',
				tier: '***',
			} )
		).toBe( 'elder' );
	} );
} );

describe( 'traditionOptionsFor (0.99.2 Blood magic, BM-4 - unaffected by the levels/elder split)', () => {
	const bloodMagic: TieredPowerDefinition = {
		blood_magic: true,
		traditions: [ 'Akhu', 'Bacaban', 'Necromancy', 'Sadhana', 'Wanga' ],
		powers: [
			{
				name: 'Path of Blood',
				traditions: { Akhu: null, Necromancy: null, Wanga: null },
				levels: [
					{ level: 1, tier: 'basic', power_name: 'Taste for Blood' },
				],
			},
		],
		sequential: false,
	};

	it( "puts a power's own real offering traditions first, but never narrows to just them (1.1.0 D5 - the Hunter's Wind/Dur An Ki bug)", () => {
		expect( traditionOptionsFor( bloodMagic, 'Path of Blood' ) ).toEqual( [
			'Akhu',
			'Necromancy',
			'Wanga',
			'Bacaban',
			'Sadhana',
		] );
	} );

	it( "falls back to the block's own traditions list for a power not in the catalog (a custom pick)", () => {
		expect(
			traditionOptionsFor( bloodMagic, 'Some Homebrew Path' )
		).toEqual( [ 'Akhu', 'Bacaban', 'Necromancy', 'Sadhana', 'Wanga' ] );
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

		expect( traditionOptionsFor( legacy, 'Fortitude' ) ).toEqual( [
			'Akhu',
			'Wanga',
		] );
	} );

	it( 'returns an empty list for an ordinary block with neither a traditions list nor any "X: " prefixed name', () => {
		const ordinary: TieredPowerDefinition = {
			powers: [ { name: 'Fortitude', levels: [] } ],
			sequential: false,
		};

		expect( traditionOptionsFor( ordinary, 'Fortitude' ) ).toEqual( [] );
	} );
} );
