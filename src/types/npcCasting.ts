/**
 * Type definitions for NPC casting (1.1.0 §3.8): a chronicle member loaned "how to play this
 * character tonight" for one session, and the read-only brief they read about it.
 */

/** One casting - a member cast to play an NPC for a session. */
export interface NpcCasting {
	id: number;
	game_id: number;
	session_id: number;
	character_id: number;
	wp_user_id: number;
	brief: string | null;
	created_by: number;
	created_at: string;
	updated_at: string;
}

/** Request body for creating a casting. */
export interface CreateNpcCastingRequest {
	session_id: number;
	character_id: number;
	wp_user_id: number;
	brief?: string;
}

/** Request body for updating a casting's cast member and/or brief. */
export interface UpdateNpcCastingRequest {
	wp_user_id?: number;
	brief?: string;
}

/** One chronicle member eligible to be cast - any role, unlike the staff-only assignee picker. */
export interface EligibleMember {
	id: number;
	name: string;
	role: string;
}

/**
 * One resolved section of a casting brief (or a signed sheet) - `Sheet_Document`'s own
 * presentation-neutral shape. `rows`/`groups` depend on the block's `section_type`, so both
 * are optional and a renderer checks which is present.
 */
export interface SheetDocumentSection {
	block_slug: string;
	section_type: string;
	title: string;
	column: number;
	order: number;
	span: number;
	rows?: string[];
	groups?: Array< { label: string | null; rows: string[] } >;
}

/**
 * The casting brief document `Sheet_Document::for_casting()` returns: the NPC's name, the
 * session's date/time/place, its resolved sections (Storyteller-only blocks removed except
 * `npc-roleplaying-notes`), and the casting's own brief text - never XP, status, change
 * history, connections, secrets, or the real player.
 */
export interface CastingBriefDocument {
	title: string;
	header: Array< [ string, string ] >;
	sections: SheetDocumentSection[];
	prose: Array< [ string, string ] >;
	provenance_lines: string[];
}
