/**
 * Splits a Who's Who profile list into its two display groups.
 */
import type { CharacterProfile } from '../types/character';

export interface GroupedProfiles {
	characters: CharacterProfile[];
	npcs: CharacterProfile[];
}

export function groupProfiles( profiles: CharacterProfile[] ): GroupedProfiles {
	return {
		characters: profiles.filter( ( p ) => p.kind === 'pc' ),
		npcs: profiles.filter( ( p ) => p.kind === 'npc' ),
	};
}
