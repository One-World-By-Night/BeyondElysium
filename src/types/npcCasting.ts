/**
 * Type definitions for NPC casting: a chronicle member loaned "how to play this character tonight" for one session,
 * and the read-only brief they read about it.
 */

/**
 * One casting - a member cast to play an NPC for a session.
 */
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

/**
 * Request body for creating a casting.
 */
export interface CreateNpcCastingRequest {
	session_id: number;
	character_id: number;
	wp_user_id: number;
	brief?: string;
}

/**
 * Request body for updating a casting's cast member and/or brief.
 */
export interface UpdateNpcCastingRequest {
	wp_user_id?: number;
	brief?: string;
}

/**
 * One chronicle member eligible to be cast.
 */
export interface EligibleMember {
	id: number;
	name: string;
	role: string;
}

/**
 * One line of a sheet document: its text, or its text with an indent and, for a rated trait or a pool, the rating a
 * printed sheet draws as empty circles.
 */
export type SheetDocumentRow =
	| string
	| { text: string; indent?: number; circles?: number };

/**
 * One resolved section of a casting brief (or a signed sheet).
 */
export interface SheetDocumentSection {
	block_slug: string;
	section_type: string;
	title: string;
	column: number;
	order: number;
	span: number;
	rows?: SheetDocumentRow[];
	groups?: Array< { label: string | null; rows: SheetDocumentRow[] } >;
}

/**
 * The casting brief document `Sheet_Document::for_casting()` returns.
 */
export interface CastingBriefDocument {
	title: string;
	/**
	 * Label and value pairs; a pool's pair carries its permanent rating third.
	 */
	header: Array< [ string, string ] | [ string, string, number ] >;
	sections: SheetDocumentSection[];
	prose: Array< [ string, string ] >;
	provenance_lines: string[];
}
