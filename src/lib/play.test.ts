import {
	canRegain,
	canSpend,
	canSpendPermanent,
	healthLevels,
	healthTrack,
	nightLossesFrom,
	poolRows,
	temporaryControls,
	waitingSpends,
	woundsFrom,
} from './play';
import type { SchemaBlock } from '../types';

const vampireLevels = [
	{ name: 'Healthy', count: 2 },
	{ name: 'Bruised', count: 3 },
	{ name: 'Wounded', count: 2 },
	{ name: 'Incapacitated', count: 1 },
	{ name: 'Torpor', count: 1 },
];

const none = { bashing: 0, lethal: 0, aggravated: 0 };

describe( 'the health track', () => {
	it( 'draws one box for each held level', () => {
		const track = healthTrack( vampireLevels, none );
		expect( track.boxes ).toHaveLength( 9 );
		expect( track.boxes.map( ( b ) => b.level ) ).toEqual( [
			'Healthy',
			'Healthy',
			'Bruised',
			'Bruised',
			'Bruised',
			'Wounded',
			'Wounded',
			'Incapacitated',
			'Torpor',
		] );
		expect( track.boxes.every( ( b ) => b.damage === null ) ).toBe( true );
		expect( track.reached ).toBe( 'Healthy' );
		expect( track.beyond ).toBe( 0 );
	} );

	it( 'fills aggravated first, then lethal, then bashing', () => {
		const track = healthTrack( vampireLevels, {
			bashing: 2,
			lethal: 1,
			aggravated: 1,
		} );
		expect( track.boxes.map( ( b ) => b.damage ).slice( 0, 6 ) ).toEqual( [
			'aggravated',
			'lethal',
			'bashing',
			'bashing',
			null,
			null,
		] );
	} );

	it( 'names the level of the first box still undamaged', () => {
		expect(
			healthTrack( vampireLevels, { ...none, bashing: 1 } ).reached
		).toBe( 'Healthy' );
		expect(
			healthTrack( vampireLevels, { ...none, bashing: 2 } ).reached
		).toBe( 'Bruised' );
		expect(
			healthTrack( vampireLevels, { ...none, lethal: 5 } ).reached
		).toBe( 'Wounded' );
	} );

	it( 'stays on the last level and counts damage beyond the track', () => {
		const track = healthTrack( vampireLevels, { ...none, lethal: 11 } );
		expect( track.boxes.every( ( b ) => b.damage === 'lethal' ) ).toBe(
			true
		);
		expect( track.reached ).toBe( 'Torpor' );
		expect( track.beyond ).toBe( 2 );
	} );

	it( 'has no level to name for a character with none', () => {
		expect( healthTrack( [], none ) ).toEqual( {
			boxes: [],
			reached: '',
			beyond: 0,
		} );
	} );

	it( 'reads held rows as levels, a row with no count being one', () => {
		expect(
			healthLevels( [
				{ name: 'Healthy', count: 2 },
				{ name: 'Bruised' },
				{ name: 'Wounded', count: '3' },
				{ name: 'Gone', count: 0 },
				{ count: 4 },
				'x',
			] )
		).toEqual( [
			{ name: 'Healthy', count: 2 },
			{ name: 'Bruised', count: 1 },
			{ name: 'Wounded', count: 3 },
		] );
		expect( healthLevels( null ) ).toEqual( [] );
	} );
} );

describe( 'wounds and tonight', () => {
	it( 'reads wounds from the sheet, zero when never recorded', () => {
		expect( woundsFrom( {} ) ).toEqual( none );
		expect(
			woundsFrom( { _wound_state: { lethal: '2', bashing: 1, bad: 9 } } )
		).toEqual( { bashing: 1, lethal: 2, aggravated: 0 } );
		expect( woundsFrom( { _wound_state: { lethal: -3 } } ).lethal ).toBe(
			0
		);
	} );

	it( 'reads what was lost or expended tonight', () => {
		expect( nightLossesFrom( {} ) ).toEqual( {
			lostTraits: [],
			expended: [],
		} );
		expect(
			nightLossesFrom( {
				_night_losses: {
					lost_traits: [ { block_slug: 'a', name: 'Graceful' }, {} ],
					expended_abilities: [
						{ block_slug: 'b', name: 'Brawl', levels: 2 },
						{ block_slug: 'b', name: 'Dodge' },
					],
				},
			} )
		).toEqual( {
			lostTraits: [ 'Graceful' ],
			expended: [
				{ name: 'Brawl', levels: 2 },
				{ name: 'Dodge', levels: 1 },
			],
		} );
	} );
} );

describe( 'permanent spends that wait', () => {
	it( 'counts the pending permanent spends on each pool', () => {
		expect(
			waitingSpends( [
				{
					change_type: 'pool_permanent_spent',
					change_data: { block_slug: 'a', pool_name: 'Willpower' },
				},
				{
					change_type: 'pool_permanent_spent',
					change_data: { block_slug: 'a', pool_name: 'Willpower' },
				},
				{
					change_type: 'pool_permanent_spent',
					change_data: { block_slug: 'b', pool_name: 'Gnosis' },
				},
				{ change_type: 'add_trait', change_data: {} },
				{ change_type: 'pool_permanent_spent', change_data: null },
			] )
		).toEqual( { 'a.Willpower': 2, 'b.Gnosis': 1 } );
		expect( waitingSpends( [] ) ).toEqual( {} );
	} );
} );

describe( 'the pools', () => {
	const block = ( slug: string, pools: unknown[] ) =>
		( {
			slug,
			name: slug,
			section_type: 'resource_pool',
			definition: { pools },
		} ) as unknown as SchemaBlock;

	const blocks = {
		'vampire-resources': block( 'vampire-resources', [
			{ name: 'Blood', default_start: 10 },
			{ name: 'Willpower', default_start: 3, permanent_spend: true },
			{ name: 'Morality', default_start: 7, rating_only: true },
		] ),
		'changeling-resources': block( 'changeling-resources', [
			{ name: 'Banality', default_start: 3, max: 10, accumulates: true },
		] ),
		'werewolf-renown': block( 'werewolf-renown', [
			{ name: 'Honor', default_start: 0, accumulates: true },
		] ),
		'vampire-health': {
			slug: 'vampire-health',
			section_type: 'trait_list',
			definition: { items: [] },
		} as unknown as SchemaBlock,
	};

	it( 'lists the pools a sheet stores, in the template order, marking the permanent-spend ones', () => {
		const rows = poolRows(
			{
				'vampire-resources': {
					Blood: { permanent: 10, temporary: 8 },
					Willpower: { permanent: 6, temporary: 6 },
				},
			},
			blocks,
			[ 'vampire-health', 'vampire-resources' ]
		);
		expect( rows.map( ( r ) => r.poolName ) ).toEqual( [
			'Blood',
			'Willpower',
		] );
		expect( rows[ 0 ] ).toMatchObject( {
			blockSlug: 'vampire-resources',
			label: 'Blood',
			permanent: 10,
			temporary: 8,
			permanentSpend: false,
		} );
		expect( rows[ 1 ].permanentSpend ).toBe( true );
	} );

	it( 'carries each pool kind and a build-up pool maximum', () => {
		const rows = poolRows(
			{
				'vampire-resources': {
					Blood: { permanent: 10, temporary: 8 },
					Morality: { permanent: 7, temporary: 7 },
				},
				'changeling-resources': {
					Banality: { permanent: 2, temporary: 7 },
				},
				'werewolf-renown': { Honor: { permanent: 4, temporary: 3 } },
			},
			blocks,
			[ 'vampire-resources', 'changeling-resources', 'werewolf-renown' ]
		);
		expect(
			rows.map( ( r ) => [ r.poolName, r.kind, r.ceiling ] )
		).toEqual( [
			[ 'Blood', 'ordinary', undefined ],
			[ 'Morality', 'rating_only', undefined ],
			[ 'Banality', 'accumulates', 10 ],
			[ 'Honor', 'accumulates', undefined ],
		] );
	} );

	it( 'leaves out a pool the sheet holds no pair for and a block that is not in the template', () => {
		expect(
			poolRows(
				{ 'vampire-resources': { Blood: { permanent: 10 } } },
				blocks,
				[ 'vampire-resources' ]
			)
		).toEqual( [] );
		expect(
			poolRows(
				{
					'vampire-resources': {
						Blood: { permanent: 1, temporary: 1 },
					},
				},
				blocks,
				[]
			)
		).toEqual( [] );
	} );

	it( 'spends while temporary points remain and regains up to the permanent rating', () => {
		const ordinary = { kind: 'ordinary' as const };
		expect( canSpend( { ...ordinary, temporary: 1 } ) ).toBe( true );
		expect( canSpend( { ...ordinary, temporary: 0 } ) ).toBe( false );
		expect( canRegain( { ...ordinary, temporary: 5, permanent: 6 } ) ).toBe(
			true
		);
		expect( canRegain( { ...ordinary, temporary: 6, permanent: 6 } ) ).toBe(
			false
		);
	} );

	it( 'gains past the rating up to the pool maximum, or without limit when it has none', () => {
		const built = { kind: 'accumulates' as const, permanent: 2 };
		expect( canRegain( { ...built, temporary: 7, ceiling: 10 } ) ).toBe(
			true
		);
		expect( canRegain( { ...built, temporary: 10, ceiling: 10 } ) ).toBe(
			false
		);
		expect( canRegain( { ...built, temporary: 40 } ) ).toBe( true );
		expect( canSpend( { ...built, temporary: 7 } ) ).toBe( true );
		expect( canSpend( { ...built, temporary: 0 } ) ).toBe( false );
	} );

	it( 'offers neither Spend nor Regain on a pool with no temporary points', () => {
		const rating = { kind: 'rating_only' as const, permanent: 7 };
		expect( canSpend( { ...rating, temporary: 4 } ) ).toBe( false );
		expect( canRegain( { ...rating, temporary: 4 } ) ).toBe( false );
	} );

	it( 'names the controls each kind of pool gets', () => {
		expect( temporaryControls( { kind: 'ordinary' } ) ).toBe( 'spend' );
		expect( temporaryControls( { kind: 'accumulates' } ) ).toBe( 'build' );
		expect( temporaryControls( { kind: 'rating_only' } ) ).toBe( 'none' );
	} );

	it( 'spends a permanent point only from a marked pool with a rating left', () => {
		expect(
			canSpendPermanent( { permanent: 3, permanentSpend: true } )
		).toBe( true );
		expect(
			canSpendPermanent( { permanent: 0, permanentSpend: true } )
		).toBe( false );
		expect(
			canSpendPermanent( { permanent: 3, permanentSpend: false } )
		).toBe( false );
	} );
} );
