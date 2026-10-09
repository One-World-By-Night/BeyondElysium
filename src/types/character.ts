/**
 * Type definitions for player and NPC characters and their edit history.
 */
import type { ApprovalLevel } from './index';
import type { AudienceRules, AudienceValue } from './plot';
import type { Visit, VisitingFrom } from './transfer';
import type { Attachment } from './attachment';

/**
 * A character's full set of sheet block values, keyed by block slug.
 */
export type SheetData = Record< string, unknown >;

/**
 * The lifecycle state of a submitted character change.
 */
export type ChangeStatus = 'pending' | 'approved' | 'rejected' | 'forwarded';

// ---------------------------------------------------------------------------
// Character
// ---------------------------------------------------------------------------

/**
 * A single player or NPC character record as stored and returned by the characters REST endpoints.
 */
export interface Character {
	/**
	 * Local auto-increment id, valid only on this WordPress installation.
	 */
	id: number;
	/**
	 * Only on a create response: this character is its player's request to join the chronicle, waiting for a Storyteller.
	 */
	join_pending?: boolean;
	/**
	 * Permanent identity that survives transfers between installations.
	 */
	uuid: string;
	name: string;
	stack_slug: string;
	owner_type: string;
	owner_slug: string;
	wp_user_id: number | null;
	/**
	 * Player's display name; mirrors the linked account's name once wp_user_id is set.
	 */
	player_name: string | null;
	/**
	 * Email address for a not-yet-created account this character is meant to be linked to.
	 */
	pending_player_email?: string | null;
	/**
	 * A matching real WP account found server-side, awaiting manager confirmation before linking.
	 */
	pending_match?: { id: number; display_name: string } | null;
	status: 'active' | 'inactive' | 'retired' | 'dead' | 'pending' | string;
	is_npc: boolean;
	/**
	 * How much of the sheet an NPC needs.
	 */
	npc_detail: 'full' | 'quick';
	/**
	 * An NPC's staff owner; always null on a player character.
	 */
	assigned_to: number | null;
	/**
	 * Who's Who display name.
	 */
	public_name?: string | null;
	/**
	 * Who's Who description, [ST]...[/ST] stripped for a non-manager viewer.
	 */
	public_description?: string | null;
	/**
	 * WP attachment id for the Who's Who portrait.
	 */
	public_image_id?: number | null;
	/**
	 * Audience gating who can see this NPC's Who's Who profile at all.
	 */
	profile_audience?: AudienceValue;
	profile_audience_rules?: AudienceRules | null;
	/**
	 * Whether a player character's own profile names the account playing them.
	 */
	profile_show_player?: boolean;
	/**
	 * This character's own uploaded portrait, embedded on GET {game}/characters/{id}.
	 */
	attachments?: Attachment[];
	narrator: string | null;
	start_date: string | null;
	xp_earned: number;
	xp_unspent: number;
	biography: string | null;
	notes: string | null;
	/**
	 * Only present for users with the be_manage_characters capability.
	 */
	rp_notes?: string | null;
	/**
	 * WP attachment id for the character's portrait image.
	 */
	image_id?: number | null;
	image_url?: string | null;
	/**
	 * Whether the current user may edit this character.
	 */
	can_edit?: boolean;
	/**
	 * Whether the current user holds the be_manage_characters capability.
	 */
	can_manage?: boolean;
	/**
	 * Whether the current user may customize this character's sheet style.
	 */
	can_customize_sheet?: boolean;
	/**
	 * What a Storyteller should look at on this sheet: a path held under one tradition spelled two ways. Empty for
	 * everyone else.
	 */
	sheet_warnings?: SheetWarning[];
	/**
	 * Every other chronicle this character is also currently active at, from its own outbound visits - empty while
	 * none are open.
	 */
	visits?: Visit[];
	/**
	 * Set only while this character row is itself a visitor here, from an open inbound row.
	 */
	visiting_from?: VisitingFrom | null;
	sheet_data: SheetData;
	created_by: number;
	created_at: string;
	updated_at: string;
}

/**
 * A path a sheet holds under one tradition spelled two ways, with the spellings and the level each copy carries.
 */
export interface SheetWarning {
	block_slug: string;
	name: string;
	traditions: string[];
	levels: Array< number | null >;
}

/**
 * Request body for creating a new character via the characters collection endpoint.
 */
export interface CreateCharacterRequest {
	name: string;
	stack_slug: string;
	wp_user_id?: number;
	player_name?: string;
	status?: string;
	is_npc?: boolean;
	/**
	 * Manager-only, meaningless on a PC.
	 */
	npc_detail?: 'full' | 'quick';
	narrator?: string;
	start_date?: string;
	biography?: string;
	notes?: string;
	rp_notes?: string;
	sheet_data?: SheetData;
	/**
	 * Manager-only: a sheet that already exists, so the build takes neither the chronicle's starting XP nor its
	 * creation charge.
	 */
	existing_character?: boolean;
}

/**
 * Request body for updating an existing character.
 */
export interface UpdateCharacterRequest {
	name?: string;
	status?: string;
	biography?: string;
	notes?: string;
	rp_notes?: string;
	narrator?: string;
	player_name?: string;
	start_date?: string;
	is_npc?: boolean;
	/**
	 * be_manage_characters only, either template on the same character.
	 */
	npc_detail?: 'full' | 'quick';
	image_id?: number | null;
	/**
	 * null/0 unassigns; omitting the field entirely leaves it untouched.
	 */
	wp_user_id?: number | null;
	/**
	 * Empty string clears it.
	 */
	pending_player_email?: string;
	/**
	 * be_manage_characters and NPC only.
	 */
	assigned_to?: number | null;
}

// ---------------------------------------------------------------------------
// NPC public profile ("Who's Who")
// ---------------------------------------------------------------------------

/**
 * The public projection of an NPC that `Npc_Profiles_Controller` returns.
 */
export interface NpcProfile {
	id: number;
	name: string;
	public_description: string;
	image_url: string | null;
	titles: string[];
	factions: string[];
}

/**
 * An NPC or player-character profile from GET /{game}/profiles.
 */
export interface CharacterProfile extends NpcProfile {
	kind: 'npc' | 'pc';
	played_by: string | null;
	portrait_attachment_id: number | null;
}

/**
 * Request body for updating a character's public-profile fields. A manager may set every field; a player editing
 * their own non-NPC character may set all but `profile_audience_rules`, and `profile_audience` only to everyone or
 * storytellers.
 */
export interface UpdateNpcProfileRequest {
	public_name?: string;
	public_description?: string;
	public_image_id?: number | null;
	profile_audience?: AudienceValue;
	profile_audience_rules?: AudienceRules | null;
	profile_show_player?: boolean;
}

/**
 * A minimal summary of a WordPress user account.
 */
export interface WpUserSummary {
	id: number;
	display_name: string;
	/**
	 * Site administrators always see it.
	 */
	email?: string;
}

/**
 * Query parameters accepted by the characters collection endpoint.
 */
export interface CharacterCollectionParams {
	page?: number;
	per_page?: number;
	orderby?:
		| 'name'
		| 'status'
		| 'created_at'
		| 'updated_at'
		| 'xp_earned'
		| 'xp_unspent'
		| 'stack_slug'
		| 'player_name';
	order?: 'ASC' | 'DESC';
	status?: string;
	stack_slug?: string;
	is_npc?: boolean;
	search?: string;
}

// ---------------------------------------------------------------------------
// Change
// ---------------------------------------------------------------------------

/**
 * The kind of edit a character change represents: adding, removing, or modifying a trait.
 */
export type ChangeType =
	| 'add_trait'
	| 'remove_trait'
	| 'modify_trait'
	| 'modify_resource'
	| 'modify_identity'
	| 'xp_earn'
	| 'xp_adjust'
	| 'import_note'
	// A build's own cost, priced against its creature type's creation rules and charged once, at creation.
	| 'creation_spend'
	// A Storyteller granting a trait_list item paid for from a named resource pool rather than the character's own XP.
	| 'pool_spend'
	// What a catalog cutover did to a sheet, and its undoing.
	| 'catalog_rekey'
	| 'catalog_rekey_revert'
	// A player proposing a catalog item, location or rote for their own character.
	| 'propose_world_object'
	// A player proposing a coterie/pack/cabal/motley for their own character.
	| 'propose_faction'
	// A character linked to or unlinked from a player's account.
	| 'player_link'
	// A player logging what one of their own characters learned, for a Storyteller to tie to a secret.
	| 'log_knowledge'
	// A player telling another character a secret their own character already knows.
	| 'pass_secret'
	// A host chronicle's own note about a visiting character, with no sheet effect.
	| 'visit_note'
	// A host chronicle's own request to pair a player-submitted character with its real home.
	| 'visit_pairing';

/**
 * The data carried by a single character change.
 */
export type ChangePayload = Record< string, unknown >;

/**
 * A single recorded change to a character's sheet, including its XP cost, review status, and who submitted and
 * reviewed it.
 */
export interface CharacterChange {
	id: number;
	character_id: number;
	change_type: ChangeType;
	category: string | null;
	change_data: ChangePayload;
	xp_cost: number;
	status: ChangeStatus;
	submitted_by: number;
	reviewed_by: number | null;
	submitted_at: string;
	reviewed_at: string | null;
	/**
	 * The submitter's own note.
	 */
	notes: string | null;
	/**
	 * The reviewing Storyteller's note, kept apart from the submitter's.
	 */
	review_notes?: string | null;
	/**
	 * Citation naming the real-world approval authority, set at submission time.
	 */
	reason: string | null;
	/**
	 * Ties every change submitted together in one editor submission to each other.
	 */
	submission_id?: string | null;
	/**
	 * The host's own visit this arrived on, its chronicle's name, and its free-text note - only set on a change
	 * forwarded here from a host chronicle.
	 */
	source_visit_id?: number | null;
	host_chronicle?: string | null;
	host_note?: string | null;
}

/**
 * Request body for submitting a new character change.
 */
export interface ChangeRequest {
	change_type: ChangeType;
	category: string;
	change_data: ChangePayload;
	xp_cost?: number;
	notes?: string;
}

/**
 * Request body for approving or rejecting a pending change.
 */
export interface ChangeReviewRequest {
	status: 'approved' | 'rejected';
	notes?: string;
	/**
	 * The review_token the queue issued.
	 */
	review_token?: string;
	/**
	 * What a purchase waiting for a price costs, in whole XP from 0 to 500: per dot for a trait list, for the whole pick
	 * for a power.
	 */
	xp_cost?: number;
	/**
	 * Approving a `log_knowledge` change: an existing secret's id to tie it to.
	 */
	secret_id?: number;
	/**
	 * Approving a `log_knowledge` change with no existing secret: what to create one on.
	 */
	entity_type?: string;
	entity_id?: number;
	title?: string;
	content?: string;
}

/**
 * Query parameters accepted by a character's changes collection endpoint.
 */
export interface ChangeCollectionParams {
	page?: number;
	per_page?: number;
	order?: 'ASC' | 'DESC';
	status?: ChangeStatus;
	change_type?: ChangeType;
}

/**
 * Query parameters for the game-wide change approval queue.
 */
export interface QueueCollectionParams extends ChangeCollectionParams {
	character_id?: number;
	approval_level?: ApprovalLevel;
}

/**
 * A single row in the game-wide approval queue.
 */
export interface QueueChange extends CharacterChange {
	character_name: string | null;
	approval_level: ApprovalLevel;
	/**
	 * The submitter's display name.
	 */
	submitted_by_name: string | null;
	/**
	 * Identifies exactly the content shown.
	 */
	review_token: string;
	/**
	 * For a change waiting for a price: what one price would cover.
	 */
	cost_units?: CostUnits | null;
}

/**
 * One of a player's own changes across every chronicle on this site where they have a character - pending, or
 * reviewed in the last 30 days.
 */
export interface MyChangeAcrossGames extends CharacterChange {
	character_name: string | null;
	game_slug: string;
	game_name: string;
	/**
	 * A plain description of what the change does.
	 */
	description: string;
	/**
	 * `pending`, `approved`, `auto_approved`, or `refused`.
	 */
	display_status: 'pending' | 'approved' | 'auto_approved' | 'refused';
}

/**
 * What one price covers: each dot of a trait list, or a whole pick of a tiered power.
 */
export type PriceUnit = 'dot' | 'pick';

/**
 * How the server says a change waiting for a price is priced: per what, over how many.
 */
export interface CostUnits {
	per: PriceUnit;
	units: number;
	/**
	 * A flaw: the price is recorded on the row and nothing is deducted for it.
	 */
	negative?: boolean;
}

/**
 * A single row in a game's recent activity feed.
 */
export interface ActivityChange extends CharacterChange {
	character_name: string | null;
}

/**
 * Response from a batch-approve request.
 */
export interface BatchApproveResponse {
	approved: number[];
	skipped: number[];
	/**
	 * Changes left alone because they are waiting for a price; each needs its own review.
	 */
	needs_cost?: number[];
}

/**
 * Response from submitting a whole set of editor changes together.
 */
export interface SubmitSetResponse {
	submission_id: string;
	changes: CharacterChange[];
}

// ---------------------------------------------------------------------------
// Preview
// ---------------------------------------------------------------------------

/**
 * The priced outcome for one proposed change, in the same order as the request array it was computed from.
 */
export interface ChangePreviewResult {
	xp_cost: number;
	approval_level: ApprovalLevel;
	/**
	 * Citation naming the real-world approval authority, when the matched rule carries one.
	 */
	approval_reason: string | null;
	/**
	 * False when the purchase has no price yet.
	 */
	priced?: boolean;
	unpriced_reason?: string | null;
	/**
	 * Present when the server would refuse this change on submit.
	 */
	error?: { code: string; message: string };
	/**
	 * True when this change fails real validation and can never be submitted as-is - distinct from `priced: false`,
	 * which is an honestly-unpriced purchase a Storyteller still has to set a cost for at approval. `xp_cost` and
	 * `priced` carry no real meaning when this is true; render `error` instead of a cost.
	 */
	invalid?: boolean;
}

/**
 * Response from previewing a batch of proposed changes.
 */
export interface PreviewChangesResponse {
	results: ChangePreviewResult[];
	/**
	 * Character's xp_unspent after applying every proposed change's cost.
	 */
	running_xp_unspent: number;
}

// ---------------------------------------------------------------------------
// Snapshot
// ---------------------------------------------------------------------------

/**
 * A saved point-in-time copy of a character's full sheet data.
 */
export interface CharacterSnapshot {
	id: number;
	character_id: number;
	snapshot_data: SheetData;
	change_id: number | null;
	created_at: string;
}

/**
 * Query parameters accepted by a character's snapshots collection endpoint.
 */
export interface SnapshotCollectionParams {
	page?: number;
	per_page?: number;
	order?: 'ASC' | 'DESC';
}

// ---------------------------------------------------------------------------
// Sheet Style — one cosmetic override per character
// ---------------------------------------------------------------------------

/**
 * A character's saved cosmetic sheet override, as returned by the API.
 */
export interface SheetStyle {
	id?: number;
	character_id?: number;
	font_family?: string;
	accent_color?: string | null;
	background_color?: string | null;
	text_color?: string | null;
	background_image_id?: number | null;
	background_image_url?: string | null;
	section_graphics?: Record< string, number >;
	section_graphic_urls?: Record< string, string >;
}

/**
 * Request body for saving a character's sheet style override. font_family is required.
 */
export interface SheetStyleInput {
	font_family: string;
	accent_color?: string | null;
	background_color?: string | null;
	text_color?: string | null;
	background_image_id?: number | null;
	section_graphics?: Record< string, number >;
}

// ---------------------------------------------------------------------------
// Bulk XP
// ---------------------------------------------------------------------------

/**
 * Request body for awarding the same amount of XP to a group of characters at once, with a shared reason recorded
 * against each award.
 */
export interface BulkXPRequest {
	character_ids: number[];
	amount: number;
	reason: string;
}

/**
 * Response from a bulk XP award.
 */
export interface BulkXPResponse {
	awarded: number;
	amount: number;
	reason: string;
}

// ---------------------------------------------------------------------------
// A player's own XP request
// ---------------------------------------------------------------------------

/**
 * What a player tells their Storyteller about XP earned outside this chronicle - where, when, and any details -
 * submitted as an ordinary `xp_earn` change's `change_data.request`.
 */
export interface XpRequestDetails {
	where: string;
	date?: string;
	note?: string;
}

// ---------------------------------------------------------------------------
// Apply XP
// ---------------------------------------------------------------------------

/**
 * One character's share of an apply-XP request: a positive amount awards, a negative amount takes XP back.
 */
export interface XpApplyRow {
	character_id: number;
	amount: number;
}

/**
 * Request body for applying a different amount to each of several characters in one call, with one shared reason.
 */
export interface XpApplyRequest {
	reason: string;
	awards: XpApplyRow[];
}

/**
 * One row's own result from an apply-XP request, in request order.
 */
export interface XpApplyResult {
	character_id: number;
	applied: boolean;
	code?: string;
	message?: string;
	amount?: number;
	xp_earned?: number;
	xp_unspent?: number;
}

/**
 * Response from an apply-XP request.
 */
export interface XpApplyResponse {
	reason: string;
	results: XpApplyResult[];
}

// ---------------------------------------------------------------------------
// Bulk resource-pool reset
// ---------------------------------------------------------------------------

/**
 * Request body for resetting one named resource pool's temporary rating back to its permanent one across a group of
 * characters at once.
 */
export interface BulkPoolResetRequest {
	character_ids: number[];
	block_slug: string;
	pool_name: string;
}

/**
 * Response from a bulk pool reset.
 */
export interface BulkPoolResetResponse {
	reset: number;
	block_slug: string;
	pool_name: string;
}

// ---------------------------------------------------------------------------
// Bulk character status
// ---------------------------------------------------------------------------

/**
 * Request body for setting the same status on a group of characters at once.
 */
export interface BulkStatusRequest {
	character_ids: number[];
	status: string;
}

/**
 * One character's own outcome within a bulk-status request.
 */
export interface BulkStatusResult {
	id: number;
	success: boolean;
	error?: string;
}

/**
 * Response from a bulk status update.
 */
export interface BulkStatusResponse {
	results: BulkStatusResult[];
	updated: number;
}

/**
 * Request body for exporting a character to a Grapevine exchange file. hide_st asks for a player's copy.
 */
export interface ExportCharacterOptions {
	hide_st?: boolean;
	verify?: boolean;
}

/**
 * Response from exporting a character: the exchange document text, plus anything the Storyteller should see before
 * relying on it.
 */
export interface ExportCharacterResponse {
	xml: string;
	warnings: string[];
	transliterations: string[];
}

/**
 * One priced or unpriced holding on the point audit.
 */
export interface PointAuditLine {
	block_slug: string;
	section_label: string;
	section_type:
		| 'trait_list'
		| 'tiered_power'
		| 'resource_pool'
		| 'identity_field'
		| null;
	label: string;
	xp: number | null;
	direction: 'spent' | 'earned';
	basis:
		| 'catalog_cost'
		| 'chosen_cost'
		| 'rule_floor'
		| 'flat_level'
		| 'sequential_sum'
		| 'elder_pick'
		| 'tier_fallback'
		| 'innate_free'
		| null;
	unpriced_reason: string | null;
	/**
	 * How much a rank modifier changed this line's cost, signed.
	 */
	modifier: number | null;
	/**
	 * Which side's modifier that was.
	 */
	modifier_side: 'in_type' | 'out_of_type' | null;
	undeclared_by_stack: boolean;
}

/**
 * The point audit envelope.
 */
export interface PointAudit {
	character_id: number;
	lines: PointAuditLine[];
	spent_total: number;
	earned_total: number;
	net_total: number;
	coverage: {
		priced_lines: number;
		unpriced_lines: number;
		unpriced_by_reason: Record< string, number >;
	};
	xp_spent_of_record: number;
	variance: number;
	complete: false;
	caveat: string;
}

/**
 * One section a `prioritized` creation step covered: what it holds against what the step allows.
 */
export interface CreationTallyPrioritizedSection {
	section: string;
	used: number;
	allowed: number;
	over: boolean;
}

/**
 * One `budget` step's named quota, met or short.
 */
export interface CreationTallyQuota {
	label: string;
	min: number;
	met: number;
	ok: boolean;
}

/**
 * One `limit` step's flag: an entry or section over, under, or above another entry's rating.
 */
export interface CreationTallyLimitFlag {
	target: string;
	reason: 'max_points' | 'max_rating' | 'min_rating' | 'ceiling';
	value: number;
	max?: number;
	min?: number;
	ceiling?: number;
	ceiling_target?: string;
}

/**
 * One `grant` step's entry, and, listed under `grants_missing`, one the draft sheet doesn't hold yet.
 */
export interface CreationTallyGrantEntry {
	section: string;
	name: string;
	level?: number;
	count?: number;
	power_name?: string;
}

/**
 * One step of a creation tally, in the book's order. The fields present depend on `kind`.
 */
export type CreationTallyStep =
	| {
			kind: 'prioritized';
			label: string;
			applies: boolean;
			sections: CreationTallyPrioritizedSection[];
	  }
	| {
			kind: 'budget';
			label: string;
			applies: boolean;
			section: string;
			used: number;
			allowed: number;
			over: boolean;
			quotas: CreationTallyQuota[];
	  }
	| {
			kind: 'earned';
			label: string;
			applies: boolean;
			pool: string;
			points: number;
	  }
	| {
			kind: 'free';
			label: string;
			applies: boolean;
			pool: string;
			spent: number;
	  }
	| {
			kind: 'limit';
			label: string;
			applies: boolean;
			flags: CreationTallyLimitFlag[];
	  }
	| {
			kind: 'grant';
			label: string;
			applies: boolean;
			missing: CreationTallyGrantEntry[];
	  }
	| {
			kind: 'start';
			label: string;
			applies: boolean;
			target: string;
			value: number | null;
	  };

/**
 * One named pool a creation tally tracks: its own points, what it earned, what it spent, and what is left.
 */
export interface CreationTallyPool {
	own: number;
	earned: number;
	spent: number;
	left: number;
}

/**
 * The creation tally envelope: every declared step's own report, each pool, every limit flag, missing grants, and
 * the XP the build still needs against the chronicle's starting experience.
 */
export interface CreationTally {
	steps: CreationTallyStep[];
	pools: Record< string, CreationTallyPool >;
	limits: CreationTallyLimitFlag[];
	grants_missing: CreationTallyGrantEntry[];
	xp: {
		starting: number;
		needed: number;
		left: number;
	};
}
