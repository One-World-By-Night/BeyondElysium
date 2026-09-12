/**
 * Type definitions for plots and their child actions and rumors,
 * plot entries and connections, and the action-allocation and
 * rumor-generation response shapes. A plot, action, and rumor all
 * share this same Plot record, distinguished by their place in
 * the parent/child hierarchy and by tags.
 */

/**
 * The stored lifecycle state of a plot: active, resolved, or
 * archived. Set directly by a Storyteller, as opposed to
 * DerivedPlotStatus, which is computed.
 */
export type PlotStatus = 'active' | 'resolved' | 'archived';

/**
 * A plot's computed display status: not yet started, currently
 * active, or finished. Derived server-side from its dates and
 * stored status rather than stored itself.
 */
export type DerivedPlotStatus = 'pending' | 'active' | 'finished';

/**
 * Who originated a plot: a player through their own actions, or
 * a Storyteller.
 */
export type InitiatedBy = 'player' | 'st';

/**
 * The kind of a single plot entry: a player's action, a
 * Storyteller's response, a freestanding note, or a resolution
 * entry.
 */
export type EntryType = 'action' | 'response' | 'note' | 'resolution';

/**
 * The kind of entity a connection can link: a character, a plot,
 * a world object, or a tag.
 */
export type EntityType = 'character' | 'plot' | 'world_object' | 'tag';

/**
 * A saved query shape used to target a plot or rumor at a set of
 * matching characters. Recipients are resolved by running this
 * query rather than being listed individually.
 */
export interface TargetQuery {
	field: string;
	operator: string;
	value: string;
}

/**
 * A display-only grouping label for a top-level plot, such as an
 * arc or season. Purely organizational; no behavior branches on
 * this value.
 */
export type PlotCategory = 'arc' | 'subplot' | 'season' | 'episode';

/**
 * A single faction's stated goal within a plot, along with the
 * key NPCs pursuing it and the strategy they are using.
 */
export interface FactionGoal {
	faction: string;
	goal: string;
	key_npcs?: string;
	strategy?: string;
}

/**
 * A single plot, action, or rumor record - all three share this
 * same shape, distinguished by parent_plot_id and by tags rather
 * than by separate types. Holds its status, timeline, and
 * resolution details, and optionally its entries, connections,
 * and children.
 */
export interface Plot {
	id: number;
	game_id: number;
	/** Parent plot id; null for a top-level plot, forming a Plot -> Action -> Rumor hierarchy. */
	parent_plot_id: number | null;
	/** Cover image attachment id; image_url is resolved from it server-side. */
	image_id: number | null;
	image_url: string | null;
	title: string;
	description: string | null;
	status: PlotStatus;
	/** Computed server-side from status and dates; status remains the authoritative stored value. */
	derived_status: DerivedPlotStatus;
	initiated_by: InitiatedBy;
	/** Null on an ordinary plot, action, or rumor; those stay initiated_by/tag-driven instead. */
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
	/** Only present for users with the be_manage_plots capability. */
	st_notes?: string | null;
	created_at: string;
	updated_at: string;
	/** Only present on the single-plot fetch. */
	entries?: PlotEntry[];
	connections?: Connection[];
	/** Immediate child plots, included in the same fetch. */
	children?: Plot[];
}

/**
 * A single entry in a plot's timeline: a player's action, a
 * Storyteller's response, a note, or a resolution. Ordered by
 * created_at and optionally carries its own in-fiction event
 * date.
 */
export interface PlotEntry {
	id: number;
	plot_id: number;
	author_id: number;
	entry_type: EntryType;
	content: string;
	/** A Timeline entry's in-fiction date, independent of created_at. */
	event_date: string | null;
	created_at: string;
}

/**
 * A link between two entities, such as a character connected to
 * a plot or to another character. Carries an optional label and
 * notes describing the nature of the relationship.
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
 * Request body for creating a new plot, action, or rumor. Only
 * title is required; every other field is optional and takes a
 * server-side default when omitted.
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
	/** Set to create this plot as a child of an existing plot. */
	parent_plot_id?: number;
	/** be_manage_plots only; tags the new plot as a rumor via the apr_rumor connection. */
	is_rumor?: boolean;
	/** be_manage_plots only. */
	plot_category?: PlotCategory;
	cliffhanger?: string;
	faction_goals?: FactionGoal[];
	/** Cover image attachment id; be_manage_plots only. */
	image_id?: number;
}

/**
 * Request body for updating an existing plot, action, or rumor.
 * Every field is optional; only the fields included in the
 * request are changed.
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
	/** Changes this plot's parent; null moves it to the top level. */
	parent_plot_id?: number | null;
	/** Display grouping label; null clears it. */
	plot_category?: PlotCategory | null;
	cliffhanger?: string | null;
	faction_goals?: FactionGoal[] | null;
	/** Cover image attachment id; null clears it. */
	image_id?: number | null;
}

/**
 * Query parameters accepted by the plots collection endpoint.
 * Supports pagination, ordering, filtering by status or
 * initiator, a free-text search, and a date range.
 */
export interface PlotCollectionParams {
	status?: PlotStatus;
	initiated_by?: InitiatedBy;
	search?: string;
	date_from?: string;
	date_to?: string;
	orderby?: 'title' | 'status' | 'created_at' | 'updated_at';
	order?: 'ASC' | 'DESC';
	page?: number;
	per_page?: number;
}

/**
 * Response from the player-facing "my plots" endpoint: the plots
 * resolved as relevant to the current player, plus which
 * resolution mechanisms contributed to that list.
 */
export interface MyPlotsResponse {
	plots: Plot[];
	/** Which resolution mechanisms are active; kept as an object for response-shape stability. */
	resolution: {
		direct_connections: boolean;
		target_query: boolean;
	};
}

/**
 * Request body for creating a new plot entry. entry_type and
 * content are required; event_date is optional and only
 * meaningful for a Timeline-style entry.
 */
export interface CreateEntryRequest {
	entry_type: EntryType;
	content: string;
	/** A Timeline entry's in-fiction date. */
	event_date?: string;
}

/**
 * Request body for creating a new connection between two
 * entities. source_type and source_id identify the origin;
 * target_type and target_id identify the other end, when known.
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
 * Request body for updating an existing connection. Only its
 * label and notes may be changed; both are optional and only
 * included fields are updated.
 */
export interface UpdateConnectionRequest {
	label?: string;
	notes?: string;
}

/**
 * A single subaction produced by allocating a character's actions
 * across their held powers. Reports the power's name, its level,
 * how many actions it received in total, how many went unused,
 * and how much it grew. unused already reflects any
 * Background_Ledger spends debited against it; spent/over_budget
 * report that debit explicitly.
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
 * Lists the resulting subactions and whether the allocation was
 * actually committed or only previewed.
 */
export interface AllocateActionsResponse {
	subactions: Subaction[];
	committed: boolean;
	plot_id?: number;
	complete?: boolean;
}

/**
 * A single generated rumor candidate, not yet committed. Carries
 * its title, category, the target query used to find recipients,
 * and a description of the rumor's content.
 */
export interface RumorCandidate {
	title: string;
	category: string;
	target_query: TargetQuery | null;
	description: string;
	/** Number of characters actually matched by target_query. */
	recipient_count: number;
}

/**
 * Response from generating rumors for a game date. Lists the
 * generated rumor candidates and whether they were actually
 * committed or only previewed.
 */
export interface GenerateRumorsResponse {
	rumors: RumorCandidate[];
	committed: boolean;
}
