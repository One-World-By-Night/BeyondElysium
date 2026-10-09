/**
 * Type definitions for importing Grapevine exchange files.
 */

/**
 * A count of each kind of record found in an import file, used to summarize what an import job contains before it is
 * committed.
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
 * A candidate WordPress account suggested as a match for a player named in the import file, identified by id and
 * display name.
 */
export interface PlayerMatchSuggestion {
	wp_user_id: number;
	display_name: string;
}

/**
 * A player named in the import file who has no confirmed WordPress account yet, along with their source name and
 * email and any candidate accounts found that might match them.
 */
export interface PlayerNeedingMatch {
	gv_name: string;
	gv_email: string;
	suggestions: PlayerMatchSuggestion[];
}

/**
 * A trait found in the import file that only partially matches the current catalog.
 */
export interface FlaggedTrait {
	character: string;
	block: string;
	raw: string;
	suggestions: string[];
	reason: string;
	allow_custom: boolean;
}

/**
 * A trait found in the import file with no catalog match at all.
 */
export interface UnresolvedTrait {
	character: string;
	block: string;
	raw: string;
	reason?: string;
	allow_custom: boolean;
}

/**
 * A parsed character from the import file that is already on this site.
 */
export interface DuplicateCharacter {
	character: string;
	/**
	 * 0 when the match lives in another chronicle.
	 */
	existing_id: number;
	existing_uuid: string;
	matched_by: 'uuid' | 'uuid_elsewhere' | 'name';
	/**
	 * What differs from the sheet already in this chronicle.
	 */
	changes?: SheetChange[];
	/**
	 * Whether Overwrite is a real option here.
	 */
	overwrite_allowed?: boolean;
	/**
	 * Who the existing character belongs to today, for display next to a disallowed Overwrite.
	 */
	existing_owner?: string | null;
}

/**
 * One difference between a sheet already here and the same character arriving.
 */
export interface SheetChange {
	section: string;
	entry: string;
	here: string | null;
	arriving: string | null;
}

/**
 * A parsed item, location, or rote from the import file whose name already matches an existing world object in this
 * game.
 */
export interface DuplicateWorldObject {
	type: 'item' | 'location' | 'rote';
	name: string;
	existing_id: number;
}

/**
 * The full preview of a parsed character/game-data import job, returned both right after upload and on subsequent
 * status checks.
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
	refused: RefusedCharacter[];
	warnings: string[];
	narrative: NarrativeImportPreview;
}

/**
 * A character this import will not write at all: their own creature type has no shipped stack on this site, or
 * isn't enabled in this chronicle.
 */
export interface RefusedCharacter {
	character: string;
	reason: string;
}

/**
 * How to handle one duplicate record found during import: leave the existing record alone, overwrite it with the
 * imported one, or import the new record alongside it.
 */
export type DuplicateAction = 'skip' | 'overwrite' | 'import_as_new';

/**
 * A Storyteller's chosen fix for one flagged or unresolved trait before an import is committed. apply_suggestion
 * accepts one of the trait's own suggested matches.
 */
export interface TraitResolution {
	character: string;
	block: string;
	raw: string;
	action: 'apply_suggestion' | 'keep_custom';
	suggestion_name?: string;
	/**
	 * keep_custom only: also adds this power to the block's catalog.
	 */
	add_to_catalog?: boolean;
}

/**
 * The Storyteller's resolution choices for a parsed import job, submitted together when committing it.
 */
export interface ImportResolutions {
	duplicates?: Record< string, DuplicateAction >;
	/**
	 * Keyed by "{type}:{name}", e.g. "item:Sabbat Pack Ritual Dagger", matching DuplicateWorldObject.
	 */
	world_objects?: Record< string, DuplicateAction >;
	traits?: TraitResolution[];
	/**
	 * Which of a game file's plots, rumors and actions to import, each ticked when absent.
	 */
	import_kinds?: { plots?: boolean; rumors?: boolean; actions?: boolean };
}

/**
 * A single record actually written by a committed import, and which outcome it received: newly created, overwritten
 * in place, or skipped entirely.
 */
export interface ImportedEntity {
	id: number;
	name: string;
	action: 'created' | 'overwritten' | 'skipped';
}

/**
 * Response from committing a parsed import job.
 */
export interface ImportCommitResult {
	items: ImportedEntity[];
	locations: ImportedEntity[];
	rotes: ImportedEntity[];
	characters: ImportedEntity[];
	plots?: number;
	rumors?: number;
	actions?: number;
	skipped_actions?: number;
	skipped_plots?: number;
	skipped_rumors?: number;
	skipped_queries?: number;
	unmatched_cast?: string[];
	unmatched_actors?: string[];
}

// ---------------------------------------------------------------------------
// Game file (.gv3, GVBG) import
// ---------------------------------------------------------------------------

/**
 * A minimal reference to an existing chronicle, offered as a candidate merge target when importing a full game file.
 */
export interface ExistingGame {
	slug: string;
	name: string;
}

/**
 * Counts of record kinds a game file import does not bring over, surfaced to the Storyteller. apr_engine reports
 * whether the source file used the Action/Plot/Rumor engine at all.
 */
export interface GameImportSkipped {
	queries: number;
	xp_awards: number;
	templates: number;
	calendar_entries: number;
	apr_engine: boolean;
}

/**
 * Read-only preview of a file's plots, rumors and actions: how many already exist in the target chronicle (by
 * title and date) and which cast/action names match no character, in the file or the chronicle.
 */
export interface NarrativeImportPreview {
	plots: { already_present: number };
	rumors: { already_present: number };
	actions: { already_present: number };
	unmatched_names: string[];
}

/**
 * The full preview of a parsed game file import job.
 */
export interface GameImportPreview extends ImportPreview {
	chronicle_title: string;
	existing_games: ExistingGame[];
	skipped: GameImportSkipped;
}

/**
 * Where a committed game file import should land: as a brand new chronicle with an optional name override, or merged
 * into an existing chronicle by slug.
 */
export type GameImportTarget =
	| { action: 'create_new'; name?: string }
	| { action: 'merge'; game_slug: string };

/**
 * Response from committing a game file import.
 */
export interface GameImportCommitResult extends ImportCommitResult {
	game: { id: number; slug: string; name: string; created: boolean };
}
