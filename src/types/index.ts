/**
 * Shared domain types for games (chronicles), their authorization
 * and membership, schema blocks and the section-type definitions
 * they hold, creature stacks, sheet templates, and the pagination
 * and collection-query shapes used across the REST API.
 */
import type { DisplayType } from '../lib/displayTrait';
import type { ActivityChange } from './character';

// ---------------------------------------------------------------------------
// Shared primitives
// ---------------------------------------------------------------------------

/**
 * The level of review a proposed change requires before it takes
 * effect: applied immediately, or reviewed by a Storyteller. (A
 * separate coordinator level was removed - 1.0.0-review F-043.)
 */
export type ApprovalLevel = 'auto' | 'st';

/**
 * A block-level approval rule set. default applies in the general
 * case; in_type and out_of_type only matter for a tiered_power
 * block, where a power's required approval can differ depending
 * on whether it falls within the character's own type.
 */
export interface ApprovalRules {
	default?: ApprovalLevel;
	in_type?: ApprovalLevel;
	out_of_type?: ApprovalLevel;
}

/**
 * A single prerequisite that must already be held before a trait
 * or power can be taken. Points at another block's power by name
 * and the minimum level required in it.
 */
export interface Prerequisite {
	block_slug: string;
	power: string;
	min_level: number;
}

// ---------------------------------------------------------------------------
// Game
// ---------------------------------------------------------------------------

/**
 * A single chronicle/game record. Holds its identity, the
 * free-form settings blob used to configure its behavior, and the
 * authorization and notification switches that apply across the
 * whole chronicle.
 */
export interface Game {
	id: number;
	name: string;
	slug: string;
	game_type: string;
	description: string;
	settings: Record< string, unknown > | null;
	/** Access-control role path prefix associated with this chronicle. */
	asc_role_path: string | null;
	/** Per-chronicle switch for the change approved/rejected notification email. */
	notifications_enabled: boolean;
	created_by: number;
	created_at: string;
	updated_at: string;
	/** Present only on the response to a slug-changing update - counts of what the rename cascaded to. */
	rename_report?: GameRenameReport;
}

/**
 * Counts of every dependent record a slug rename (Game::rename()) moved to
 * follow the new slug: characters, schema-block forks, verification codes,
 * and this site's side of transfers by direct ownership, pages and
 * Elementor widgets by rewriting the slug embedded in their config.
 */
export interface GameRenameReport {
	characters: number;
	schema_blocks: number;
	attestations: number;
	transfers: number;
	pages: number;
	elementor: number;
}

/**
 * Everything stored under a chronicle that deleting it deletes too, from
 * `GET /games/{slug}/content` (and a refused delete's `data.counts`).
 */
export interface ChronicleContentCounts {
	characters: number;
	plots: number;
	world_objects: number;
	templates: number;
	schema_blocks: number;
	saved_queries: number;
	attestations: number;
	transfers: number;
	/** D1 (1.2.5-design-workflow.md §D): real content `delete_with_content()` used to silently leave behind. */
	factions: number;
	positions: number;
	secrets: number;
	game_sessions: number;
	attendance: number;
	release_batches: number;
	notification_queue: number;
	npc_castings: number;
	after_game_reports: number;
}

/**
 * One chronicle the current user actually holds a real membership row
 * in, from `GET /my/games` - the data source for an in-page chronicle
 * switcher, never the full `games` collection (which lists every
 * chronicle on the install to any logged-in user).
 */
export interface MyGame {
	slug: string;
	name: string;
	role: string;
}

/**
 * What the current user can actually do in one specific chronicle,
 * from `GET /{game_slug}/my/capabilities` - resolved per chronicle
 * through `Authorization::check_request()`, distinct from the
 * site-wide, chronicle-blind snapshot `window.beyondElysium.capabilities`
 * carries on every page load.
 */
export interface MyCapabilities {
	be_manage_characters: boolean;
	be_manage_plots: boolean;
	be_manage_schemas: boolean;
	be_manage_connections: boolean;
	be_manage_boons: boolean;
	be_manage_world_objects: boolean;
	be_manage_sessions: boolean;
	be_manage_apr: boolean;
	be_manage_factions: boolean;
}

/**
 * Request body for creating a new game/chronicle. Only name is
 * required; slug, type, description, and settings are optional
 * and take server-side defaults when omitted.
 */
export interface CreateGameRequest {
	name: string;
	slug?: string;
	game_type?: string;
	description?: string;
	settings?: Record< string, unknown >;
}

/**
 * Request body for updating an existing game/chronicle. Every
 * field is optional, and only the fields included in the request
 * are changed.
 */
export interface UpdateGameRequest {
	name?: string;
	slug?: string;
	game_type?: string;
	description?: string;
	settings?: Record< string, unknown >;
	asc_role_path?: string;
	notifications_enabled?: boolean;
}

/**
 * Request body for the narrower /chronicle-setup route (1.0.0-checklist.md item 18) - the
 * three chronicle settings an HST (not an AST, item 27) may save for their own chronicle,
 * gated on be_manage_chronicle_setup rather than the full be_manage_games UpdateGameRequest
 * needs. Every field is optional, same merge-only semantics as UpdateGameRequest.settings.
 */
export interface UpdateChronicleSetupRequest {
	enabled_stacks?: string[];
	enabled_factions?: Record< string, Record< string, string[] > >;
	require_new_character_approval?: boolean;
	/** 1.2.7-design-workflow.md §E2 - '' clears the override (falls through to the site default). */
	accent_color?: string;
}

// ---------------------------------------------------------------------------
// Chronicle-scoped authorization
// ---------------------------------------------------------------------------

/**
 * The role a WordPress user holds within a single chronicle: head
 * storyteller, assistant storyteller, narrator, or player.
 */
export type GameMemberRole = 'hst' | 'ast' | 'narrator' | 'boons' | 'player';

/**
 * One user's membership record within a chronicle, recording
 * which role they hold. name and user_email are enriched
 * server-side for display purposes and are not stored columns
 * themselves.
 */
export interface GameMember {
	id: number;
	game_id: number;
	wp_user_id: number;
	role: GameMemberRole;
	created_at: string;
	/** Enriched server-side for display; not a stored column. */
	name: string | null;
	/** Enriched server-side for display; not a stored column. */
	user_email: string | null;
	/** Whether the member's WordPress account can use their role without accessSchema (1.0.0-review F-104). */
	role_usable: boolean;
}

/**
 * The plugin's current authorization configuration. Reports
 * whether external access-control integration is enabled and
 * whether a supporting client plugin was detected on the site.
 */
export interface AuthorizationSettings {
	asc_enabled: boolean;
	client_detected: boolean;
}

// ---------------------------------------------------------------------------
// Game stats
// ---------------------------------------------------------------------------

/**
 * Aggregate numbers for a chronicle's Storyteller dashboard.
 * Summarizes character counts by stack and by status, the size of
 * the pending-change queue, the active plot count, and a feed of
 * recent change activity.
 */
export interface GameStats {
	characters_by_stack: Record< string, number >;
	characters_by_status: Record< string, number >;
	pending_changes: number;
	active_plots: number;
	recent_activity: ActivityChange[];
	/** Roster health: players with zero `active` characters - none at all, or only a retired/dead/pending one. */
	players_without_active_character: number;
	/** 1.1.0 §3.14 - flagged characters on the spotlight check right now. */
	characters_needing_attention: number;
}

/** One player behind `GameStats.players_without_active_character`'s count. */
export interface PlayerWithoutActiveCharacter {
	wp_user_id: number;
	display_name: string | null;
}

/**
 * One row of the Chronicle Setup checklist (GS-4,
 * guided-chronicle-setup-design.md §6.3). `status` is always derived live -
 * never stored - and `actionable` is `current_user_can()` on the row's own
 * capability, computed server-side.
 */
export interface SetupStatusItem {
	id: string;
	status: 'attention' | 'ok' | 'info';
	title: string;
	detail: string;
	fix: { kind: 'inline' | 'link'; href?: string; capability: string };
	actionable: boolean;
}

export interface SetupStatus {
	items: SetupStatusItem[];
	summary: { attention: number; ok: number; info: number };
}

// ---------------------------------------------------------------------------
// Schema Block — definition types per section_type
// ---------------------------------------------------------------------------

/**
 * The kind of sheet section a schema block defines: a list of
 * discrete traits, a tiered ladder of powers, a numeric resource
 * pool, or a set of free-form identity fields.
 */
export type SectionType =
	| 'trait_list'
	| 'tiered_power'
	| 'resource_pool'
	| 'identity_field';

// --- trait_list ---

/**
 * A single selectable entry within a trait_list block, such as a
 * Discipline, Merit, or Background. Carries its display metadata,
 * its cost, and any prerequisites or approval override that apply
 * to taking it.
 */
/**
 * One row of a per-value approval schedule - "reaching 4 or 5 needs
 * Storyteller approval" - on a trait_list item's count or a resource_pool's
 * permanent value. `from`/`to` are inclusive. Resolved against the
 * RESULTING value only (never a diff against the character's prior value) -
 * the same state-based approach every other approval check in this engine
 * already uses.
 */
export interface ApprovalRange {
	from: number;
	to: number;
	approval: ApprovalLevel;
	/** Citation naming the real-world approval authority, shown to the reviewing Storyteller. */
	reason?: string;
}

/**
 * A site-wide (global-admin-editable, never per-chronicle by default),
 * sanitized-server-side rich-text note on a catalog item, tiered_power
 * level, or tiered_power family - a house rule, a page/document reference,
 * or a general note, kept in their own separate sections rather than run
 * together in one blob. Each present section is HTML: formatting, lists,
 * and tables survive sanitization; images and anything else don't. Never
 * populated by any seeder, never read or written by Grapevine import/export.
 * `source` here is a separate concept from a catalog item's own top-level
 * `source` citation string.
 */
export interface CatalogDescription {
	reference?: string;
	description?: string;
	source?: string;
}

export interface TraitListItem {
	name: string;
	/** Drafted Portuguese (Brazil) translation, display-only - see src/lib/localizeName.ts. Never the value stored, matched, or sent to the server; `name` alone remains canonical. */
	name_pt?: string;
	description?: CatalogDescription;
	source?: string;
	/** Free-text cost expression, not always a plain integer, e.g. "1", "1 or 3", "1-7". */
	cost?: string;
	category?: string;
	approval?: ApprovalLevel;
	/** Citation naming the real-world approval authority, shown to the reviewing Storyteller. */
	reason?: string;
	/** Per-count approval schedule, e.g. Occult 1-3 auto, 4-5 st. Checked before the flat `approval` above; `approval`/`reason` apply only when no range covers the submitted count. */
	approval_by_value?: ApprovalRange[];
	prerequisites?: Prerequisite[];
	/** Grouping label, such as a tribe or breed, used to cluster related items together. */
	group?: string;
	/** Finer-grained grouping within group, such as a breed's own faction. */
	subgroup?: string;
	/** Rank label within the group, such as basic, intermediate, or advanced. */
	tier?: string;
	/**
	 * Whether this one item may be held more than once, each holding labelled by its own
	 * specialization - `Retainers x3 (John Doe)` and `Retainers x2 (Sue Smith)` are two real
	 * purchases, while `Brawl 5 (Wrestling)` is one Brawl however the focus is labelled
	 * (1.2.11 D86). This is what makes the label part of a held row's identity; without it a
	 * row is identified by `name` alone. The item value overrides the block's own
	 * `allow_multiples` default in both directions; leave it unset to take the block default.
	 */
	allow_multiples?: boolean;
}

/**
 * Declared mechanics for a trait_list block that has none of its own - today, a rating
 * derived from another block's rating rather than an intrinsic per-item cost. Mirrors
 * `TieredPowerMeta.untiered` (1.3.2): `mage-rotes` declares
 * `{ derived_from: "mage-spheres", per_level: 1 }` at the same top-level `_meta` key a
 * tiered_power block would, since Rotes are a plain trait_list, not a ladder. Read path
 * only, this release - nothing in `Cost_Engine` consumes it yet (1.4.0's rules engine
 * reads `_meta` directly); this exists so the declared file's own field survives ingestion
 * into `schema_blocks.definition` unmodified rather than being silently dropped by a
 * decoder that only knew about `items`.
 */
export interface TraitListMeta {
	untiered?: {
		/** Flat XP per level, for a track with no intrinsic cost of its own. */
		cost_per_level?: number;
		/** Slug of the block this track's cost is read from, e.g. `mage-spheres` for Rotes. */
		derived_from?: string;
		/** XP per level of the derived-from block's own rating - Rotes are 1 per Sphere level. */
		per_level?: number;
	};
}

/**
 * The full definition of a trait_list block: its catalog of
 * selectable items plus the rules governing how many may be
 * chosen, how they are displayed, and how they are grouped.
 */
export interface TraitListDefinition {
	items: TraitListItem[];
	/** Declared mechanics, when this list's own rating derives from another block's (1.3.2). Absent on every ordinary trait_list. */
	_meta?: TraitListMeta;
	/**
	 * The block-wide default for `TraitListItem.allow_multiples`: whether a held row's identity
	 * is its name plus its specialization label rather than its name alone (1.2.11 D86). An
	 * item that states its own value overrides this, in both directions.
	 */
	allow_multiples?: boolean;
	allow_custom?: boolean;
	has_specializations?: boolean;
	max_per_item?: number;
	approval_rules?: ApprovalRules;
	/** Sort items alphabetically by name. */
	alphabetize?: boolean;
	/** Default display mode; overridden by a template section's own display setting. */
	display?: DisplayType;
	/** Category labels, in display order, when items are grouped rather than flat. */
	categories?: string[];
	negative?: boolean;
	/** Whether re-adding a held trait appends a new entry instead of incrementing the existing one's count. */
	atomic?: boolean;
	/** The stored count is a flat XP cost, not a rating - seeded on vampire-combo-disciplines (1.1.0 D3). */
	count_is_cost?: boolean;
	/** Held entries display and reorder in sheet_data array order, never alphabetized or grouped - a flag, not a slug check, so any block could opt in (1.1.0 D4). */
	player_order?: boolean;
}

// --- tiered_power ---

/**
 * A single rung on a tiered power's ladder, such as one level of
 * a Discipline or a single named Elder-and-above power kept in an
 * unordered pool.
 */
export interface PowerLevel {
	/** Numeric rank on the ordered 1-5 ladder; null for an unordered power matched by power_name and tier instead. */
	level: number | null;
	tier:
		| 'innate'
		| 'basic'
		| 'intermediate'
		| 'advanced'
		| 'elder'
		| 'master'
		| 'ascended'
		| 'methuselah'
		| string;
	power_name: string;
	/**
	 * The source menu's own free-text label for this level, which `tier` was derived from
	 * (`Seeder::normalize_tier()`) - "basic", "Basic (Sabbat)", "int. ritual", "adv., wyld
	 * west". It has always been written; nothing read it until 1.2.9 U5, which uses the
	 * half beyond the tier word to name the seam in a concatenated family (D67). See
	 * src/lib/levelQualifier.ts.
	 */
	note?: string;
	/** Drafted Portuguese (Brazil) translation of power_name, display-only - see src/lib/localizeName.ts. */
	power_name_pt?: string;
	description?: CatalogDescription;
	/** Free-text cost expression, in the same shape as TraitListItem's own cost field. */
	cost?: string;
	/** Approval override for reaching this specific level - each level is already its own catalog row, so no range is needed the way a bare-number trait_list item or resource_pool needs one. Combines (strictest wins) with the power family's own `approval_override`, never replaces it. */
	approval?: ApprovalLevel;
	/** Citation naming the real-world approval authority, shown to the reviewing Storyteller. */
	reason?: string;
}

/**
 * A single named power, such as one Discipline, and the ladder of
 * levels a character can take within it, along with an optional
 * approval override for the power as a whole.
 */
export interface TieredPower {
	name: string;
	source?: string;
	/**
	 * The numbered ladder, and **only** the ladder (1.2.10 §A/S2). One entry per rung, in
	 * rung order, as many entries as `_meta.ladder` sums to.
	 *
	 * This is the D68 fix. The stepper reads `levels`; the pick list reads `elder`. They
	 * cannot be confused because they are not the same array - no `kind` flag, no
	 * tie-counting, no rule at all. Under the old flat shape `level: null` meant both "dots
	 * 1 and 2" and "six elder powers" in one array, so the stepper read it as a ladder and
	 * the checklist read it as a pool, and each was right about half the data. Switching
	 * views was then read as levels removed, and the engine offered a refund for XP never
	 * spent.
	 */
	levels: PowerLevel[];
	/**
	 * Above-ladder picks, keyed by **rank** - `elder`, `master`, `ascended`, `methuselah`.
	 *
	 * `elder` is the *section*; the keys inside it are ranks, so `Animalism.elder.master` is
	 * unambiguous. **A consumer must never flatten those two depths**: merging them recreates
	 * exactly the ambiguity that caused D68.
	 *
	 * Picks are non-sequential in both dimensions. Holding two Master powers and no Elder
	 * ones is legal and must never be flagged, back-filled or reordered.
	 */
	elder?: Record< string, PowerLevel[] >;
	/**
	 * Ladder-rank levels that do not fit the declared ladder (§A1b) - 262 of them across 69
	 * families on the first seeder run, `Animalism` carrying 12 and `Protean` 13.
	 *
	 * They are not `elder`, because their rank is basic/intermediate/advanced; and they are
	 * not rungs, because the ladder is full. **Never offered by the stepper and never counted
	 * in a rating**; rendered as held content so a player can see the family is merged.
	 *
	 * **D67 empties this.** A family whose two ladders are split correctly has none, so a
	 * non-empty `overflow` is precisely the signal that this family still needs its human
	 * ruling - which makes the container 1.3.1's worklist rather than a dumping ground.
	 * Validation rejects an above-ladder rank here: that would be a seeder bug, not data.
	 */
	overflow?: PowerLevel[];
	approval_override?: ApprovalLevel;
	description?: CatalogDescription;
	/**
	 * Blood magic only (`TieredPowerDefinition.blood_magic`): which of the
	 * block's traditions offer this specific path, keyed by tradition name,
	 * valued by that tradition's own alternate name for it or `null` when it
	 * has none. Restricts a Tradition picker to real options rather than the
	 * block's full tradition list - not every tradition offers every path.
	 */
	traditions?: Record< string, string | null >;
	/** Blood magic only: a caste/covenant restriction on who may take this path (e.g. "Sabbat", "Warrior Only"), not an alternate name. */
	restriction?: string | null;
	/**
	 * Which category value this family is filed under, keyed by axis (1.2.10 S4) - every
	 * key must be one of `_meta.categories`. A Werewolf Gift family is
	 * `{ tribe: "Bone Gnawer" }`; a Fera one needs both levels,
	 * `{ species: "Bastet", subgroup: "Bagheera" }`.
	 *
	 * **This is the thing BE keeps nowhere today.** Measured 2026-09-21: all 510
	 * `werewolf-gifts` items carry `group: "Bone Gnawer"` and all 865 `fera-gifts` carry a
	 * species plus (639 of them) a subgroup - but **not one item records that "Bone Gnawer"
	 * *is* a tribe**, so the axis has to be rediscovered by joining each value against every
	 * `*-identity` block's own option lists (1.2.9 U4 does exactly that). Declaring it makes
	 * the axis data rather than inference, and is what lets a pick list cross group against
	 * rank without guessing which of the 29 values belongs to which of the three axes.
	 *
	 * A map rather than §A2's nested `breed`/`tribe`/`auspice` containers: the containers
	 * would be dynamic top-level keys on the definition, which no consumer could type, and
	 * a family's own `name` already carries the value. Same information, one field.
	 *
	 * **The expression only, this release.** Gifts are `trait_list` today and the values
	 * arrive when the declared JSON files are authored (1.3.0/1.3.1) and ingested as
	 * tiered_power blocks (1.3.2) - see that document's own cutover note. Nothing in 1.2.10
	 * populates or reads this.
	 */
	category_values?: Record< string, string >;
}

/**
 * The full definition of a tiered_power block: its catalog of
 * powers plus the rules governing out-of-type cost, whether
 * levels must be taken in sequence, and approval.
 */
/**
 * What a `tiered_power` block declares about its own mechanics (1.2.10 §A).
 *
 * The point of this block is that **nothing downstream infers structure any more**. Four
 * separate ceiling-derivation rules were written against the old flat `levels[]` array and
 * all four failed, because three blocks encoded "which level is a rung" three incompatible
 * ways and the boundary was destroyed before the array existed. A declared file carries
 * what no rule could recover.
 */
export interface TieredPowerMeta {
	/**
	 * This block's own rank vocabulary, lowest first. Per block, because genre vocabularies
	 * genuinely differ: Wraith declares `innate` ahead of `basic`, and Kuei-Jin uses the
	 * same three words as Vampire at different prices. One global table cannot express
	 * either, which is why `Seeder::TIER_RANKS` stops being a ranking authority.
	 */
	ranks: string[];
	/**
	 * How many numbered rungs each rank contributes to the ladder. **The ceiling is this
	 * sum**, never an inference - `{ basic: 2, intermediate: 2, advanced: 1 }` is 5.
	 *
	 * Every track measured so far is 2/2/1. A block declaring anything else should be
	 * suspected before it is believed: an earlier draft had Wraith at 2/1/1 = 4, and the
	 * OWBN Arcanoi packet showed that was a data defect, not a genre difference.
	 */
	ladder: Record< string, number >;
	/** XP per rank. Keyed by rank name, covering ladder ranks and above-ladder ranks alike. */
	costs: Record< string, number >;
	/**
	 * The out-of-type modifier **per rank**, as an expression rather than a number - which
	 * is what absorbs the two cases a scalar broke on. Mage Spheres scale (`+1`, `+2`, `+3`
	 * as 4/8/12 becomes 5/10/15) and Demon Form Powers double (`×2`), and Wraith's "−1
	 * except Innate" stops needing an exemption flag because Innate is simply `+0`.
	 *
	 * Absent means no modifier at all, which is Kuei-Jin: its chart carries none, and the
	 * owner ruled 2026-09-21 that OWBN's 4/7/10 overrides any book cost.
	 */
	out_of_type?: Record< string, string >;
	/**
	 * Where the in-type test reads the character's own value from, as `block-slug.Field`.
	 * **D75: only the vampire stack has ever declared this**, so every other genre's
	 * surcharge has been silently inert - `Cost_Engine::in_type_check()` returns
	 * always-in-type the moment it is absent.
	 */
	in_type_source?: string;
	/** Category axes a family is filed under - Gifts by breed/auspice/tribe, Fera by species. */
	categories?: string[];
	/**
	 * Rank → the level number that rank sits at, for a genre whose ranks do **not** occupy
	 * consecutive positions (1.2.10 S5). Gifts are `{ basic: 1, intermediate: 3, advanced: 5 }`
	 * - LotW Revised puts them at levels 1, 3 and 5, never 1, 2, 3. Absent means consecutive,
	 * which is every other genre measured.
	 *
	 * **Not to be confused with `TieredPower.levels`**, which is the ladder array. This is a
	 * rank→number map on the block; that is a list of rungs on a family. The format doc names
	 * both `levels` (`reference/CATALOG-JSON-FORMAT.md` §`_meta`) and the files are authored
	 * against it, so the name matches the spec rather than avoiding the collision.
	 *
	 * The expression only, this release; the values arrive with the declared files (1.3.0/1.3.1).
	 */
	levels?: Record< string, number >;
	/**
	 * Declares a track that has **no rank vocabulary at all** (1.2.10 S7) - so it stops
	 * depending on `make_tiered_power_block()`'s no-tier-vocabulary fallback, which reaches
	 * the right answer for the wrong reason and explains itself to nobody.
	 *
	 * Two shapes, per `reference/CATALOG-JSON-FORMAT.md`: a flat per-level cost
	 * (`{ cost_per_level: 2 }`, Changeling Realms) or a cost derived from another block
	 * (`{ derived_from: "mage-spheres", per_level: 1 }`, Mage Rotes - one XP per Sphere level
	 * invoked). Absent means the block is ranked normally.
	 *
	 * Owner ruling 2026-09-21: untiered tracks are declared explicitly **and must be
	 * UI-adjustable** - the input is E7, and no field in this design ships without one. The
	 * expression only, this release; the values are 1.3.0's.
	 */
	untiered?: {
		/** Flat XP per level, for a track with no ranks - Changeling Realms are 2. */
		cost_per_level?: number;
		/** Slug of the block this track's cost is read from, e.g. `mage-spheres` for Rotes. */
		derived_from?: string;
		/** XP per level of the derived-from block's own rating - Rotes are 1 per Sphere level. */
		per_level?: number;
	};
}

export interface TieredPowerDefinition {
	powers: TieredPower[];
	/** Declared mechanics (§A). Absent on a block the seeder has not yet re-emitted. */
	_meta?: TieredPowerMeta;
	/**
	 * @deprecated Superseded by `_meta.out_of_type`, which is keyed per rank and holds an
	 * expression. A single number cannot express Mage's scaling or Demon's doubling, and
	 * every block carrying this declared the same inert `1` (D75). Read only as a fallback
	 * while blocks that predate `_meta` are still seeded.
	 */
	out_of_type_cost_modifier?: number;
	sequential?: boolean;
	approval_rules?: ApprovalRules;
	/** Whether a homebrew power outside the seeded catalog may still be added. */
	allow_custom?: boolean;
	/** Flags this block as Blood Magic: taking a power prompts for a Tradition, stored per held pick rather than baked into the catalog name. */
	blood_magic?: boolean;
	/** The real traditions this block offers, for the Tradition picker - only meaningful when `blood_magic` is true. */
	traditions?: string[];
	/** Held entries display and reorder in sheet_data array order - a flag, not a slug check, so any block could opt in (1.1.0 D4). */
	player_order?: boolean;
}

// --- resource_pool ---

/**
 * A pointer to another block's field or pool value on the same
 * character's sheet, resolved at render time. Deliberately
 * generic: the resolver that reads this has no built-in knowledge
 * of what block_slug or field mean for any particular creature
 * type.
 */
export interface CrossBlockRef {
	block_slug: string;
	field: string;
}

/**
 * A single numeric resource pool, such as Blood Pool or Willpower,
 * including its starting value, its bounds, and an optional rule
 * for overriding its displayed name based on another block's
 * value.
 */
export interface ResourcePool {
	name: string;
	value_type: 'integer' | 'decimal';
	step?: number;
	default_start: number;
	max?: number;
	min?: number;
	max_lookup?: string;
	/** Overrides this pool's display name by looking up another block's value in table; falls back to name when unresolvable. */
	name_lookup?: {
		keyed_by: CrossBlockRef;
		table: Record< string, string >;
	};
	/**
	 * XP cost per dot above free_dots (PC-9/PC-10, point-calculator-design.md §4.3).
	 * Absent means "no pricing rule exists" - the pool stays unpriced, never free.
	 */
	cost_per_dot?: number;
	/** Dots granted free before cost_per_dot applies - ported per-race from Grapevine's own point estimator, see Seeder.php's citations. */
	free_dots?: number;
	/**
	 * A cost that scales with the dot being bought, rather than a flat `cost_per_dot`
	 * (1.2.10 S8). `{ equals_level: true }` is Mummy Balance - *"a number of Experience
	 * Traits equal to the level desired"* (Laws of the Resurrection), the only
	 * per-level-scaling pool in any genre, and something `cost_per_dot` structurally cannot
	 * express. Absent means the flat rate applies.
	 *
	 * Owner ruling 2026-09-21: give it a real rule rather than logging it. The expression
	 * only, this release; the value is 1.3.0's, and its input is E7.
	 *
	 * Deliberately does **not** cover Wraith's Pathos, which the book prices at *2 Traits per
	 * 1 XP* - a sub-1-XP rate that `cost_per_dot`'s integer cannot hold either. A separate
	 * gap, recorded rather than folded in here.
	 */
	sliding_cost?: {
		/** The dot costs its own number: the 4th dot costs 4. */
		equals_level?: boolean;
	};
	/** Per-value approval schedule, keyed on the pool's own PERMANENT value (never temporary - spending/regaining a point of Willpower in play never needs approval; permanently raising it via XP might). */
	approval_by_value?: ApprovalRange[];
	/** Drafted Portuguese translation of this pool's own name, display-only (1.2.0 §5.6). */
	label_pt?: string;
}

/**
 * The full definition of a resource_pool block: the list of
 * numeric pools it exposes.
 */
export interface ResourcePoolDefinition {
	pools: ResourcePool[];
}

// --- identity_field ---

/**
 * A single free-form identity field, such as Clan, Nature, or
 * Concept. Describes its input type, whether it is required, and
 * where its selectable options, if any, come from.
 */
export interface IdentityField {
	name: string;
	/** multiselect allows choosing more than one value, up to max_selections. */
	field_type: 'text' | 'select' | 'number' | 'textarea' | 'multiselect';
	required: boolean;
	options?: string[];
	options_ref?: string;
	min?: number;
	max?: number;
	/** multiselect only: how many values may be chosen. */
	max_selections?: number;
	default?: string | number;
	/** select only: allows a free-text value alongside the fixed option list. */
	allow_custom?: boolean;
	/** Per-option approval schedule, e.g. picking "Antediluvian" needs Storyteller approval while every other option is auto. Keyed by the exact option string; a multiselect's every selected value is checked, strictest wins. */
	approval_by_option?: Record<
		string,
		{ approval: ApprovalLevel; reason?: string }
	>;
	/** Drafted Portuguese translation of this field's own name, display-only (1.2.0 §5.6). */
	label_pt?: string;
	/** Drafted Portuguese translation per option, keyed by the option's exact canonical string - additive, `options` itself stays untouched (1.2.0 §5.6). */
	options_pt?: Record< string, string >;
}

/**
 * The full definition of an identity_field block: the list of
 * fields it exposes, plus an optional clan-to-discipline lookup
 * table used by vampire character creation.
 */
export interface IdentityFieldDefinition {
	fields: IdentityField[];
	clan_disciplines?: Record< string, string[] >;
}

// --- discriminated union ---

/**
 * The definition shape stored on a schema block, matching one of
 * the four section types. Which member applies is determined by
 * the owning block's own section_type field.
 */
export type BlockDefinition =
	| TraitListDefinition
	| TieredPowerDefinition
	| ResourcePoolDefinition
	| IdentityFieldDefinition;

/**
 * A single reusable schema block, such as "Vampire Disciplines" or
 * "Werewolf Gifts". Combines its identity and version number with
 * a definition whose shape depends on its section_type.
 */
export interface SchemaBlock {
	id: number;
	slug: string;
	/** '' for the global catalog; a chronicle's slug for that chronicle's own block or fork. */
	game_slug?: string;
	name: string;
	section_type: SectionType;
	definition: BlockDefinition;
	is_system: boolean;
	/** Hides the block's section and its stored values from anyone without be_manage_characters. */
	storyteller_only?: boolean;
	version: number;
	created_by: number;
	created_at: string;
	updated_at: string;
}

/**
 * Request body for creating a new schema block. slug, name, and
 * section_type are required to identify and classify the block;
 * definition is optional and may be filled in afterward.
 */
export interface CreateSchemaBlockRequest {
	slug: string;
	name: string;
	section_type: SectionType;
	definition?: BlockDefinition;
	storyteller_only?: boolean;
}

/**
 * Request body for updating an existing schema block. Every field
 * is optional, and only the fields included in the request are
 * changed.
 */
export interface UpdateSchemaBlockRequest {
	name?: string;
	section_type?: SectionType;
	definition?: BlockDefinition;
	storyteller_only?: boolean;
}

// ---------------------------------------------------------------------------
// Creature Stack
// ---------------------------------------------------------------------------

/**
 * A single block's placement within a creature stack's sheet
 * layout: which block, its label, its position, and whether it is
 * required or paired with a negative-trait counterpart block.
 */
export interface StackSection {
	block_slug: string;
	label: string;
	display_order: number;
	required: boolean;
	negative_block_slug?: string;
	in_type_source?: string;
}

/**
 * The full sheet layout for a creature stack: the ordered list of
 * sections it is made of, plus optional display preferences.
 */
export interface StackDefinition {
	sections: StackSection[];
	display_preferences?: Record< string, unknown >;
}

/**
 * A single step in a creature stack's guided character-creation
 * flow, naming which sections it covers and the point budget
 * available for spending on them.
 */
export interface CreationStep {
	step: number;
	label: string;
	sections: string[];
	budget?: { primary: number; secondary: number; tertiary: number };
	budgets?: Record< string, number >;
	prioritize?: boolean;
	free_traits?: number;
}

/**
 * The ordered set of character-creation steps for a creature
 * stack, if it defines a guided creation flow.
 */
export interface CreationRules {
	steps?: CreationStep[];
}

/**
 * A single creature type, such as Vampire or Werewolf. Defines
 * the sheet layout its characters use and, optionally, a guided
 * character-creation flow.
 */
export interface CreatureStack {
	id: number;
	slug: string;
	name: string;
	game_line: string;
	stack_definition: StackDefinition;
	creation_rules: CreationRules;
	is_system: boolean;
	created_by: number;
	created_at: string;
	updated_at: string;
}

/**
 * A creature stack together with the actual SchemaBlock record
 * for every block its sections reference, keyed by block slug, so
 * a sheet can be rendered without a further lookup per section.
 */
export interface ResolvedStack {
	stack: CreatureStack;
	blocks: Record< string, SchemaBlock >;
}

/**
 * Request body for creating a new creature stack. slug, name, and
 * stack_definition are required; game_line and creation_rules are
 * optional.
 */
export interface CreateCreatureStackRequest {
	slug: string;
	name: string;
	game_line?: string;
	stack_definition: StackDefinition;
	creation_rules?: CreationRules;
}

/**
 * Request body for updating an existing creature stack. Every
 * field is optional, and only the fields included in the request
 * are changed.
 */
export interface UpdateCreatureStackRequest {
	name?: string;
	game_line?: string;
	stack_definition?: StackDefinition;
	creation_rules?: CreationRules;
}

// ---------------------------------------------------------------------------
// Template
// ---------------------------------------------------------------------------

/**
 * A single block's placement within a sheet template's layout:
 * its column and order, its display heading, whether it starts
 * collapsed, and how much row width it occupies.
 */
export interface TemplateLayoutSection {
	block_slug: string;
	column: number;
	order: number;
	title: string;
	display: DisplayType | null;
	collapsed: boolean;
	/** How much of a 6-unit row this section spans: third (default), half, or full. */
	width?: 'third' | 'half' | 'full';
	/** Cross-block references appended to title once every reference resolves; falls back to title alone otherwise. */
	title_refs?: CrossBlockRef[];
}

/**
 * A full sheet layout: its column count and the ordered list of
 * block sections placed within it.
 */
export interface TemplateLayout {
	version: 1;
	columns: number;
	sections: TemplateLayoutSection[];
}

/**
 * The layout actually resolved for a given stack and template
 * type, along with the identity of the template row it came from,
 * if any.
 */
export interface ResolvedTemplate {
	/** Null when resolved_from is "generated", since a generated layout has no row of its own. */
	id: number | null;
	name: string | null;
	stack_slug: string;
	template_type: string;
	layout: TemplateLayout;
}

/**
 * Response from resolving a template for a stack. Reports both
 * the resolved layout and which tier it was resolved from: a
 * game-specific override, a global template, or a generated
 * fallback.
 */
export interface TemplateResolveResponse {
	resolved_from: 'game' | 'global' | 'generated';
	template: ResolvedTemplate;
}

/**
 * The raw stored template row, as opposed to the ResolvedTemplate
 * outcome of resolving one. game_id is null for a global template
 * and set for a chronicle-specific one.
 */
export interface Template {
	id: number;
	game_id: number | null;
	stack_slug: string | null;
	name: string;
	template_type: string;
	layout: TemplateLayout;
	is_system: boolean;
	created_by: number;
	created_at: string;
	updated_at: string;
}

/**
 * Request body for creating a new template. name, template_type,
 * and layout are required; stack_slug is optional and, when
 * omitted, creates a template not tied to a specific stack.
 */
export interface CreateTemplateRequest {
	name: string;
	template_type: string;
	layout: TemplateLayout;
	stack_slug?: string;
}

/**
 * Request body for updating an existing template. Every field is
 * optional, and only the fields included in the request are
 * changed.
 */
export interface UpdateTemplateRequest {
	name?: string;
	template_type?: string;
	layout?: TemplateLayout;
	stack_slug?: string;
}

// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------

/**
 * The common pagination and ordering parameters accepted by most
 * collection endpoints: page number, page size, sort field, and
 * sort direction.
 */
export interface CollectionParams {
	page?: number;
	per_page?: number;
	orderby?: string;
	order?: 'ASC' | 'DESC';
}

/**
 * Query parameters for the games collection endpoint. Extends the
 * common collection params with an optional game_type filter.
 */
export interface GameCollectionParams extends CollectionParams {
	game_type?: string;
}

/**
 * Query parameters for the schema blocks collection endpoint.
 * Extends the common collection params with filters for section
 * type, the system-block flag, free-text search, and a chronicle
 * whose own customized blocks should be substituted in.
 */
export interface SchemaBlockCollectionParams extends CollectionParams {
	section_type?: SectionType;
	is_system?: boolean;
	search?: string;
	/** Substitutes this chronicle's own fork of a block in place of the global one, where one exists. */
	game_slug?: string;
}

/**
 * Query parameters for the creature stacks collection endpoint.
 * Extends the common collection params with filters for game
 * line, the system-stack flag, and free-text search.
 */
export interface CreatureStackCollectionParams extends CollectionParams {
	game_line?: string;
	is_system?: boolean;
	search?: string;
	/** Narrows to this chronicle's own settings.enabled_stacks (GS-3); omitted, every stack is offered. */
	game_slug?: string;
}
