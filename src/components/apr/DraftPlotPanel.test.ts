/**
 * `splitSelectedCharacters()` splits a flat list of checked-off character ids into the two separate fields the
 * plot-draft route takes, by each character's own `is_npc` rather than by which picker list it came from.
 */
import { splitSelectedCharacters } from './DraftPlotPanel';
import type { Character } from '../../types/character';

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

describe( 'splitSelectedCharacters', () => {
	it( 'sends a checked player character under character_ids', () => {
		const pc = character( { id: 1, is_npc: false } );

		expect( splitSelectedCharacters( [ pc ], [ 1 ] ) ).toEqual( {
			characterIds: [ 1 ],
			npcIds: [],
		} );
	} );

	it( 'sends a checked NPC under npc_ids, never character_ids', () => {
		const npc = character( { id: 2, is_npc: true } );

		expect( splitSelectedCharacters( [ npc ], [ 2 ] ) ).toEqual( {
			characterIds: [],
			npcIds: [ 2 ],
		} );
	} );

	it( "splits a mixed pick list by each row's own is_npc", () => {
		const pc = character( { id: 1, is_npc: false } );
		const npc = character( { id: 2, is_npc: true } );

		expect( splitSelectedCharacters( [ pc, npc ], [ 1, 2 ] ) ).toEqual( {
			characterIds: [ 1 ],
			npcIds: [ 2 ],
		} );
	} );

	it( 'leaves out a character that exists but was never checked', () => {
		const pc = character( { id: 1, is_npc: false } );
		const npc = character( { id: 2, is_npc: true } );

		expect( splitSelectedCharacters( [ pc, npc ], [ 1 ] ) ).toEqual( {
			characterIds: [ 1 ],
			npcIds: [],
		} );
	} );

	it( 'returns both lists empty when nothing is checked', () => {
		expect( splitSelectedCharacters( [ character() ], [] ) ).toEqual( {
			characterIds: [],
			npcIds: [],
		} );
	} );
} );
