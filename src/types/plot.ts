/**
 * Type definitions for plots and their child actions and rumors, plot entries and connections, and the
 * action-allocation and rumor-generation response shapes.
 */
import type { QueryCondition, QueryLogic } from './query';
import type { Attachment } from './attachment';

/**
 * The stored lifecycle state of a plot: active, resolved, or archived.
 */
export type PlotStatus = 'active' | 'resolved' | 'archived';

/**
 * A plot's computed display status: not yet started, currently active, or finished.
 */
export type DerivedPlotStatus = 'pending' | 'active' | 'finished';

/**
 * Who originated a plot: a player through their own actions, or a Storyteller.
 */
export type InitiatedBy = 'player' | 'st';

/**
 * The kind of a single plot entry: a player's action, a Storyteller's response, a freestanding note, or a resolution
 * entry.
 */
export type EntryType = 'action' | 'response' | 'note' | 'resolution';

/**
 * The kind of entity a connection can link: a character, a plot, a world object, or a tag.
 */
export type EntityType = 'character' | 'plot' | 'world_object' | 'tag';

/**
 * A saved query shape used to target a plot or rumor at a set of matching characters, resolved by running the query.
 */
export interface TargetQuery {
	field: string;
	operator: string;
	value: string;
}

/**
 * A display-only grouping label for a top-level plot, such as an arc or season.
 */
export type PlotCategory = 'arc' | 'subplot' | 'season' | 'episode';

/**
 * A single faction's stated goal within a plot, along with the key NPCs pursuing it and the strategy they are using.
 */
export interface FactionGoal {
	faction: string;
	goal: string;
	key_npcs?: string;
	strategy?: string;
}

/**
 * Who may see a plot, item, or location: everyone in the chronicle, Storytellers/Narrators only, or a
 * Storyteller-defined rule set (AudienceRules) plus any character directly connected to it.
 */
export type AudienceValue = 'everyone' | 'storytellers' | 'restricted';

/**
 * A Storyteller-defined rule set narrowing a `restricted` audience to characters matching a query, in the same
 * {conditions, logic} shape the query builder already uses (resolved server-side against the `char` inventory).
 */
export interface AudienceRules {
	conditions: QueryCondition[];
	logic: QueryLogic;
}

/**
 * Who may see a single plot entry.
 */
export type EntryAudienceValue = 'plot' | 'storytellers' | 'characters';

/**
 * A character option in a narrow, name-and-id-only picker, such as a player plot's invite candidates or an entry's
 * directed-post recipients.
 */
export interface CharacterOption {
	id: number;
	name: string;
}

/**
 * A single plot, action, or rumor record.
 */
export interface Plot {
	id: number;
	game_id: number;
	/**
	 * Parent plot id; null for a top-level plot, forming a Plot -> Action -> Rumor hierarchy.
	 */
	parent_plot_id: number | null;
	/**
	 * Cover image attachment id.
	 */
	image_id: number | null;
	image_url: string | null;
	title: string;
	description: string | null;
	status: PlotStatus;
	/**
	 * Computed server-side from status and dates.
	 */
	derived_status: DerivedPlotStatus;
	initiated_by: InitiatedBy;
	/**
	 * Null on an ordinary plot, action, or rumor.
	 */
	plot_category: PlotCategory | null;
	created_by: number;
	first_introduced: string | null;
	start_date: string | null;
	end_date: string | null;
	game_date: string | null;
	resolution_details: string | null;
	resolution_impact: string | null;
	faction_goals: FactionGoal[] | null;
	cliffhanger: string | null;
	target_query: TargetQuery | null;
	/**
	 * Only present for users with the be_manage_plots capability.
	 */
	st_notes?: string | null;
	/**
	 * Defaults to `storytellers` for a Storyteller-created plot, `restricted` for a player plot.
	 */
	audience: AudienceValue;
	/**
	 * Only meaningful when audience is `restricted`.
	 */
	audience_rules: AudienceRules | null;
	/**
	 * Whether the current viewer owns this player plot.
	 */
	is_owner: boolean;
	/**
	 * Release batch gate: held plus a null release_batch_id means "draft, never visible".
	 */
	held: boolean;
	release_batch_id: number | null;
	/**
	 * This plot's staff owner, or null when unassigned.
	 */
	assigned_to: number | null;
	created_at: string;
	updated_at: string;
	/**
	 * Only present on the single-plot fetch.
	 */
	entries?: PlotEntry[];
	connections?: Connection[];
	/**
	 * Only present on the single-plot fetch.
	 */
	attachments?: Attachment[];
	/**
	 * Immediate child plots, included in the same fetch.
	 */
	children?: Plot[];
}

/**
 * A single entry in a plot's timeline: a player's action, a Storyteller's response, a note, or a resolution.
 */
export interface PlotEntry {
	id: number;
	plot_id: number;
	author_id: number;
	entry_type: EntryType;
	content: string;
	/**
	 * A Timeline entry's in-fiction date, independent of created_at.
	 */
	event_date: string | null;
	/**
	 * Defaults to `plot` (public) when omitted.
	 */
	audience: EntryAudienceValue;
	/**
	 * Only meaningful when audience is `characters`.
	 */
	audience_character_ids: number[] | null;
	/**
	 * Release batch gate: held plus a null release_batch_id means "draft, never visible".
	 */
	held: boolean;
	release_batch_id: number | null;
	created_at: string;
}

/**
 * A link between two entities, such as a character connected to a plot or to another character.
 */
export interface Connection {
	id: number;
	game_id: number;
	source_type: EntityType;
	source_id: number;
	target_type: EntityType;
	target_id: number | null;
	label: string | null;
	notes: string | null;
	created_by: number;
	created_at: string;
}

/**
 * Request body for creating a new plot, action, or rumor.
 */
export interface CreatePlotRequest {
	title: string;
	description?: string;
	initiated_by?: InitiatedBy;
	status?: PlotStatus;
	first_introduced?: string;
	start_date?: string;
	end_date?: string;
	st_notes?: string;
	/**
	 * Set to create this plot as a child of an existing plot.
	 */
	parent_plot_id?: number;
	/**
	 * be_manage_plots only; tags the new plot as a rumor via the apr_rumor connection.
	 */
	is_rumor?: boolean;
	/**
	 * be_manage_plots only.
	 */
	plot_category?: PlotCategory;
	cliffhanger?: string;
	faction_goals?: FactionGoal[];
	/**
	 * Cover image attachment id.
	 */
	image_id?: number;
	/**
	 * be_manage_plots only; a player plot is always forced to `restricted` regardless of this field.
	 */
	audience?: AudienceValue;
	/**
	 * be_manage_plots only; requires audience: 'restricted'.
	 */
	audience_rules?: AudienceRules;
	/**
	 * Player branch only: the player's own character this plot belongs to.
	 */
	character_id?: number;
}

/**
 * Request body for updating an existing plot, action, or rumor.
 */
export interface UpdatePlotRequest {
	title?: string;
	description?: string;
	status?: PlotStatus;
	initiated_by?: InitiatedBy;
	first_introduced?: string;
	start_date?: string;
	end_date?: string;
	resolution_details?: string;
	resolution_impact?: string;
	st_notes?: string;
	/**
	 * Changes this plot's parent.
	 */
	parent_plot_id?: number | null;
	/**
	 * Display grouping label.
	 */
	plot_category?: PlotCategory | null;
	cliffhanger?: string | null;
	faction_goals?: FactionGoal[] | null;
	/**
	 * Cover image attachment id.
	 */
	image_id?: number | null;
	audience?: AudienceValue;
	/**
	 * Requires audience: 'restricted'.
	 */
	audience_rules?: AudienceRules | null;
	/**
	 * be_manage_plots only; must be a chronicle member with role hst, ast, or narrator. null unassigns.
	 */
	assigned_to?: number | null;
}

/**
 * The plot list's character filter.
 */
export type CharacterPlotFilter = 'only' | 'exclude';

/**
 * Query parameters accepted by the plots collection endpoint.
 */
export interface PlotCollectionParams {
	status?: PlotStatus;
	initiated_by?: InitiatedBy;
	search?: string;
	date_from?: string;
	date_to?: string;
	/**
	 * `only`: each character's own plot and its action rounds.
	 */
	character_plots?: CharacterPlotFilter;
	orderby?: 'title' | 'status' | 'created_at' | 'updated_at';
	order?: 'ASC' | 'DESC';
	page?: number;
	per_page?: number;
}

/**
 * Response from the player-facing "my plots" endpoint: the plots resolved as relevant to the current player, plus
 * which resolution mechanisms contributed to that list.
 */
export interface MyPlotsResponse {
	plots: Plot[];
	/**
	 * Which resolution mechanisms are active.
	 */
	resolution: {
		direct_connections: boolean;
		target_query: boolean;
	};
}

/**
 * Request body for creating a new plot entry. entry_type and content are required.
 */
export interface CreateEntryRequest {
	entry_type: EntryType;
	content: string;
	/**
	 * A Timeline entry's in-fiction date.
	 */
	event_date?: string;
	/**
	 * A player may only choose `plot` (the default) or `storytellers`.
	 */
	audience?: EntryAudienceValue;
	/**
	 * Required, non-empty, when audience is `characters`.
	 */
	audience_character_ids?: number[];
}

/**
 * Request body for creating a new connection between two entities. source_type and source_id identify the origin.
 */
export interface CreateConnectionRequest {
	source_type: EntityType;
	source_id: number;
	target_type: EntityType;
	target_id?: number;
	label?: string;
	notes?: string;
}

/**
 * Request body for updating an existing connection.
 */
export interface UpdateConnectionRequest {
	label?: string;
	notes?: string;
}

/**
 * A single subaction produced by allocating a character's actions across their held powers.
 */
export interface Subaction {
	name: string;
	level: number;
	total: number;
	unused: number;
	growth: number;
	spent?: number;
	over_budget?: boolean;
}

/**
 * Response from allocating a character's actions for a game date.
 */
export interface AllocateActionsResponse {
	subactions: Subaction[];
	committed: boolean;
	plot_id?: number;
	complete?: boolean;
}

/**
 * A single generated rumor candidate.
 */
export interface RumorCandidate {
	title: string;
	category: string;
	target_query: TargetQuery | null;
	description: string;
	/**
	 * Number of characters actually matched by target_query.
	 */
	recipient_count: number;
}

/**
 * Response from generating rumors for a game date.
 */
export interface GenerateRumorsResponse {
	rumors: RumorCandidate[];
	committed: boolean;
}
