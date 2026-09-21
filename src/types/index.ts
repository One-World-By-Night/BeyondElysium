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
}

/**
 * The full definition of a trait_list block: its catalog of
 * selectable items plus the rules governing how many may be
 * chosen, how they are displayed, and how they are grouped.
 */
export interface TraitListDefinition {
	items: TraitListItem[];
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
	levels: PowerLevel[];
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
}

/**
 * The full definition of a tiered_power block: its catalog of
 * powers plus the rules governing out-of-type cost, whether
 * levels must be taken in sequence, and approval.
 */
export interface TieredPowerDefinition {
	powers: TieredPower[];
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
