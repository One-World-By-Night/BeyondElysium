/**
 * Type definitions for importing Grapevine exchange files: a
 * single character/game-data import job's preview and commit
 * shapes, plus the separate full game file (.gv3, GVBG) import
 * job's own preview and commit shapes.
 */

/**
 * A count of each kind of record found in an import file, used to
 * summarize what an import job contains before it is committed.
 */
export interface ImportCounts {
	players: number;
	characters: number;
	queries: number;
	items: number;
	rotes: number;
	locations: number;
	actions: number;
	plots: number;
	rumors: number;
}

/**
 * A candidate WordPress account suggested as a match for a player
 * named in the import file, identified by id and display name.
 */
export interface PlayerMatchSuggestion {
	wp_user_id: number;
	display_name: string;
}

/**
 * A player named in the import file who has no confirmed
 * WordPress account yet, along with their source name and email
 * and any candidate accounts found that might match them.
 */
export interface PlayerNeedingMatch {
	gv_name: string;
	gv_email: string;
	suggestions: PlayerMatchSuggestion[];
}

/**
 * A trait found in the import file that only partially matches
 * the current catalog. Carries the raw source text, the character
 * and block it belongs to, why it was flagged, and any close-match
 * suggestions to resolve it with.
 */
export interface FlaggedTrait {
	character: string;
	block: string;
	raw: string;
	suggestions: string[];
	reason: string;
}

/**
 * A trait found in the import file with no catalog match at all.
 * Carries the raw source text and the character and block it
 * belongs to, plus an optional reason it could not be resolved.
 */
export interface UnresolvedTrait {
	character: string;
	block: string;
	raw: string;
	reason?: string;
}

/**
 * A parsed character from the import file whose name already
 * matches an existing character in this chronicle, matched by
 * name only, never by uuid. Requires the importing Storyteller to
 * choose how to handle the conflict.
 */
export interface DuplicateCharacter {
	character: string;
	existing_id: number;
	existing_uuid: string;
}

/**
 * A parsed item, location, or rote from the import file whose
 * name already matches an existing world object in this game.
 * Uses the same conflict-resolution mechanism as
 * DuplicateCharacter, extended to world objects.
 */
export interface DuplicateWorldObject {
	type: 'item' | 'location' | 'rote';
	name: string;
	existing_id: number;
}

/**
 * The full preview of a parsed character/game-data import job,
 * returned both right after upload and on subsequent status
 * checks. Summarizes what was found, what needs player matching
 * or trait resolution, and what would be skipped as a duplicate.
 */
export interface ImportPreview {
	job_id: string;
	format: string;
	version: number;
	counts: ImportCounts;
	players_needing_match: PlayerNeedingMatch[];
	flagged_traits: FlaggedTrait[];
	unresolved: UnresolvedTrait[];
	duplicates: DuplicateCharacter[];
	world_object_duplicates: DuplicateWorldObject[];
	warnings: string[];
}

/**
 * How to handle one duplicate record found during import: leave
 * the existing record alone, overwrite it with the imported one,
 * or import the new record alongside it.
 */
export type DuplicateAction = 'skip' | 'overwrite' | 'import_as_new';

/**
 * A Storyteller's chosen fix for one flagged or unresolved trait
 * before an import is committed. apply_suggestion accepts one of
 * the trait's own suggested matches; keep_custom keeps the raw
 * imported name as-is instead. Only meaningful for a
 * tiered_power-classified block.
 */
export interface TraitResolution {
	character: string;
	block: string;
	raw: string;
	action: 'apply_suggestion' | 'keep_custom';
	suggestion_name?: string;
	/** keep_custom only: also adds this power to the block's catalog; requires the be_manage_schemas capability. */
	add_to_catalog?: boolean;
}

/**
 * The Storyteller's resolution choices for a parsed import job,
 * submitted together when committing it. Covers how to handle
 * duplicate characters and world objects and how to resolve
 * flagged or unresolved traits.
 */
export interface ImportResolutions {
	duplicates?: Record<string, DuplicateAction>;
	/** Keyed by "{type}:{name}", e.g. "item:Sabbat Pack Ritual Dagger", matching DuplicateWorldObject. */
	world_objects?: Record<string, DuplicateAction>;
	traits?: TraitResolution[];
}

/**
 * A single record actually written by a committed import, and
 * which outcome it received: newly created, overwritten in place,
 * or skipped entirely.
 */
export interface ImportedEntity {
	id: number;
	name: string;
	action: 'created' | 'overwritten' | 'skipped';
}

/**
 * Response from committing a parsed import job. Lists the items,
 * locations, rotes, and characters actually written, plus counts
 * of anything this pass deliberately skipped.
 */
export interface ImportCommitResult {
	items: ImportedEntity[];
	locations: ImportedEntity[];
	rotes: ImportedEntity[];
	characters: ImportedEntity[];
	skipped_actions?: number;
	skipped_plots?: number;
	skipped_rumors?: number;
	skipped_queries?: number;
}

// ---------------------------------------------------------------------------
// Game file (.gv3, GVBG) import
// ---------------------------------------------------------------------------

/**
 * A minimal reference to an existing chronicle, offered as a
 * candidate merge target when importing a full game file.
 */
export interface ExistingGame {
	slug: string;
	name: string;
}

/**
 * Counts of record kinds a game file import deliberately does not
 * bring over, surfaced to the Storyteller rather than silently
 * dropped. apr_engine reports whether the source file used the
 * Action/Plot/Rumor engine at all.
 */
export interface GameImportSkipped {
	queries: number;
	actions: number;
	plots: number;
	rumors: number;
	xp_awards: number;
	templates: number;
	calendar_entries: number;
	apr_engine: boolean;
}

/**
 * The full preview of a parsed game file import job. Extends the
 * character-import preview with the source chronicle's title, a
 * list of existing chronicles it could be merged into, and what
 * this pass skips.
 */
export interface GameImportPreview extends ImportPreview {
	chronicle_title: string;
	existing_games: ExistingGame[];
	skipped: GameImportSkipped;
}

/**
 * Where a committed game file import should land: as a brand new
 * chronicle with an optional name override, or merged into an
 * existing chronicle by slug.
 */
export type GameImportTarget =
	| { action: 'create_new'; name?: string }
	| { action: 'merge'; game_slug: string };

/**
 * Response from committing a game file import. Extends the
 * standard import commit result with the resulting chronicle's
 * identity and whether it was newly created or merged into.
 */
export interface GameImportCommitResult extends ImportCommitResult {
	game: { id: number; slug: string; name: string; created: boolean };
}
