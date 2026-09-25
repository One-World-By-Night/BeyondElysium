/**
 * Shared domain types for games (chronicles), their authorization and membership, schema blocks and the section-type
 * definitions they hold, creature stacks, sheet templates, and the pagination and collection-query shapes used across
 * the REST API.
 */
import type { DisplayType } from '../lib/displayTrait';
import type { ActivityChange } from './character';

// ---------------------------------------------------------------------------
// Shared primitives
// ---------------------------------------------------------------------------

/**
 * The level of review a proposed change requires before it takes effect: applied immediately, or reviewed by a
 * Storyteller.
 */
export type ApprovalLevel = 'auto' | 'st';

/**
 * A block-level approval rule set. default applies in the general case.
 */
export interface ApprovalRules {
	default?: ApprovalLevel;
	in_type?: ApprovalLevel;
	out_of_type?: ApprovalLevel;
}

/**
 * A single prerequisite that must already be held before a trait or power can be taken.
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
 * A single chronicle/game record.
 */
export interface Game {
	id: number;
	name: string;
	slug: string;
	game_type: string;
	description: string;
	settings: Record< string, unknown > | null;
	/**
	 * Access-control role path prefix associated with this chronicle.
	 */
	asc_role_path: string | null;
	/**
	 * Per-chronicle switch for the change approved/rejected notification email.
	 */
	notifications_enabled: boolean;
	created_by: number;
	created_at: string;
	updated_at: string;
	/**
	 * Present only on the response to a slug-changing update.
	 */
	rename_report?: GameRenameReport;
}

/**
 * Counts of every dependent record a slug rename (Game::rename()) moved to follow the new slug.
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
 * Everything stored under a chronicle that deleting it deletes too, from `GET /games/{slug}/content` (and a refused
 * delete's `data.counts`).
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
	/**
	 * Factions in the chronicle.
	 */
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
 * One chronicle the current user actually holds a real membership row in, from `GET /my/games`.
 */
export interface MyGame {
	slug: string;
	name: string;
	role: string;
	/**
	 * The chronicle is linked to OWbN accessSchema: the site reads it and the chronicle names its role path.
	 */
	asc_linked?: boolean;
}

/**
 * What the current user can actually do in one specific chronicle, from `GET /{game_slug}/my/capabilities`.
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
 * Request body for creating a new game/chronicle.
 */
export interface CreateGameRequest {
	name: string;
	slug?: string;
	game_type?: string;
	description?: string;
	settings?: Record< string, unknown >;
}

/**
 * Request body for updating an existing game/chronicle.
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
 * Request body for the narrower /chronicle-setup route.
 */
export interface UpdateChronicleSetupRequest {
	enabled_stacks?: string[];
	enabled_factions?: Record< string, Record< string, string[] > >;
	require_new_character_approval?: boolean;
	/**
	 * '' clears the override (falls through to the site default).
	 */
	accent_color?: string;
	/**
	 * The purchase lists opened to every creature type.
	 */
	purchase_scope?: Partial<
		Record< 'abilities' | 'backgrounds' | 'merits_flaws', boolean >
	>;
}

// ---------------------------------------------------------------------------
// Chronicle-scoped authorization
// ---------------------------------------------------------------------------

/**
 * The role a WordPress user holds within a single chronicle: head storyteller, assistant storyteller, narrator, or
 * player.
 */
export type GameMemberRole = 'hst' | 'ast' | 'narrator' | 'boons' | 'player';

/**
 * One user's membership record within a chronicle, recording which role they hold. name and user_email are enriched
 * server-side for display purposes and are not stored columns themselves.
 */
/**
 * One player of a chronicle, as its Storytellers see them.
 */
export interface ChroniclePlayer {
	wp_user_id: number;
	display_name: string | null;
	since: string;
}

/**
 * A chronicle's players, and the accessSchema role that also makes someone a player there, when the chronicle reads
 * accessSchema.
 */
export interface ChroniclePlayerList {
	players: ChroniclePlayer[];
	asc_role_path: string | null;
}

/**
 * What adding or removing a player did: `added`, `already_player`, `removed`, `not_member`, or `staff` for a staff
 * member left unchanged; `asc` reports the accessSchema grant or revoke.
 */
export interface ChroniclePlayerResult {
	status: 'added' | 'already_player' | 'removed' | 'not_member' | 'staff';
	role?: GameMemberRole;
	site_added?: boolean;
	asc?: {
		attempted: boolean;
		granted?: boolean;
		revoked?: boolean;
		role_path?: string;
		message?: string;
	};
}

export interface GameMember {
	id: number;
	game_id: number;
	wp_user_id: number;
	role: GameMemberRole;
	created_at: string;
	/**
	 * Enriched server-side for display.
	 */
	name: string | null;
	/**
	 * Enriched server-side for display.
	 */
	user_email: string | null;
	/**
	 * Whether the member's WordPress account can use their role without accessSchema.
	 */
	role_usable: boolean;
}

/**
 * The plugin's current authorization configuration.
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
 */
export interface GameStats {
	characters_by_stack: Record< string, number >;
	characters_by_status: Record< string, number >;
	pending_changes: number;
	active_plots: number;
	recent_activity: ActivityChange[];
	/**
	 * Roster health: players with zero `active` characters.
	 */
	players_without_active_character: number;
	/**
	 * Flagged characters on the spotlight check right now.
	 */
	characters_needing_attention: number;
}

/**
 * One player behind `GameStats.players_without_active_character`'s count.
 */
export interface PlayerWithoutActiveCharacter {
	wp_user_id: number;
	display_name: string | null;
}

/**
 * One row of the Chronicle Setup checklist.
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
	summary: { attention: number; ok: number; info: number; total: number };
}

// ---------------------------------------------------------------------------
// Schema Block — definition types per section_type
// ---------------------------------------------------------------------------

/**
 * The kind of sheet section a schema block defines: a list of discrete traits, a tiered ladder of powers, a numeric
 * resource pool, or a set of free-form identity fields.
 */
export type SectionType =
	| 'trait_list'
	| 'tiered_power'
	| 'resource_pool'
	| 'identity_field';

// --- trait_list ---

/**
 * A single selectable entry within a trait_list block, such as a Discipline, Merit, or Background.
 */
/**
 * One row of a per-value approval schedule.
 */
export interface ApprovalRange {
	from: number;
	to: number;
	approval: ApprovalLevel;
	/**
	 * Citation naming the real-world approval authority, shown to the reviewing Storyteller.
	 */
	reason?: string;
}

/**
 * A site-wide (global-admin-editable, never per-chronicle by default), sanitized-server-side rich-text note on a
 * catalog item, tiered_power level, or tiered_power family.
 */
export interface CatalogDescription {
	reference?: string;
	description?: string;
	source?: string;
}

export interface TraitListItem {
	name: string;
	/**
	 * Drafted Portuguese (Brazil) translation, display-only.
	 */
	name_pt?: string;
	description?: CatalogDescription;
	source?: string;
	/**
	 * Free-text cost expression.
	 */
	cost?: string;
	category?: string;
	approval?: ApprovalLevel;
	/**
	 * Citation naming the real-world approval authority, shown to the reviewing Storyteller.
	 */
	reason?: string;
	/**
	 * Per-count approval schedule, e.g. Occult 1-3 auto, 4-5 st.
	 */
	approval_by_value?: ApprovalRange[];
	prerequisites?: Prerequisite[];
	/**
	 * Grouping label, such as a tribe or breed, used to cluster related items together.
	 */
	group?: string;
	/**
	 * Finer-grained grouping within group, such as a breed's own faction.
	 */
	subgroup?: string;
	/**
	 * Rank label within the group, such as basic, intermediate, or advanced.
	 */
	tier?: string;
	/**
	 * Whether this one item may be held more than once, each holding labelled by its own specialization.
	 */
	allow_multiples?: boolean;
}

/**
 * Declared mechanics for a trait_list block that has none of its own.
 */
export interface TraitListMeta {
	untiered?: {
		/**
		 * Flat XP per level, for a track with no intrinsic cost of its own.
		 */
		cost_per_level?: number;
		/**
		 * Slug of the block this track's cost is read from, e.g. `mage-spheres` for Rotes.
		 */
		derived_from?: string;
		/**
		 * XP per level of the derived-from block's own rating.
		 */
		per_level?: number;
	};
}

/**
 * The full definition of a trait_list block: its catalog of selectable items plus the rules governing how many may be
 * chosen, how they are displayed, and how they are grouped.
 */
export interface TraitListDefinition {
	items: TraitListItem[];
	/**
	 * Declared mechanics, when this list's own rating derives from another block's.
	 */
	_meta?: TraitListMeta;
	/**
	 * The block-wide default for `TraitListItem.allow_multiples`: whether a held row's identity is its name plus its
	 * specialization label.
	 */
	allow_multiples?: boolean;
	allow_custom?: boolean;
	has_specializations?: boolean;
	max_per_item?: number;
	approval_rules?: ApprovalRules;
	/**
	 * Sort items alphabetically by name.
	 */
	alphabetize?: boolean;
	/**
	 * Default display mode; overridden by a template section's own display setting.
	 */
	display?: DisplayType;
	/**
	 * Category labels, in display order, when items are grouped.
	 */
	categories?: string[];
	negative?: boolean;
	/**
	 * Whether a printed sheet draws each rating as empty circles to fill in; false prints the number alone.
	 */
	print_rings?: boolean;
	/**
	 * Whether re-adding a held trait appends a new entry.
	 */
	atomic?: boolean;
	/**
	 * The stored count is a flat XP cost.
	 */
	count_is_cost?: boolean;
	/**
	 * Held entries display and reorder in sheet_data array order.
	 */
	player_order?: boolean;
}

// --- tiered_power ---

/**
 * A single rung on a tiered power's ladder, such as one level of a Discipline or a single named Elder-and-above power
 * kept in an unordered pool.
 */
export interface PowerLevel {
	/**
	 * Numeric rank on the ordered 1-5 ladder.
	 */
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
	 * The source menu's own free-text label for this level.
	 */
	note?: string;
	/**
	 * Drafted Portuguese (Brazil) translation of power_name, display-only.
	 */
	power_name_pt?: string;
	description?: CatalogDescription;
	/**
	 * Free-text cost expression, in the same shape as TraitListItem's own cost field.
	 */
	cost?: string;
	/**
	 * Approval override for reaching this specific level.
	 */
	approval?: ApprovalLevel;
	/**
	 * Citation naming the real-world approval authority, shown to the reviewing Storyteller.
	 */
	reason?: string;
}

/**
 * A single named power, such as one Discipline, and the ladder of levels a character can take within it, along with
 * an optional approval override for the power as a whole.
 */
export interface TieredPower {
	name: string;
	source?: string;
	/**
	 * The numbered ladder, and **only** the ladder.
	 */
	levels: PowerLevel[];
	/**
	 * Above-ladder picks, keyed by **rank**.
	 */
	elder?: Record< string, PowerLevel[] >;
	/**
	 * Ladder-rank levels that do not fit the declared ladder.
	 */
	overflow?: PowerLevel[];
	approval_override?: ApprovalLevel;
	description?: CatalogDescription;
	/**
	 * Blood magic only (`TieredPowerDefinition.blood_magic`).
	 */
	traditions?: Record< string, string | null >;
	/**
	 * Blood magic only: a caste/covenant restriction on who may take this path (e.g. "Sabbat", "Warrior Only").
	 */
	restriction?: string | null;
	/**
	 * Which category value this family is filed under, keyed by axis.
	 */
	category_values?: Record< string, string >;
}

/**
 * The full definition of a tiered_power block: its catalog of powers plus the rules governing out-of-type cost,
 * whether levels must be taken in sequence, and approval.
 */
/**
 * What a `tiered_power` block declares about its own mechanics.
 */
export interface TieredPowerMeta {
	/**
	 * This block's own rank vocabulary, lowest first.
	 */
	ranks: string[];
	/**
	 * How many numbered rungs each rank contributes to the ladder.
	 */
	ladder: Record< string, number >;
	/**
	 * XP per rank.
	 */
	costs: Record< string, number >;
	/**
	 * The out-of-type modifier **per rank**, as an expression.
	 */
	out_of_type?: Record< string, string >;
	/**
	 * Where the in-type test reads the character's own value from, as `block-slug.Field`.
	 */
	in_type_source?: string;
	/**
	 * Category axes a family is filed under.
	 */
	categories?: string[];
	/**
	 * Rank → the level number that rank sits at, for a genre whose ranks do **not** occupy consecutive positions.
	 */
	levels?: Record< string, number >;
	/**
	 * Declares a track that has **no rank vocabulary at all**.
	 */
	untiered?: {
		/**
		 * Flat XP per level, for a track with no ranks.
		 */
		cost_per_level?: number;
		/**
		 * Slug of the block this track's cost is read from, e.g. `mage-spheres` for Rotes.
		 */
		derived_from?: string;
		/**
		 * XP per level of the derived-from block's own rating.
		 */
		per_level?: number;
	};
}

export interface TieredPowerDefinition {
	powers: TieredPower[];
	/**
	 * Declared mechanics.
	 */
	_meta?: TieredPowerMeta;
	/**
	 * @deprecated Superseded by `_meta.out_of_type`, which is keyed per rank and holds an
	 * expression. A single number cannot express Mage's scaling or Demon's doubling, and
	 * every block carrying this declared the same inert `1`. Read only as a fallback
	 * while blocks that predate `_meta` are still seeded.
	 */
	out_of_type_cost_modifier?: number;
	sequential?: boolean;
	approval_rules?: ApprovalRules;
	/**
	 * Whether a homebrew power outside the seeded catalog may still be added.
	 */
	allow_custom?: boolean;
	/**
	 * Flags this block as Blood Magic: taking a power prompts for a Tradition, stored per held pick.
	 */
	blood_magic?: boolean;
	/**
	 * The real traditions this block offers, for the Tradition picker.
	 */
	traditions?: string[];
	/**
	 * Held entries display and reorder in sheet_data array order.
	 */
	player_order?: boolean;
}

// --- resource_pool ---

/**
 * A pointer to another block's field or pool value on the same character's sheet, resolved at render time.
 */
export interface CrossBlockRef {
	block_slug: string;
	field: string;
}

/**
 * Names a resource pool from another block's value: `table` maps that value to the name shown, compared exactly, or
 * loosely when `ignore_words` is set; `otherwise` is tried when this lookup names nothing.
 */
export interface NameLookup {
	keyed_by: CrossBlockRef;
	table: Record< string, string >;
	/**
	 * Words dropped from both sides of a loose comparison, which also ignores case, punctuation and a trailing
	 * parenthetical.
	 */
	ignore_words?: string[];
	/**
	 * The name shown when `keyed_by` has a value the table does not hold.
	 */
	unmatched?: string;
	otherwise?: NameLookup;
}

/**
 * A single numeric resource pool, such as Blood Pool or Willpower, including its starting value, its bounds, and an
 * optional rule for overriding its displayed name based on another block's value.
 */
export interface ResourcePool {
	name: string;
	value_type: 'integer' | 'decimal';
	step?: number;
	default_start: number;
	max?: number;
	min?: number;
	max_lookup?: string;
	/**
	 * Overrides this pool's display name by looking up another block's value in table.
	 */
	name_lookup?: NameLookup;
	/**
	 * XP cost per dot above free_dots.
	 */
	cost_per_dot?: number;
	/**
	 * Dots granted free before cost_per_dot applies.
	 */
	free_dots?: number;
	/**
	 * A cost that scales with the dot being bought.
	 */
	sliding_cost?: {
		/**
		 * The dot costs its own number: the 4th dot costs 4.
		 */
		equals_level?: boolean;
	};
	/**
	 * Per-value approval schedule, keyed on the pool's own PERMANENT value (never temporary - spending/regaining a point
	 * of Willpower in play never needs approval; permanently raising it via XP might).
	 */
	approval_by_value?: ApprovalRange[];
	/**
	 * Drafted Portuguese translation of this pool's own name, display-only.
	 */
	label_pt?: string;
}

/**
 * The full definition of a resource_pool block: the list of numeric pools it exposes.
 */
export interface ResourcePoolDefinition {
	pools: ResourcePool[];
}

// --- identity_field ---

/**
 * A single free-form identity field, such as Clan, Nature, or Concept.
 */
export interface IdentityField {
	name: string;
	/**
	 * multiselect allows choosing more than one value, up to max_selections.
	 */
	field_type: 'text' | 'select' | 'number' | 'textarea' | 'multiselect';
	required: boolean;
	options?: string[];
	options_ref?: string;
	min?: number;
	max?: number;
	/**
	 * multiselect only: how many values may be chosen.
	 */
	max_selections?: number;
	default?: string | number;
	/**
	 * select only: allows a free-text value alongside the fixed option list.
	 */
	allow_custom?: boolean;
	/**
	 * Per-option approval schedule, e.g. picking "Antediluvian" needs Storyteller approval while every other option is
	 * auto.
	 */
	approval_by_option?: Record<
		string,
		{ approval: ApprovalLevel; reason?: string }
	>;
	/**
	 * Drafted Portuguese translation of this field's own name, display-only.
	 */
	label_pt?: string;
	/**
	 * Drafted Portuguese translation per option, keyed by the option's exact canonical string.
	 */
	options_pt?: Record< string, string >;
}

/**
 * The full definition of an identity_field block: the list of fields it exposes, plus an optional clan-to-discipline
 * lookup table used by vampire character creation.
 */
export interface IdentityFieldDefinition {
	fields: IdentityField[];
	clan_disciplines?: Record< string, string[] >;
}

// --- discriminated union ---

/**
 * The definition shape stored on a schema block, matching one of the four section types.
 */
export type BlockDefinition =
	| TraitListDefinition
	| TieredPowerDefinition
	| ResourcePoolDefinition
	| IdentityFieldDefinition;

/**
 * A single reusable schema block, such as "Vampire Disciplines" or "Werewolf Gifts".
 */
export interface SchemaBlock {
	id: number;
	slug: string;
	/**
	 * '' for the global catalog.
	 */
	game_slug?: string;
	name: string;
	section_type: SectionType;
	definition: BlockDefinition;
	is_system: boolean;
	/**
	 * Hides the block's section and its stored values from anyone without be_manage_characters.
	 */
	storyteller_only?: boolean;
	version: number;
	created_by: number;
	created_at: string;
	updated_at: string;
}

/**
 * Request body for creating a new schema block. slug, name, and section_type are required to identify and classify
 * the block.
 */
export interface CreateSchemaBlockRequest {
	slug: string;
	name: string;
	section_type: SectionType;
	definition?: BlockDefinition;
	storyteller_only?: boolean;
}

/**
 * Request body for updating an existing schema block.
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
 * A single block's placement within a creature stack's sheet layout: which block, its label, its position, and
 * whether it is required or paired with a negative-trait counterpart block.
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
 * The full sheet layout for a creature stack: the ordered list of sections it is made of, plus optional display
 * preferences.
 */
export interface StackDefinition {
	sections: StackSection[];
	display_preferences?: Record< string, unknown >;
}

/**
 * A single step in a creature stack's guided character-creation flow, naming which sections it covers and the point
 * budget available for spending on them.
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
 * The ordered set of character-creation steps for a creature stack, if it defines a guided creation flow.
 */
export interface CreationRules {
	steps?: CreationStep[];
}

/**
 * A single creature type, such as Vampire or Werewolf.
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
 * A creature stack together with the actual SchemaBlock record for every block its sections reference, keyed by block
 * slug.
 */
export interface ResolvedStack {
	stack: CreatureStack;
	blocks: Record< string, SchemaBlock >;
}

/**
 * Request body for creating a new creature stack. slug, name, and stack_definition are required.
 */
export interface CreateCreatureStackRequest {
	slug: string;
	name: string;
	game_line?: string;
	stack_definition: StackDefinition;
	creation_rules?: CreationRules;
}

/**
 * Request body for updating an existing creature stack.
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
 * A single block's placement within a sheet template's layout: its column and order, its display heading, whether it
 * starts collapsed, and how much row width it occupies.
 */
export interface TemplateLayoutSection {
	block_slug: string;
	column: number;
	order: number;
	title: string;
	display: DisplayType | null;
	collapsed: boolean;
	/**
	 * How much of a 6-unit row this section spans: third (default), half, or full.
	 */
	width?: 'third' | 'half' | 'full';
	/**
	 * Cross-block references appended to title once every reference resolves.
	 */
	title_refs?: CrossBlockRef[];
}

/**
 * A full sheet layout: its column count and the ordered list of block sections placed within it.
 */
export interface TemplateLayout {
	version: 1;
	columns: number;
	sections: TemplateLayoutSection[];
}

/**
 * The layout actually resolved for a given stack and template type, along with the identity of the template row it
 * came from, if any.
 */
export interface ResolvedTemplate {
	/**
	 * Null when resolved_from is "generated".
	 */
	id: number | null;
	name: string | null;
	stack_slug: string;
	template_type: string;
	layout: TemplateLayout;
}

/**
 * Response from resolving a template for a stack.
 */
export interface TemplateResolveResponse {
	resolved_from: 'game' | 'global' | 'generated';
	template: ResolvedTemplate;
}

/**
 * The raw stored template row, as opposed to the ResolvedTemplate outcome of resolving one. game_id is null for a
 * global template and set for a chronicle-specific one.
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
 * Request body for creating a new template. name, template_type, and layout are required.
 */
export interface CreateTemplateRequest {
	name: string;
	template_type: string;
	layout: TemplateLayout;
	stack_slug?: string;
}

/**
 * Request body for updating an existing template.
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
 * The common pagination and ordering parameters accepted by most collection endpoints: page number, page size, sort
 * field, and sort direction.
 */
export interface CollectionParams {
	page?: number;
	per_page?: number;
	orderby?: string;
	order?: 'ASC' | 'DESC';
}

/**
 * Query parameters for the games collection endpoint.
 */
export interface GameCollectionParams extends CollectionParams {
	game_type?: string;
}

/**
 * Query parameters for the schema blocks collection endpoint.
 */
export interface SchemaBlockCollectionParams extends CollectionParams {
	section_type?: SectionType;
	is_system?: boolean;
	search?: string;
	/**
	 * Substitutes this chronicle's own fork of a block in place of the global one, where one exists.
	 */
	game_slug?: string;
}

/**
 * Query parameters for the creature stacks collection endpoint.
 */
export interface CreatureStackCollectionParams extends CollectionParams {
	game_line?: string;
	is_system?: boolean;
	search?: string;
	/**
	 * Narrows to this chronicle's own settings.enabled_stacks.
	 */
	game_slug?: string;
}
