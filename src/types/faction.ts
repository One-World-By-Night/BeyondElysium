/**
 * Type definitions for chronicle factions and their court positions.
 */
import type { AudienceRules, AudienceValue } from './plot';

/**
 * `faction_type` is free text server-side (Faction::FACTION_TYPES is a picker suggestion list, not an enforced enum).
 */
export type FactionType = string;

/**
 * The picker's suggested vocabulary.
 */
export const FACTION_TYPE_SUGGESTIONS = [
	'sect',
	'clan',
	'coterie',
	'pack',
	'chantry',
	'court',
	'cabal',
	'sept',
	'motley',
	'house',
	'other',
] as const;

/**
 * The narrower subset a player's own faction proposal may create.
 */
export const PLAYER_PROPOSABLE_FACTION_TYPES = [
	'coterie',
	'pack',
	'cabal',
	'motley',
	'other',
] as const;

export type FactionStatus = 'active' | 'disbanded';

/**
 * A sect, coterie, pack, chantry, court, or other in-fiction grouping.
 */
export interface Faction {
	id: number;
	game_id: number;
	parent_id: number | null;
	name: string;
	faction_type: FactionType;
	description: string | null;
	/**
	 * Present only for a manager, or for a member of this faction.
	 */
	goals?: string | null;
	status: FactionStatus;
	created_via_proposal: boolean;
	audience: AudienceValue;
	/**
	 * Present only for a manager.
	 */
	audience_rules?: AudienceRules | null;
	/**
	 * Whether one of the caller's own characters belongs to this faction.
	 */
	is_member: boolean;
	created_at: string;
	updated_at: string;
}

/**
 * Request body for creating or updating a faction directly (a Storyteller's own action).
 */
export interface FactionRequest {
	name?: string;
	faction_type?: FactionType;
	parent_id?: number | null;
	description?: string | null;
	goals?: string | null;
	status?: FactionStatus;
	audience?: AudienceValue;
	audience_rules?: AudienceRules | null;
}

/**
 * One row of a faction's own member roster (`GET /factions/{id}/members`).
 */
export interface FactionMember {
	id: number;
	character_id: number;
	character_name: string | null;
	rank: string | null;
	is_leader: boolean;
	created_at: string;
}

/**
 * A candidate for `POST /factions/{id}/members`.
 */
export interface FactionMemberCandidate {
	id: number;
	name: string;
}

/**
 * A chronicle office - free-text title, optionally under a faction.
 */
export interface Position {
	id: number;
	game_id: number;
	faction_id: number | null;
	title: string;
	since: string | null;
	audience: AudienceValue;
	/**
	 * Whether anyone currently holds this position.
	 */
	held: boolean;
	character_id: number | null;
	character_name: string | null;
	/**
	 * Present only for a manager.
	 */
	holder_public?: boolean;
	audience_rules?: AudienceRules | null;
	notes?: string | null;
	created_at: string;
	updated_at: string;
}

/**
 * Request body for creating or updating a position.
 */
export interface PositionRequest {
	title?: string;
	faction_id?: number | null;
	character_id?: number | null;
	holder_public?: boolean;
	audience?: AudienceValue;
	audience_rules?: AudienceRules | null;
	notes?: string | null;
}

/**
 * One row of a position's own holder history (`GET /positions/{id}/history`).
 */
export interface PositionHistoryRow {
	id: number;
	character_id: number | null;
	character_name: string | null;
	started: string;
	ended: string | null;
}

/**
 * The title preset groups (`GET /position-presets`).
 */
export type PositionPresets = Record< string, string[] >;
