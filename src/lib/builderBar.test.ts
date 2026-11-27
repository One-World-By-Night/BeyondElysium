import { barModel, limitsForStep } from './builderBar';
import type { BuilderStep } from './builderSteps';
import type { CreationStep } from '../types';
import type { CreationTally } from '../types/character';

const step = ( sections: string[], key = 's' ): BuilderStep => ( {
	key,
	kind: 'rule',
	title: key,
	sections,
} );

const rules: CreationStep[] = [
	{
		kind: 'prioritized',
		label: 'Attributes',
		sections: [ 'phys', 'soc', 'ment' ],
		amounts: [ 7, 5, 3 ],
	},
	{ kind: 'budget', label: 'Abilities', section: 'abil', count: 5 },
	{
		kind: 'free',
		label: 'Free Traits',
		pool: 'Free Traits',
		points: 5,
		rates: { phys: 1, abil: 1, virt: 2, 'res.Willpower': 3 },
	},
];

function tally( overrides: Partial< CreationTally > = {} ): CreationTally {
	return {
		steps: [
			{
				kind: 'prioritized',
				label: 'Attributes',
				applies: true,
				sections: [
					{
						section: 'phys',
						label: 'Physical',
						used: 4,
						allowed: 7,
						over: false,
					},
					{
						section: 'soc',
						label: 'Social',
						used: 5,
						allowed: 5,
						over: false,
					},
					{
						section: 'ment',
						label: 'Mental',
						used: 3,
						allowed: 3,
						over: false,
					},
				],
			},
			{
				kind: 'budget',
				label: 'Abilities',
				applies: true,
				section: 'abil',
				section_label: 'Abilities',
				used: 3,
				allowed: 5,
				over: false,
				quotas: [],
			},
		],
		pools: {
			'Free Traits': {
				label: 'Free Traits',
				own: 5,
				earned: 0,
				spent: 2,
				left: 3,
			},
		},
		limits: [],
		grants_missing: [],
		unbuyable: [],
		xp: { starting: 30, needed: 5, left: 25 },
		...overrides,
	};
}

describe( 'barModel', () => {
	it( 'reads the prioritized rows of the step, the free pool and the experience', () => {
		const model = barModel(
			step( [ 'phys', 'soc', 'ment' ] ),
			tally(),
			rules
		);
		expect( model.lines.map( ( line ) => line.text ) ).toEqual( [
			'Physical: 4 of 7',
			'Social: 5 of 5',
			'Mental: 3 of 3',
			'Free Traits: 3 of 5 left',
			'XP 25 of 30 left',
		] );
		expect( model.note ).toBe(
			'Your largest category counts as 7, the next as 5, the last as 3.'
		);
		expect( model.mark ).toBe( 'open' );
	} );

	it( 'says nothing about a section that is on another step', () => {
		const model = barModel( step( [ 'abil' ] ), tally(), rules );
		expect( model.lines.map( ( line ) => line.text ) ).toEqual( [
			'Abilities: 3 of 5',
			'Free Traits: 3 of 5 left',
			'XP 25 of 30 left',
		] );
		expect( model.note ).toBeNull();
	} );

	it( 'drops the free pool from a step none of its rates reach', () => {
		const model = barModel( step( [ 'other' ] ), tally(), rules );
		expect( model.lines.map( ( line ) => line.key ) ).toEqual( [ 'xp' ] );
		expect( model.mark ).toBe( 'none' );
	} );

	it( 'marks a step done when every budget on it is used exactly', () => {
		const full = tally();
		( full.steps[ 1 ] as { used: number } ).used = 5;
		expect( barModel( step( [ 'abil' ] ), full, rules ).mark ).toBe(
			'done'
		);
	} );

	it( 'says where the rest comes from when a budget is over, first the free pool, then XP', () => {
		const over = tally();
		( over.steps[ 1 ] as { used: number; over: boolean } ).used = 6;
		( over.steps[ 1 ] as { used: number; over: boolean } ).over = true;
		const line = barModel( step( [ 'abil' ] ), over, rules ).lines[ 0 ];
		expect( line.text ).toBe(
			'Abilities: 6 of 5 · 1 over: from Free Traits'
		);
		expect( line.state ).toBe( 'over' );
		expect( barModel( step( [ 'abil' ] ), over, rules ).mark ).toBe(
			'over'
		);

		over.pools[ 'Free Traits' ].left = 0;
		expect(
			barModel( step( [ 'abil' ] ), over, rules ).lines[ 0 ].text
		).toBe( 'Abilities: 6 of 5 · 1 over: from XP' );
	} );

	it( 'shows a quota with the budget it belongs to', () => {
		const withQuota = tally();
		( withQuota.steps[ 1 ] as { quotas: unknown[] } ).quotas = [
			{ label: 'One breed Gift', min: 1, met: 0, ok: false },
		];
		const lines = barModel( step( [ 'abil' ] ), withQuota, rules ).lines;
		expect( lines[ 1 ] ).toMatchObject( {
			text: 'One breed Gift: 0 of 1',
			state: 'short',
		} );
	} );

	it( 'leaves out a rule that does not apply', () => {
		const skipped = tally();
		skipped.steps[ 1 ].applies = false;
		expect(
			barModel( step( [ 'abil' ] ), skipped, rules ).lines.map(
				( line ) => line.key
			)
		).toEqual( [ 'pool-Free Traits', 'xp' ] );
	} );

	it( 'is empty before the first answer', () => {
		expect( barModel( step( [ 'abil' ] ), null, rules ) ).toEqual( {
			lines: [],
			note: null,
			mark: 'none',
		} );
	} );

	it( 'marks a step over when a limit flag points into it', () => {
		const flagged = tally( {
			limits: [
				{
					target: 'virt.Mercy',
					target_label: 'Mercy',
					reason: 'ceiling',
					value: 3,
					ceiling: 1,
				},
			],
		} );
		expect( limitsForStep( step( [ 'virt' ] ), flagged ) ).toHaveLength(
			1
		);
		expect( limitsForStep( step( [ 'abil' ] ), flagged ) ).toHaveLength(
			0
		);
		expect( barModel( step( [ 'virt' ] ), flagged, rules ).mark ).toBe(
			'over'
		);
	} );

	it( 'hides the experience line when the chronicle starts characters with none and the build needs none', () => {
		const none = tally( { xp: { starting: 0, needed: 0, left: 0 } } );
		expect(
			barModel( step( [ 'abil' ] ), none, rules ).lines.map(
				( line ) => line.key
			)
		).not.toContain( 'xp' );
	} );
} );
