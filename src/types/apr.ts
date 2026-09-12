/**
 * Type definitions for a chronicle's Action & Rumor configuration
 * and its background-use ledger. The ledger tracks what a
 * background use spends; the settings decide what a background
 * grants to spend in the first place - see
 * BE_PROCESS/background-ledger-apr-design.md.
 */

/**
 * The full thirteen-knob Action & Rumor configuration for one
 * chronicle: five action-allocation knobs plus eight rumor-
 * generation toggles, always returned together since they share
 * one settings screen.
 */
export interface AprSettings {
	personal_actions: number;
	carry_unused: boolean;
	add_common: boolean;
	background_actions: string[];
	actions_per_level: Record<string, number>;
	public_rumors: boolean;
	personal_rumors: boolean;
	race_rumors: boolean;
	/** Recognized but inert - no character carries group/subgroup data to query against. */
	group_rumors: boolean;
	/** Recognized but inert - no character carries group/subgroup data to query against. */
	subgroup_rumors: boolean;
	influence_rumors: boolean;
	previous_rumors: boolean;
	copy_previous: boolean;
}

/** A partial update to a chronicle's Action & Rumor settings; only the included keys change. */
export type AprSettingsRequest = Partial<AprSettings>;

/**
 * One background or influence name available across a chronicle's
 * creature stacks, for the background_actions picker. An
 * Influence-sourced name is already granted unconditionally on
 * every stack that has it, so choosing it as a background_actions
 * entry is a harmless no-op, not an error.
 */
export interface AprBackgroundOption {
	name: string;
	stacks: string[];
	is_influence: boolean;
}

/**
 * A background a character currently holds, annotated with its
 * live budget when the character's most recent allocation granted
 * it a subaction. budget_total/budget_name are both null when no
 * live allocator subaction exists under this name yet.
 */
export interface SpendableBackground {
	name: string;
	block_slug: string;
	level: number;
	source: string;
	budget_total: number | null;
	budget_name: string | null;
}

/**
 * One recorded background use: a spend against a background's
 * allocator budget, or an unbudgeted use recorded anyway (§4.1).
 * result starts empty and is filled in later by a Storyteller.
 */
export interface BackgroundUse {
	id: number;
	name: string;
	/** Null for a Personal-subaction use, which is not a catalog background. */
	block_slug: string | null;
	level: number;
	cost: number;
	text: string;
	result: string;
	character_id: number;
	recorded_by: number;
	recorded_at: string;
	/** Only present on the response to POST - the plot the use was recorded onto. */
	plot_id?: number;
}

/** Request body for recording a new background use. */
export interface RecordBackgroundUseRequest {
	game_date: string;
	name: string;
	cost?: number;
	text?: string;
}

/** Request body for editing an existing background use. */
export interface UpdateBackgroundUseRequest {
	text?: string;
	result?: string;
	cost?: number;
}
