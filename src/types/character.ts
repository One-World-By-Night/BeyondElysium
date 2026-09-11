/**
 * Type definitions for player and NPC characters and their edit
 * history. Covers the Character record itself, the request and
 * response shapes for the characters REST endpoints, character
 * changes and the approval queue, snapshots, and per-character
 * sheet style overrides.
 */
import type { ApprovalLevel } from './index';

/**
 * A character's full set of sheet block values, keyed by block
 * slug. Each value's shape depends on the section type of that
 * block, so the value type is intentionally left unstructured.
 */
export type SheetData = Record<string, unknown>;

/**
 * The lifecycle state of a submitted character change. A change
 * starts out pending and is then moved to approved or rejected
 * by a reviewer.
 */
export type ChangeStatus = 'pending' | 'approved' | 'rejected';

// ---------------------------------------------------------------------------
// Character
// ---------------------------------------------------------------------------

/**
 * A single player or NPC character record as stored and returned
 * by the characters REST endpoints. Combines fixed identity and
 * status fields with the free-form sheet_data blob holding every
 * game-specific trait and resource value.
 */
export interface Character {
    /** Local auto-increment id, valid only on this WordPress installation. */
    id: number;
    /** Permanent identity that survives transfers between installations; use instead of `id` for cross-site references. */
    uuid: string;
    name: string;
    stack_slug: string;
    owner_type: string;
    owner_slug: string;
    wp_user_id: number | null;
    /** Player's display name; mirrors the linked account's name once wp_user_id is set, otherwise freely editable text. */
    player_name: string | null;
    /** Email address for a not-yet-created account this character is meant to be linked to. */
    pending_player_email?: string | null;
    /** A matching real WP account found server-side, awaiting manager confirmation before linking. */
    pending_match?: { id: number; display_name: string } | null;
    status: 'active' | 'inactive' | 'retired' | 'dead' | 'pending' | string;
    is_npc: 0 | 1;
    narrator: string | null;
    start_date: string | null;
    xp_earned: number;
    xp_unspent: number;
    biography: string | null;
    notes: string | null;
    /** Only present for users with the be_manage_characters capability. */
    rp_notes?: string | null;
    /** WP attachment id for the character's portrait image; image_url is resolved from it server-side. */
    image_id?: number | null;
    image_url?: string | null;
    /** Whether the current user may edit this character; present only on the single-character fetch. */
    can_edit?: boolean;
    /** Whether the current user holds the be_manage_characters capability; present only on the single-character fetch. */
    can_manage?: boolean;
    /** Whether the current user may customize this character's sheet style; its own be_customize_sheet capability, not derived from can_manage. */
    can_customize_sheet?: boolean;
    sheet_data: SheetData;
    created_by: number;
    created_at: string;
    updated_at: string;
}

/**
 * Request body for creating a new character via the characters
 * collection endpoint. Only name and stack_slug are required;
 * every other field is optional and takes a server-side default
 * when omitted.
 */
export interface CreateCharacterRequest {
    name: string;
    stack_slug: string;
    wp_user_id?: number;
    player_name?: string;
    status?: string;
    is_npc?: boolean;
    narrator?: string;
    start_date?: string;
    biography?: string;
    notes?: string;
    rp_notes?: string;
    sheet_data?: SheetData;
}

/**
 * Request body for updating an existing character. Every field
 * is optional and only the fields included are changed. Most
 * fields simply skip on omission; wp_user_id and
 * pending_player_email each define their own clear/unassign rule.
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
    image_id?: number | null;
    /** null/0 unassigns; omitting the field entirely leaves it untouched. */
    wp_user_id?: number | null;
    /** Empty string clears it; omitting the field entirely leaves it untouched. */
    pending_player_email?: string;
}

/**
 * A minimal summary of a WordPress user account. Used to present
 * a picker of candidate accounts to link to a character, and to
 * display which account a character is already linked to.
 */
export interface WpUserSummary {
    id: number;
    display_name: string;
    email: string;
}

/**
 * Query parameters accepted by the characters collection endpoint.
 * Supports pagination, ordering by any of the listed fields, and
 * filtering by status, stack, NPC flag, or a free-text search term.
 */
export interface CharacterCollectionParams {
    page?: number;
    per_page?: number;
    orderby?: 'name' | 'status' | 'created_at' | 'updated_at' | 'xp_earned' | 'xp_unspent' | 'stack_slug' | 'player_name';
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
 * The kind of edit a character change represents: adding,
 * removing, or modifying a trait; adjusting a resource pool or
 * identity field; earning or spending XP; or recording an import
 * note.
 */
export type ChangeType =
    | 'add_trait'
    | 'remove_trait'
    | 'modify_trait'
    | 'modify_resource'
    | 'modify_identity'
    | 'xp_earn'
    | 'xp_adjust'
    | 'import_note';

/**
 * The data carried by a single character change. Its shape
 * depends on the owning change's change_type, so it is
 * intentionally left unstructured here rather than modeled as a
 * discriminated union.
 */
export type ChangePayload = Record<string, unknown>;

/**
 * A single recorded change to a character's sheet, including its
 * XP cost, review status, and who submitted and reviewed it. This
 * is the core record stored per edit and returned by the changes
 * endpoints.
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
    notes: string | null;
    /** Citation naming the real-world approval authority, set at submission time. */
    reason: string | null;
}

/**
 * Request body for submitting a new character change. Identifies
 * the kind of change and its category, carries the change-specific
 * payload, and optionally overrides the XP cost and records
 * reviewer-facing notes.
 */
export interface ChangeRequest {
    change_type: ChangeType;
    category: string;
    change_data: ChangePayload;
    xp_cost?: number;
    notes?: string;
}

/**
 * Request body for approving or rejecting a pending change. The
 * status field records the reviewer's decision, and notes
 * optionally records the reviewer's reasoning.
 */
export interface ChangeReviewRequest {
    status: 'approved' | 'rejected';
    notes?: string;
}

/**
 * Query parameters accepted by a character's changes collection
 * endpoint. Supports pagination and ordering, and filtering by
 * review status or change type.
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
 * Extends the per-character change filters with an optional
 * character_id, since the queue spans every character in a game
 * rather than being scoped to one.
 */
export interface QueueCollectionParams extends ChangeCollectionParams {
    character_id?: number;
}

/**
 * A single row in the game-wide approval queue. Extends
 * CharacterChange with the owning character's name and its
 * currently computed approval level, so the queue can render both
 * without a second lookup per row.
 */
export interface QueueChange extends CharacterChange {
    character_name: string | null;
    approval_level: ApprovalLevel;
}

/**
 * A single row in a game's recent activity feed. Extends
 * CharacterChange with the owning character's name, covering
 * changes that have already been approved or rejected.
 */
export interface ActivityChange extends CharacterChange {
    character_name: string | null;
}

/**
 * Response from a batch-approve request. Lists which change ids
 * were successfully approved and which were skipped, so the
 * caller can reconcile its local queue state.
 */
export interface BatchApproveResponse {
    approved: number[];
    skipped: number[];
}

// ---------------------------------------------------------------------------
// Preview
// ---------------------------------------------------------------------------

/**
 * The priced outcome for one proposed change, in the same order
 * as the request array it was computed from. Reports what the
 * change would cost and what approval level it would require,
 * without actually submitting it.
 */
export interface ChangePreviewResult {
    xp_cost: number;
    approval_level: ApprovalLevel;
    /** Citation naming the real-world approval authority, when the matched rule carries one. */
    approval_reason: string | null;
}

/**
 * Request body for previewing a batch of proposed changes before
 * submitting them. Carries the same change shapes accepted by the
 * real submit endpoint.
 */
export interface PreviewChangesRequest {
    changes: ChangeRequest[];
}

/**
 * Response from previewing a batch of proposed changes. Lists the
 * priced outcome of each change alongside the character's running
 * unspent XP total after applying every proposed cost.
 */
export interface PreviewChangesResponse {
    results: ChangePreviewResult[];
    /** Character's xp_unspent after applying every proposed change's cost. */
    running_xp_unspent: number;
}

// ---------------------------------------------------------------------------
// Snapshot
// ---------------------------------------------------------------------------

/**
 * A saved point-in-time copy of a character's full sheet data.
 * Snapshots let a Storyteller or player compare or restore an
 * earlier version of a character's sheet.
 */
export interface CharacterSnapshot {
    id: number;
    character_id: number;
    snapshot_data: SheetData;
    change_id: number | null;
    created_at: string;
}

/**
 * Query parameters accepted by a character's snapshots collection
 * endpoint. Supports pagination and ordering of the returned
 * snapshot list.
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
 * A character's saved cosmetic sheet override, as returned by the
 * API. An empty object means no override has been saved yet and
 * the sheet uses its default appearance; this is returned instead
 * of a 404 when nothing has been customized.
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
    section_graphics?: Record<string, number>;
    section_graphic_urls?: Record<string, string>;
}

/**
 * Request body for saving a character's sheet style override.
 * font_family is required; every color and graphic field is
 * optional and left unchanged when omitted.
 */
export interface SheetStyleInput {
    font_family: string;
    accent_color?: string | null;
    background_color?: string | null;
    text_color?: string | null;
    background_image_id?: number | null;
    section_graphics?: Record<string, number>;
}

// ---------------------------------------------------------------------------
// Bulk XP
// ---------------------------------------------------------------------------

/**
 * Request body for awarding the same amount of XP to a group of
 * characters at once, with a shared reason recorded against each
 * award.
 */
export interface BulkXPRequest {
    character_ids: number[];
    amount: number;
    reason: string;
}

/**
 * Response from a bulk XP award. Reports how many characters were
 * awarded XP, the amount each received, and the reason recorded
 * against the award.
 */
export interface BulkXPResponse {
    awarded: number;
    amount: number;
    reason: string;
}
