/**
 * `computeBaseVisibleIds()` picks one focus character and one step out, or the whole chronicle.
 * `applyChartFilters()` narrows that set by faction and creature type. `layoutNodes()` places the result on a
 * fixed circle, clustered into contiguous faction arcs.
 */
import {
	applyChartFilters,
	computeBaseVisibleIds,
	layoutNodes,
} from './RelationshipChart';
import type { Character } from '../../types/character';
import type { Connection } from '../../types/plot';

function character( overrides: Partial< Character > = {} ): Character {
	return {
		id: 1,
		uuid: 'uuid-1',
		name: 'Test Character',
		stack_slug: 'vampire',
		owner_type: 'game',
		owner_slug: 'test-chronicle',
		wp_user_id: 1,
		player_name: 'Test Player',
		status: 'active',
		is_npc: false,
		npc_detail: 'full',
		assigned_to: null,
		narrator: null,
		start_date: null,
		xp_earned: 0,
		xp_unspent: 0,
		biography: null,
		notes: null,
		sheet_data: {},
		created_by: 1,
		created_at: '2026-10-01 00:00:00',
		updated_at: '2026-10-01 00:00:00',
		...overrides,
	};
}

function connection( overrides: Partial< Connection > = {} ): Connection {
	return {
		id: 1,
		game_id: 1,
		source_type: 'character',
		source_id: 1,
		target_type: 'character',
		target_id: 2,
		label: null,
		notes: null,
		created_by: 1,
		created_at: '2026-10-01 00:00:00',
		...overrides,
	};
}

describe( 'computeBaseVisibleIds', () => {
	it( 'returns the focus character and whoever they are directly connected to', () => {
		const connections = [
			connection( { id: 1, source_id: 1, target_id: 2 } ),
			connection( { id: 2, source_id: 3, target_id: 1 } ),
		];

		const ids = computeBaseVisibleIds(
			false,
			new Set( [ 1, 2, 3 ] ),
			1,
			connections
		);

		expect( ids ).toEqual( new Set( [ 1, 2, 3 ] ) );
	} );

	it( 'leaves out a character who is not directly connected to the focus', () => {
		const connections = [
			connection( { id: 1, source_id: 2, target_id: 3 } ),
		];

		const ids = computeBaseVisibleIds(
			false,
			new Set( [ 1, 2, 3 ] ),
			1,
			connections
		);

		expect( ids ).toEqual( new Set( [ 1 ] ) );
	} );

	it( 'finds the focus character in rows whose ids arrive as text', () => {
		const connections = [
			connection( {
				id: 1,
				source_id: '1' as unknown as number,
				target_id: '2' as unknown as number,
			} ),
			connection( {
				id: 2,
				source_id: '3' as unknown as number,
				target_id: '1' as unknown as number,
			} ),
		];

		const ids = computeBaseVisibleIds(
			false,
			new Set( [ 1, 2, 3 ] ),
			1,
			connections
		);

		expect( ids ).toEqual( new Set( [ 1, 2, 3 ] ) );
	} );

	it( 'returns an empty set when there is no focus character yet', () => {
		expect(
			computeBaseVisibleIds( false, new Set( [ 1, 2 ] ), null, [] )
		).toEqual( new Set() );
	} );

	it( 'returns every connected character in whole-chronicle mode, ignoring the focus', () => {
		const ids = computeBaseVisibleIds(
			true,
			new Set( [ 1, 2, 3 ] ),
			1,
			[]
		);

		expect( ids ).toEqual( new Set( [ 1, 2, 3 ] ) );
	} );
} );

describe( 'applyChartFilters', () => {
	const byId: Record< number, Character > = {
		1: character( { id: 1, stack_slug: 'vampire' } ),
		2: character( { id: 2, stack_slug: 'werewolf' } ),
	};
	const membership: Record< number, number > = { 1: 10 };

	it( 'passes everyone through when no filter is set', () => {
		expect(
			applyChartFilters( new Set( [ 1, 2 ] ), byId, membership, '', '' )
		).toEqual( new Set( [ 1, 2 ] ) );
	} );

	it( 'keeps only characters in the chosen faction', () => {
		expect(
			applyChartFilters( new Set( [ 1, 2 ] ), byId, membership, '10', '' )
		).toEqual( new Set( [ 1 ] ) );
	} );

	it( 'keeps only characters of the chosen creature type', () => {
		expect(
			applyChartFilters(
				new Set( [ 1, 2 ] ),
				byId,
				membership,
				'',
				'werewolf'
			)
		).toEqual( new Set( [ 2 ] ) );
	} );

	it( 'drops an id with no matching character at all', () => {
		expect(
			applyChartFilters( new Set( [ 1, 99 ] ), byId, membership, '', '' )
		).toEqual( new Set( [ 1 ] ) );
	} );
} );

describe( 'layoutNodes', () => {
	it( 'places every visible character on the circle, none at the same point', () => {
		const byId: Record< number, Character > = {
			1: character( { id: 1, name: 'Alice' } ),
			2: character( { id: 2, name: 'Bob' } ),
			3: character( { id: 3, name: 'Carol' } ),
		};

		const nodes = layoutNodes( new Set( [ 1, 2, 3 ] ), byId, {} );

		expect( nodes ).toHaveLength( 3 );
		const points = new Set( nodes.map( ( n ) => `${ n.x },${ n.y }` ) );
		expect( points.size ).toBe( 3 );
	} );

	it( 'clusters same-faction characters into a contiguous run', () => {
		const byId: Record< number, Character > = {
			1: character( { id: 1, name: 'Alice' } ),
			2: character( { id: 2, name: 'Bob' } ),
			3: character( { id: 3, name: 'Carol' } ),
		};
		const membership: Record< number, number > = { 1: 10, 3: 10 };

		const nodes = layoutNodes( new Set( [ 1, 2, 3 ] ), byId, membership );

		expect( nodes.map( ( n ) => n.id ) ).toEqual( [ 1, 3, 2 ] );
	} );

	it( 'sorts characters with no faction after every factioned one, by name', () => {
		const byId: Record< number, Character > = {
			1: character( { id: 1, name: 'Zed' } ),
			2: character( { id: 2, name: 'Amy' } ),
		};
		const membership: Record< number, number > = { 2: 5 };

		const nodes = layoutNodes( new Set( [ 1, 2 ] ), byId, membership );

		expect( nodes.map( ( n ) => n.id ) ).toEqual( [ 2, 1 ] );
	} );

	it( "carries each node's own faction id through for coloring", () => {
		const byId: Record< number, Character > = {
			1: character( { id: 1 } ),
		};

		const nodes = layoutNodes( new Set( [ 1 ] ), byId, { 1: 7 } );

		expect( nodes[ 0 ].factionId ).toBe( 7 );
	} );

	it( 'returns an empty list for an empty visible set', () => {
		expect( layoutNodes( new Set(), {}, {} ) ).toEqual( [] );
	} );
} );
