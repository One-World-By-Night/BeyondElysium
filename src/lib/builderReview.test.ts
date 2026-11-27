import {
	firstLine,
	reviewModel,
	sectionLines,
	startsLine,
} from './builderReview';
import type { BuilderStep } from './builderSteps';
import type { CreationTally } from '../types/character';

const steps: BuilderStep[] = [
	{ key: 'concept', kind: 'concept', title: 'Concept', sections: [ 'id' ] },
	{
		key: 'section-abil',
		kind: 'rule',
		title: 'Abilities',
		sections: [ 'abil' ],
	},
	{ key: 'story', kind: 'story', title: 'Story', sections: [] },
	{ key: 'review', kind: 'review', title: 'Review', sections: [] },
];

function tally( overrides: Partial< CreationTally > = {} ): CreationTally {
	return {
		steps: [],
		pools: {},
		limits: [],
		grants_missing: [],
		unbuyable: [],
		xp: { starting: 10, needed: 4, left: 6 },
		needs_storyteller: [],
		starts: { status: 'active', reasons: [] },
		...overrides,
	};
}

const input = {
	steps,
	sheet: {
		id: { Clan: 'Tremere', Generation: '9', Sect: '' },
		abil: [ { name: 'Occult', count: 3 }, { name: 'Alertness' } ],
	},
	blocks: {
		id: { section_type: 'identity_field' },
		abil: { section_type: 'trait_list' },
	},
	titles: { id: 'Identity', abil: 'Abilities' },
	name: 'Isolde',
	stackSlug: 'vampire',
	biography: '<p>Born in Vienna.</p><p>Second line.</p>',
};

describe( 'reviewModel', () => {
	it( 'lists each step’s picks with their counts and leaves out an empty field', () => {
		const model = reviewModel( { ...input, tally: tally() } );
		expect( model.groups.map( ( group ) => group.title ) ).toEqual( [
			'Concept',
			'Abilities',
		] );
		expect( model.groups[ 0 ].sections[ 0 ].lines ).toEqual( [
			'Clan: Tremere',
			'Generation: 9',
		] );
		expect( model.groups[ 1 ].sections[ 0 ].lines ).toEqual( [
			'Occult ×3',
			'Alertness',
		] );
		expect( model.background ).toBe( 'Born in Vienna.' );
	} );

	it( 'can be created when nothing must be fixed', () => {
		const model = reviewModel( { ...input, tally: tally() } );
		expect( model.mustFix ).toEqual( [] );
		expect( model.canCreate ).toBe( true );
		expect( model.next ).toBe( 'Starts active.' );
	} );

	it( 'waits on a name and a creature type', () => {
		const model = reviewModel( {
			...input,
			name: '  ',
			stackSlug: '',
			tally: tally(),
		} );
		expect( model.mustFix ).toEqual( [
			'Give the character a name.',
			'Choose a creature type.',
		] );
		expect( model.canCreate ).toBe( false );
	} );

	it( 'waits on a build over the starting experience, with both numbers', () => {
		const model = reviewModel( {
			...input,
			tally: tally( { xp: { starting: 10, needed: 23, left: -13 } } ),
		} );
		expect( model.mustFix ).toEqual( [
			'This build needs 23 XP and a new character here starts with 10.',
		] );
		expect( model.canCreate ).toBe( false );
	} );

	it( 'waits on what a player may not start with but not for a Storyteller', () => {
		const unbuyable = [
			{
				kind: 'book_max' as const,
				section: 'bal',
				pool: 'Balance',
				value: 8,
				max: 5,
			},
		];
		expect(
			reviewModel( { ...input, tally: tally( { unbuyable } ) } ).canCreate
		).toBe( false );
		expect(
			reviewModel( {
				...input,
				isManager: true,
				tally: tally( { unbuyable } ),
			} ).canCreate
		).toBe( true );
	} );

	it( 'keeps a limit flag as a warning that does not block', () => {
		const model = reviewModel( {
			...input,
			tally: tally( {
				limits: [
					{
						target: 'virt.Mercy',
						target_label: 'Mercy',
						reason: 'ceiling',
						value: 3,
						ceiling: 1,
					},
				],
			} ),
		} );
		expect( model.warnings ).toHaveLength( 1 );
		expect( model.warnings[ 0 ] ).toContain( 'Mercy' );
		expect( model.canCreate ).toBe( true );
	} );

	it( 'carries the picks a Storyteller will look at with their reasons and the status that follows', () => {
		const model = reviewModel( {
			...input,
			tally: tally( {
				needs_storyteller: [
					{
						section: 'id',
						section_label: 'Identity',
						name: 'Generation: 9',
						reason: 'OWbN Character Bylaws 3.b.ii.',
					},
				],
				starts: { status: 'pending', reasons: [ 'rules' ] },
			} ),
		} );
		expect( model.needs ).toEqual( [
			{
				name: 'Generation: 9',
				section: 'Identity',
				reason: 'OWbN Character Bylaws 3.b.ii.',
			},
		] );
		expect( model.next ).toBe(
			'Starts pending: 1 pick needs a Storyteller.'
		);
		expect( model.canCreate ).toBe( true );
	} );
} );

describe( 'startsLine', () => {
	it( 'says each way a character starts', () => {
		expect( startsLine( 'active', [], 0 ) ).toBe( 'Starts active.' );
		expect( startsLine( 'pending', [ 'chronicle' ], 0 ) ).toBe(
			'Starts pending: this chronicle reviews new characters.'
		);
		expect( startsLine( 'pending', [ 'rules' ], 2 ) ).toBe(
			'Starts pending: 2 picks need a Storyteller.'
		);
		expect( startsLine( 'pending', [ 'chronicle', 'rules' ], 2 ) ).toBe(
			'Starts pending: this chronicle reviews new characters, and 2 picks need a Storyteller.'
		);
		expect( startsLine( 'pending', [ 'join', 'rules' ], 2 ) ).toBe(
			'This is a request to join; a Storyteller approves it before you can open the sheet.'
		);
	} );
} );

describe( 'sectionLines and firstLine', () => {
	it( 'describes a pool and a tiered power', () => {
		expect(
			sectionLines( 'resource_pool', {
				Rage: { permanent: 5, temporary: 5 },
			} )
		).toEqual( [ 'Rage (5)' ] );
		expect(
			sectionLines( 'tiered_power', [ { name: 'Auspex', level: 2 } ] )
		).toEqual( [ 'Auspex 2' ] );
		expect( sectionLines( 'trait_list', null ) ).toEqual( [] );
	} );

	it( 'reads the first line of a rich-text background', () => {
		expect( firstLine( '' ) ).toBe( '' );
		expect( firstLine( '<p></p><p>  Hello &amp; welcome </p>' ) ).toBe(
			'Hello & welcome'
		);
		expect( firstLine( 'one<br>two' ) ).toBe( 'one' );
	} );
} );
