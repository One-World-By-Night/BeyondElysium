import { describe, expect, it } from 'vitest';
import { groupProfiles } from './profileGrouping';
import type { CharacterProfile } from '../types/character';

function profile( overrides: Partial< CharacterProfile > ): CharacterProfile {
	return {
		id: 1,
		kind: 'npc',
		name: 'Someone',
		public_description: '',
		image_url: null,
		titles: [],
		factions: [],
		played_by: null,
		portrait_attachment_id: null,
		...overrides,
	};
}

describe( 'groupProfiles', () => {
	it( 'separates player characters from NPCs', () => {
		const pc = profile( { id: 1, kind: 'pc', name: 'A Character' } );
		const npc = profile( { id: 2, kind: 'npc', name: 'An NPC' } );

		const grouped = groupProfiles( [ pc, npc ] );

		expect( grouped.characters ).toEqual( [ pc ] );
		expect( grouped.npcs ).toEqual( [ npc ] );
	} );

	it( 'returns empty arrays for an empty list', () => {
		expect( groupProfiles( [] ) ).toEqual( { characters: [], npcs: [] } );
	} );

	it( 'keeps every entry of a kind in its own group, in order', () => {
		const a = profile( { id: 1, kind: 'pc', name: 'A' } );
		const b = profile( { id: 2, kind: 'pc', name: 'B' } );
		const c = profile( { id: 3, kind: 'npc', name: 'C' } );

		const grouped = groupProfiles( [ a, c, b ] );

		expect( grouped.characters ).toEqual( [ a, b ] );
		expect( grouped.npcs ).toEqual( [ c ] );
	} );
} );
