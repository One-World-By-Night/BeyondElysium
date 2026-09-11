/**
 * REST API client for the Beyond Elysium plugin. Wraps
 * WordPress's api-fetch package with one grouped object per
 * resource - games, schema blocks, creature stacks, templates,
 * characters, changes, plots, world objects, queries, and
 * imports - so components call methods instead of building fetch
 * requests.
 */
import apiFetch from '@wordpress/api-fetch';
import type {
    Game,
    SchemaBlock,
    CreatureStack,
    ResolvedStack,
    CreateGameRequest,
    UpdateGameRequest,
    CreateSchemaBlockRequest,
    UpdateSchemaBlockRequest,
    CreateCreatureStackRequest,
    UpdateCreatureStackRequest,
    GameCollectionParams,
    SchemaBlockCollectionParams,
    CreatureStackCollectionParams,
    TemplateResolveResponse,
    Template,
    CreateTemplateRequest,
    UpdateTemplateRequest,
    GameMember,
    GameMemberRole,
    AuthorizationSettings,
    GameStats,
} from '../types';
import type {
    Character,
    CharacterChange,
    CharacterSnapshot,
    CreateCharacterRequest,
    UpdateCharacterRequest,
    ChangeRequest,
    ChangeReviewRequest,
    BulkXPRequest,
    BulkXPResponse,
    CharacterCollectionParams,
    ChangeCollectionParams,
    SnapshotCollectionParams,
    PreviewChangesResponse,
    QueueCollectionParams,
    QueueChange,
    BatchApproveResponse,
    SheetStyle,
    SheetStyleInput,
    WpUserSummary,
} from '../types/character';
import type {
    Plot,
    PlotEntry,
    Connection,
    CreatePlotRequest,
    UpdatePlotRequest,
    PlotCollectionParams,
    MyPlotsResponse,
    CreateEntryRequest,
    CreateConnectionRequest,
    UpdateConnectionRequest,
    AllocateActionsResponse,
    GenerateRumorsResponse,
    EntityType,
} from '../types/plot';
import type {
    QueryField,
    QueryResultCharacter,
    RunQueryRequest,
    RunStatisticsRequest,
    StatisticsResult,
    SavedQuery,
    SaveQueryRequest,
} from '../types/query';
import type {
    WorldObject,
    CreateWorldObjectRequest,
    UpdateWorldObjectRequest,
    WorldObjectCollectionParams,
    Boon,
    CreateBoonRequest,
    BoonLedgerParams,
} from '../types/world';
import type {
    ImportPreview,
    ImportCommitResult,
    ImportResolutions,
    GameImportPreview,
    GameImportTarget,
    GameImportCommitResult,
} from '../types/import';

const BASE = '/be/v1';

/**
 * Converts a plain params object into a URL query string. Skips
 * any key whose value is null, undefined, or an empty string, and
 * URL-encodes every remaining key and value.
 */
function toQuery( params: Record<string, unknown> ): string {
    const parts: string[] = [];
    for ( const [ key, val ] of Object.entries( params ) ) {
        if ( val !== undefined && val !== null && val !== '' ) {
            parts.push( `${ encodeURIComponent( key ) }=${ encodeURIComponent( String( val ) ) }` );
        }
    }
    return parts.length ? '?' + parts.join( '&' ) : '';
}

// ---------------------------------------------------------------------------
// Games
// ---------------------------------------------------------------------------

/**
 * REST client for the games (chronicles) collection: listing,
 * fetching, creating, updating, and deleting a chronicle. Not
 * scoped to any one game, since these operate on the collection
 * itself.
 */
export const games = {
    /**
     * Fetches the list of games matching the given filters.
     * Supports the standard pagination, ordering, and game_type
     * filter from GameCollectionParams.
     */
    list: ( params: GameCollectionParams = {} ): Promise<Game[]> =>
        apiFetch( { path: `${ BASE }/games${ toQuery( params as Record<string, unknown> ) }` } ),

    /**
     * Fetches a single game by its slug. Returns the full Game
     * record, including its settings and authorization fields.
     */
    get: ( slug: string ): Promise<Game> =>
        apiFetch( { path: `${ BASE }/games/${ slug }` } ),

    /**
     * Creates a new game/chronicle from the given request body.
     * Returns the newly created Game record, including its
     * server-assigned id and slug.
     */
    create: ( data: CreateGameRequest ): Promise<Game> =>
        apiFetch( { path: `${ BASE }/games`, method: 'POST', data } ),

    /**
     * Updates an existing game identified by slug with the given
     * partial request body. Returns the updated Game record as
     * stored after the change.
     */
    update: ( slug: string, data: UpdateGameRequest ): Promise<Game> =>
        apiFetch( { path: `${ BASE }/games/${ slug }`, method: 'PUT', data } ),

    /**
     * Deletes a game/chronicle by slug. Resolves with no content
     * on success; the server is responsible for cascading or
     * blocking deletion of dependent records.
     */
    delete: ( slug: string ): Promise<void> =>
        apiFetch( { path: `${ BASE }/games/${ slug }`, method: 'DELETE' } ),
};

// ---------------------------------------------------------------------------
// Schema Blocks
// ---------------------------------------------------------------------------

/**
 * REST client for the schema blocks collection: the reusable
 * sheet-section definitions (trait lists, tiered powers, resource
 * pools, identity fields) that creature stacks are built from.
 */
export const schemaBlocks = {
    /**
     * Fetches the list of schema blocks matching the given
     * filters. Supports the standard collection params plus
     * filtering by section type and the system-block flag.
     */
    list: ( params: SchemaBlockCollectionParams = {} ): Promise<SchemaBlock[]> =>
        apiFetch( { path: `${ BASE }/schema-blocks${ toQuery( params as Record<string, unknown> ) }` } ),

    /**
     * Fetches a single schema block by slug. When gameSlug is
     * given, resolves that chronicle's own customized fork of the
     * block in place of the global one, if it has forked it.
     */
    get: ( slug: string, gameSlug?: string ): Promise<SchemaBlock> =>
        apiFetch( { path: `${ BASE }/schema-blocks/${ slug }${ gameSlug ? `?game_slug=${ encodeURIComponent( gameSlug ) }` : '' }` } ),

    /**
     * Creates a new schema block from the given request body.
     * slug, name, and section_type identify and classify it.
     * Returns the newly created SchemaBlock record.
     */
    create: ( data: CreateSchemaBlockRequest ): Promise<SchemaBlock> =>
        apiFetch( { path: `${ BASE }/schema-blocks`, method: 'POST', data } ),

    /**
     * Updates an existing schema block by slug. When gameSlug is
     * given, auto-forks this chronicle's own copy on save if it
     * does not already have one, rather than requiring a separate
     * fork step first.
     */
    update: ( slug: string, data: UpdateSchemaBlockRequest, gameSlug?: string ): Promise<SchemaBlock> =>
        apiFetch( {
            path: `${ BASE }/schema-blocks/${ slug }${ gameSlug ? `?game_slug=${ encodeURIComponent( gameSlug ) }` : '' }`,
            method: 'PUT',
            data,
        } ),

    /**
     * Deletes a schema block by slug. Resolves with no content on
     * success; the server governs whether blocks still referenced
     * by a creature stack may be deleted.
     */
    delete: ( slug: string ): Promise<void> =>
        apiFetch( { path: `${ BASE }/schema-blocks/${ slug }`, method: 'DELETE' } ),
};

// ---------------------------------------------------------------------------
// Creature Stacks
// ---------------------------------------------------------------------------

/**
 * REST client for the creature stacks collection: the creature
 * types (Vampire, Werewolf, and so on) that define which schema
 * blocks make up a character sheet.
 */
export const creatureStacks = {
    /**
     * Fetches the list of creature stacks matching the given
     * filters. Supports the standard collection params plus
     * filtering by game line and the system-stack flag.
     */
    list: ( params: CreatureStackCollectionParams = {} ): Promise<CreatureStack[]> =>
        apiFetch( { path: `${ BASE }/creature-stacks${ toQuery( params as Record<string, unknown> ) }` } ),

    /**
     * Fetches a single creature stack by slug, without resolving
     * its referenced schema blocks. Use resolve() instead when the
     * actual block definitions are needed.
     */
    get: ( slug: string ): Promise<CreatureStack> =>
        apiFetch( { path: `${ BASE }/creature-stacks/${ slug }` } ),

    /**
     * Fetches a creature stack together with the real SchemaBlock
     * record for every block its sections reference. When gameSlug
     * is given, prefers that chronicle's own customized fork of a
     * block over the global one wherever it has forked it.
     */
    resolve: ( slug: string, gameSlug?: string ): Promise<ResolvedStack> =>
        apiFetch( {
            path: `${ BASE }/creature-stacks/${ slug }?resolve=true${ gameSlug ? `&game_slug=${ encodeURIComponent( gameSlug ) }` : '' }`,
        } ),

    /**
     * Creates a new creature stack from the given request body.
     * Requires a full stack_definition describing its sheet
     * layout. Returns the newly created CreatureStack record.
     */
    create: ( data: CreateCreatureStackRequest ): Promise<CreatureStack> =>
        apiFetch( { path: `${ BASE }/creature-stacks`, method: 'POST', data } ),

    /**
     * Updates an existing creature stack by slug with the given
     * partial request body. Returns the updated CreatureStack
     * record as stored after the change.
     */
    update: ( slug: string, data: UpdateCreatureStackRequest ): Promise<CreatureStack> =>
        apiFetch( { path: `${ BASE }/creature-stacks/${ slug }`, method: 'PUT', data } ),

    /**
     * Deletes a creature stack by slug. Resolves with no content
     * on success; the server governs whether stacks still in use
     * by characters may be deleted.
     */
    delete: ( slug: string ): Promise<void> =>
        apiFetch( { path: `${ BASE }/creature-stacks/${ slug }`, method: 'DELETE' } ),
};

// ---------------------------------------------------------------------------
// Templates (game-scoped resolve)
// ---------------------------------------------------------------------------

/**
 * REST client for the global templates collection: the reusable,
 * non-chronicle-specific sheet layouts a template_type/stack
 * combination can fall back to.
 */
export const templatesGlobal = {
    /**
     * Fetches every global template. Returns the raw Template
     * records, not resolved against any particular stack or
     * chronicle.
     */
    list: (): Promise<Template[]> =>
        apiFetch( { path: `${ BASE }/templates` } ),

    /**
     * Fetches a single global template by its numeric id.
     * Returns the raw Template record as stored, including its
     * full layout.
     */
    get: ( id: number ): Promise<Template> =>
        apiFetch( { path: `${ BASE }/templates/${ id }` } ),

    /**
     * Creates a new global template from the given request body.
     * name, template_type, and layout are required. Returns the
     * newly created Template record.
     */
    create: ( data: CreateTemplateRequest ): Promise<Template> =>
        apiFetch( { path: `${ BASE }/templates`, method: 'POST', data } ),

    /**
     * Updates an existing global template by id with the given
     * partial request body. Returns the updated Template record
     * as stored after the change.
     */
    update: ( id: number, data: UpdateTemplateRequest ): Promise<Template> =>
        apiFetch( { path: `${ BASE }/templates/${ id }`, method: 'PUT', data } ),

    /**
     * Deletes a global template by id. Resolves with no content
     * on success; chronicles that had resolved to this template
     * fall back to the generated default afterward.
     */
    delete: ( id: number ): Promise<void> =>
        apiFetch( { path: `${ BASE }/templates/${ id }`, method: 'DELETE' } ),
};
/**
 * REST client factory for a single chronicle's template
 * resolution. Returns an object bound to gameSlug whose resolve()
 * method finds the right layout for a given stack and template
 * type.
 */
export const templates = ( gameSlug: string ) => ( {
    /**
     * Resolves the sheet layout to use for a given stack and
     * template type within this chronicle. Falls back from a
     * chronicle-specific override, to a global template, to a
     * generated default, in that order.
     */
    resolve: ( stackSlug: string, templateType: string ): Promise<TemplateResolveResponse> =>
        apiFetch( {
            path: `${ BASE }/${ gameSlug }/templates/resolve${ toQuery( {
                stack_slug: stackSlug,
                template_type: templateType,
            } ) }`,
        } ),
} );

// ---------------------------------------------------------------------------
// Approval Rules (game-scoped)
// ---------------------------------------------------------------------------

export interface ApprovalRule {
    id: string;
    block_slug: string;
    block_name: string;
    target_type: 'item' | 'power' | 'level';
    target_name: string;
    level: number | null;
    approval: string | null;
    reason: string | null;
}

export interface ApprovalRuleOptions {
    approval_levels: string[];
    reason_presets: string[];
}

export interface ApprovalRuleRequest {
    block_slug: string;
    target_type: 'item' | 'power' | 'level';
    target_name: string;
    level?: number;
    approval?: string;
    reason?: string;
}

/**
 * REST client factory for a single chronicle's approval rules.
 * Returns an object bound to gameSlug covering listing every rule
 * currently set, the vocabulary the create/edit form offers, and
 * creating, updating, and deleting one rule.
 */
export const approvalRules = ( gameSlug: string ) => ( {
    /** Fetches every approval rule currently set across this chronicle's blocks. */
    list: (): Promise<ApprovalRule[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/approval-rules` } ),

    /** Fetches the fixed approval-level and reason-preset vocabulary the form offers. */
    options: (): Promise<ApprovalRuleOptions> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/approval-rules/options` } ),

    /** Creates (sets) a rule on the named item, power, or power level. */
    create: ( data: ApprovalRuleRequest ): Promise<ApprovalRule> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/approval-rules`, method: 'POST', data } ),

    /** Updates an existing rule, identified by the opaque id list() returned for it. */
    update: ( id: string, data: Partial<ApprovalRuleRequest> ): Promise<ApprovalRule> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/approval-rules/${ id }`, method: 'PUT', data } ),

    /** Clears a rule back to unset; the catalog item, power, or level itself is not removed. */
    remove: ( id: string ): Promise<void> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/approval-rules/${ id }`, method: 'DELETE' } ),
} );

// ---------------------------------------------------------------------------
// Characters (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's characters.
 * Returns an object bound to gameSlug covering listing, fetching,
 * creating, updating, deleting, previewing changes, and the
 * player's own character list.
 */
export const characters = ( gameSlug: string ) => ( {
    /**
     * Fetches the list of characters in this chronicle matching
     * the given filters. Supports the standard pagination,
     * ordering, and status/stack/NPC/search filters.
     */
    list: ( params: CharacterCollectionParams = {} ): Promise<Character[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters${ toQuery( params as Record<string, unknown> ) }` } ),

    /**
     * Same collection as list(), but reads total and totalPages
     * from the X-WP-Total / X-WP-TotalPages response headers
     * instead of assuming the response body carries them, since
     * apiFetch's default parsed-JSON mode discards headers.
     */
    listPaginated: async (
        params: CharacterCollectionParams = {}
    ): Promise<{ items: Character[]; total: number; totalPages: number }> => {
        const response: Response = await apiFetch( {
            path: `${ BASE }/${ gameSlug }/characters${ toQuery( params as Record<string, unknown> ) }`,
            parse: false,
        } );
        const items = ( await response.json() ) as Character[];
        return {
            items,
            total: parseInt( response.headers.get( 'X-WP-Total' ) ?? '0', 10 ),
            totalPages: parseInt( response.headers.get( 'X-WP-TotalPages' ) ?? '0', 10 ),
        };
    },

    /**
     * Fetches a single character by id within this chronicle.
     * Returns the full Character record, including permission
     * flags computed for the current user.
     */
    get: ( id: number ): Promise<Character> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters/${ id }` } ),

    /**
     * Creates a new character in this chronicle from the given
     * request body. name and stack_slug are required. Returns the
     * newly created Character record.
     */
    create: ( data: CreateCharacterRequest ): Promise<Character> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters`, method: 'POST', data } ),

    /**
     * Updates an existing character by id with the given partial
     * request body. Returns the updated Character record as
     * stored after the change.
     */
    update: ( id: number, data: UpdateCharacterRequest ): Promise<Character> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters/${ id }`, method: 'PUT', data } ),

    /**
     * Deletes a character by id. Resolves with no content on
     * success; the server is responsible for cascading removal of
     * the character's own changes and snapshots.
     */
    delete: ( id: number ): Promise<void> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters/${ id }`, method: 'DELETE' } ),

    /**
     * Prices a batch of proposed changes for a character without
     * submitting them. Returns each change's computed XP cost and
     * approval level, plus the resulting running unspent XP total.
     */
    previewChanges: ( id: number, changes: ChangeRequest[] ): Promise<PreviewChangesResponse> =>
        apiFetch( {
            path: `${ BASE }/${ gameSlug }/characters/${ id }/preview-changes`,
            method: 'POST',
            data: { changes },
        } ),

    /**
     * Fetches the characters in this chronicle belonging to the
     * current player. Backs the player dashboard's own "my
     * characters" card.
     */
    myCharacters: (): Promise<Character[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/my/characters` } ),
} );

/**
 * REST client for looking up WordPress user accounts. Not scoped
 * to any one chronicle, since an account is not owned by a
 * chronicle. Backs the "assign a player" account picker.
 */
export const wpUsers = {
    /**
     * Searches WordPress user accounts by display name or email.
     * An empty search returns a default/unfiltered result set.
     * Returns a minimal summary for each matching account.
     */
    search: ( search = '' ): Promise<WpUserSummary[]> =>
        apiFetch( { path: `${ BASE }/wp-users${ toQuery( { search: search || undefined } ) }` } ),
};

// ---------------------------------------------------------------------------
// Chronicle-scoped authorization
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's membership list:
 * which WordPress accounts hold which role (head storyteller,
 * assistant storyteller, narrator, player) within it.
 */
export const gameMembers = ( gameSlug: string ) => ( {
    /**
     * Fetches every member of this chronicle along with their
     * role. name and user_email are enriched server-side for
     * display.
     */
    list: (): Promise<GameMember[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/members` } ),

    /**
     * Adds a WordPress user to this chronicle with the given role,
     * or changes their role if they are already a member. Returns
     * the resulting GameMember record.
     */
    set: ( wpUserId: number, role: GameMemberRole ): Promise<GameMember> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/members`, method: 'POST', data: { wp_user_id: wpUserId, role } } ),

    /**
     * Removes a WordPress user's membership from this chronicle
     * entirely. Resolves with no content on success.
     */
    remove: ( wpUserId: number ): Promise<void> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/members/${ wpUserId }`, method: 'DELETE' } ),
} );

/**
 * REST client for the plugin's site-wide authorization
 * configuration: whether external access-control integration is
 * enabled.
 */
export const authorizationSettings = {
    /**
     * Fetches the current authorization configuration. Reports
     * whether external access-control is enabled and whether a
     * supporting client plugin was detected.
     */
    get: (): Promise<AuthorizationSettings> =>
        apiFetch( { path: `${ BASE }/authorization-settings` } ),

    /**
     * Enables or disables external access-control integration
     * site-wide. Returns the updated AuthorizationSettings record.
     */
    update: ( ascEnabled: boolean ): Promise<AuthorizationSettings> =>
        apiFetch( { path: `${ BASE }/authorization-settings`, method: 'PUT', data: { asc_enabled: ascEnabled } } ),
};

// ---------------------------------------------------------------------------
// Data Management — the uninstall keep/download/delete choice
// ---------------------------------------------------------------------------

export interface DataManagementSettings {
    delete_on_uninstall: boolean;
}

export interface DataExport {
    exported_at: string;
    plugin_version: string | null;
    tables: Record<string, Record<string, unknown>[]>;
}

export const dataManagement = {
    /** Fetches whether uninstalling this plugin is currently set to delete its data. */
    get: (): Promise<DataManagementSettings> =>
        apiFetch( { path: `${ BASE }/data-management` } ),

    /** Turns the uninstall delete-data behavior on or off, site-wide. */
    update: ( deleteOnUninstall: boolean ): Promise<DataManagementSettings> =>
        apiFetch( { path: `${ BASE }/data-management`, method: 'PUT', data: { delete_on_uninstall: deleteOnUninstall } } ),

    /** Fetches a full export of every plugin table, for a manual backup. */
    export: (): Promise<DataExport> =>
        apiFetch( { path: `${ BASE }/data-management/export` } ),
};

// ---------------------------------------------------------------------------
// Docs — the wp-admin "Docs" page's own content source
// ---------------------------------------------------------------------------

/**
 * REST client for the plugin's built-in documentation pages shown
 * in wp-admin.
 */
export const docs = {
    /**
     * Fetches the content of one built-in documentation page by
     * its slug. Returns the page's slug and its rendered content.
     */
    get: ( slug: 'st-guide' | 'admin-guide' | 'player-guide' | 'rest-api' ): Promise<{ slug: string; content: string }> =>
        apiFetch( { path: `${ BASE }/docs/${ slug }` } ),
};

// ---------------------------------------------------------------------------
// Credits — the "Powered by BeyondElysium" footer and its Credits modal
// ---------------------------------------------------------------------------

export interface InMemoriamEntry {
    name: string;
    note?: string;
}

export interface CreditsResponse {
    credits_text: string;
    in_memoriam: InMemoriamEntry[];
}

/**
 * REST client for the plugin's credits text and in-memoriam list,
 * shared site-wide rather than scoped to one chronicle.
 */
export const credits = {
    /** Fetches the current credits text and in-memoriam list. */
    get: (): Promise<CreditsResponse> => apiFetch( { path: `${ BASE }/credits` } ),

    /**
     * Updates the credits text and/or in-memoriam list. Either field
     * may be omitted to leave it unchanged.
     */
    update: ( data: Partial<CreditsResponse> ): Promise<CreditsResponse> =>
        apiFetch( { path: `${ BASE }/credits`, method: 'PUT', data } ),
};

// ---------------------------------------------------------------------------
// Game stats
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's Storyteller
 * dashboard statistics.
 */
export const gameStats = ( gameSlug: string ) => ( {
    /**
     * Fetches the aggregate dashboard numbers for this chronicle:
     * character counts, pending change count, active plot count,
     * and recent activity.
     */
    get: (): Promise<GameStats> => apiFetch( { path: `${ BASE }/${ gameSlug }/stats` } ),
} );

// ---------------------------------------------------------------------------
// Changes (game + character scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's character changes:
 * submitting, reviewing, and listing edits, plus the game-wide
 * approval queue and the player's own pending changes.
 */
export const changes = ( gameSlug: string ) => ( {
    /**
     * Fetches the changes recorded against a single character.
     * Supports the standard pagination and ordering plus filtering
     * by review status or change type.
     */
    list: ( characterId: number, params: ChangeCollectionParams = {} ): Promise<CharacterChange[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters/${ characterId }/changes${ toQuery( params as Record<string, unknown> ) }` } ),

    /**
     * Submits a new change against a character from the given
     * request body. Returns the created CharacterChange record,
     * including its computed status and XP cost.
     */
    create: ( characterId: number, data: ChangeRequest ): Promise<CharacterChange> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters/${ characterId }/changes`, method: 'POST', data } ),

    /**
     * Approves or rejects a pending change by id. Returns the
     * updated CharacterChange record reflecting the reviewer's
     * decision.
     */
    review: ( changeId: number, data: ChangeReviewRequest ): Promise<CharacterChange> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/changes/${ changeId }`, method: 'PUT', data } ),

    /**
     * Fetches the game-wide change approval queue, spanning every
     * character in the chronicle rather than one. Reads total and
     * totalPages from the X-WP-Total / X-WP-TotalPages response
     * headers, the same approach as characters().listPaginated().
     */
    queue: async (
        params: QueueCollectionParams = {}
    ): Promise<{ items: QueueChange[]; total: number; totalPages: number }> => {
        const response: Response = await apiFetch( {
            path: `${ BASE }/${ gameSlug }/changes${ toQuery( params as Record<string, unknown> ) }`,
            parse: false,
        } );
        const items = ( await response.json() ) as QueueChange[];
        return {
            items,
            total: parseInt( response.headers.get( 'X-WP-Total' ) ?? '0', 10 ),
            totalPages: parseInt( response.headers.get( 'X-WP-TotalPages' ) ?? '0', 10 ),
        };
    },

    /**
     * Approves a batch of pending changes by id in one request.
     * Returns which change ids were actually approved and which
     * were skipped.
     */
    batchApprove: ( changeIds: number[] ): Promise<BatchApproveResponse> =>
        apiFetch( {
            path: `${ BASE }/${ gameSlug }/changes/batch-approve`,
            method: 'POST',
            data: { change_ids: changeIds },
        } ),

    /**
     * Fetches the current player's own pending changes across
     * every one of their characters. Unlike queue(), this is
     * unpaginated and open to any player, not just a manager.
     */
    myChanges: (): Promise<QueueChange[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/my/changes` } ),
} );

// ---------------------------------------------------------------------------
// Snapshots (game + character scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's character
 * snapshots: saved point-in-time copies of a character's sheet
 * data.
 */
export const snapshots = ( gameSlug: string ) => ( {
    /**
     * Fetches the list of snapshots saved for a character.
     * Supports the standard pagination and ordering.
     */
    list: ( characterId: number, params: SnapshotCollectionParams = {} ): Promise<CharacterSnapshot[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters/${ characterId }/snapshots${ toQuery( params as Record<string, unknown> ) }` } ),

    /**
     * Fetches a single saved snapshot by id for a character.
     * Returns the full CharacterSnapshot record, including its
     * saved sheet data.
     */
    get: ( characterId: number, snapshotId: number ): Promise<CharacterSnapshot> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters/${ characterId }/snapshots/${ snapshotId }` } ),

    /**
     * Saves a new snapshot of a character's current sheet data.
     * Returns the newly created CharacterSnapshot record.
     */
    create: ( characterId: number ): Promise<CharacterSnapshot> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters/${ characterId }/snapshots`, method: 'POST', data: {} } ),
} );

// ---------------------------------------------------------------------------
// Sheet Style (game + character scoped) — one cosmetic override per character.
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's character sheet
 * style overrides: per-character cosmetic customization such as
 * fonts, colors, and section graphics.
 */
export const sheetStyle = ( gameSlug: string ) => ( {
    /**
     * Fetches a character's saved sheet style override. Returns an
     * empty object when nothing has been customized yet, rather
     * than a 404.
     */
    get: ( characterId: number ): Promise<SheetStyle> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters/${ characterId }/sheet-style` } ),

    /**
     * Saves a character's sheet style override from the given
     * request body. Returns the saved SheetStyle record.
     */
    save: ( characterId: number, data: SheetStyleInput ): Promise<SheetStyle> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters/${ characterId }/sheet-style`, method: 'PUT', data } ),

    /**
     * Clears a character's sheet style override entirely,
     * returning it to the default appearance. Resolves with no
     * content on success.
     */
    reset: ( characterId: number ): Promise<void> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/characters/${ characterId }/sheet-style`, method: 'DELETE' } ),
} );

// ---------------------------------------------------------------------------
// Experience (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's experience-point
 * operations.
 */
export const experience = ( gameSlug: string ) => ( {
    /**
     * Awards the same amount of XP to a group of characters at
     * once, with a shared reason recorded against each award.
     * Returns a summary of the award actually applied.
     */
    bulkAward: ( data: BulkXPRequest ): Promise<BulkXPResponse> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/experience/bulk-award`, method: 'POST', data } ),
} );

// ---------------------------------------------------------------------------
// Plots (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's plots: listing,
 * fetching, creating, updating, and deleting plots, actions, and
 * rumors, plus action allocation and rumor generation.
 */
export const plots = ( gameSlug: string ) => ( {
    /**
     * Fetches the list of plots in this chronicle matching the
     * given filters. Supports the standard pagination, ordering,
     * status/initiator filters, a search term, and a date range.
     */
    list: ( params: PlotCollectionParams = {} ): Promise<Plot[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/plots${ toQuery( params as Record<string, unknown> ) }` } ),

    /**
     * Same collection as list(), but reads total and totalPages
     * from the X-WP-Total / X-WP-TotalPages response headers
     * instead of assuming the response body carries them.
     */
    listPaginated: async (
        params: PlotCollectionParams = {}
    ): Promise<{ items: Plot[]; total: number; totalPages: number }> => {
        const response: Response = await apiFetch( {
            path: `${ BASE }/${ gameSlug }/plots${ toQuery( params as Record<string, unknown> ) }`,
            parse: false,
        } );
        const items = ( await response.json() ) as Plot[];
        return {
            items,
            total: parseInt( response.headers.get( 'X-WP-Total' ) ?? '0', 10 ),
            totalPages: parseInt( response.headers.get( 'X-WP-TotalPages' ) ?? '0', 10 ),
        };
    },

    /**
     * Fetches a single plot, action, or rumor by id. Returns the
     * full Plot record, including its entries, connections, and
     * immediate children.
     */
    get: ( id: number ): Promise<Plot> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/plots/${ id }` } ),

    /**
     * Fetches the plots resolved as relevant to the current
     * player, through both direct connections and target-query
     * matching. Backs the player dashboard's own plot feed.
     */
    myPlots: (): Promise<MyPlotsResponse> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/my/plots` } ),

    /**
     * Creates a new plot, action, or rumor from the given request
     * body. Only title is required. Returns the newly created
     * Plot record.
     */
    create: ( data: CreatePlotRequest ): Promise<Plot> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/plots`, method: 'POST', data } ),

    /**
     * Updates an existing plot, action, or rumor by id with the
     * given partial request body. Returns the updated Plot record
     * as stored after the change.
     */
    update: ( id: number, data: UpdatePlotRequest ): Promise<Plot> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/plots/${ id }`, method: 'PUT', data } ),

    /**
     * Deletes a plot, action, or rumor by id. Resolves with no
     * content on success; the server governs how any child plots
     * are handled.
     */
    delete: ( id: number ): Promise<void> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/plots/${ id }`, method: 'DELETE' } ),

    /**
     * Allocates a character's available actions across their held
     * powers for a given game date. commit false previews the
     * allocation only; commit true actually saves the resulting
     * subactions, optionally under a parent plot.
     */
    allocateActions: (
        characterId: number,
        gameDate: string,
        commit = false,
        parentPlotId?: number
    ): Promise<AllocateActionsResponse> =>
        apiFetch( {
            path: `${ BASE }/${ gameSlug }/plots/allocate-actions`,
            method: 'POST',
            data: { character_id: characterId, game_date: gameDate, commit, parent_plot_id: parentPlotId },
        } ),

    /**
     * Generates candidate rumors for a given game date. commit
     * false previews the candidates only; commit true actually
     * saves them as new Plot records.
     */
    generateRumors: ( gameDate: string, commit = false ): Promise<GenerateRumorsResponse> =>
        apiFetch( {
            path: `${ BASE }/${ gameSlug }/plots/generate-rumors`,
            method: 'POST',
            data: { game_date: gameDate, commit },
        } ),
} );

// ---------------------------------------------------------------------------
// Plot Entries (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's plot entries: the
 * individual timeline entries (actions, responses, notes,
 * resolutions) recorded against a plot.
 */
export const plotEntries = ( gameSlug: string ) => ( {
    /**
     * Fetches the entries recorded against a single plot, action,
     * or rumor, optionally filtered to one entry type.
     */
    list: ( plotId: number, entryType?: string ): Promise<PlotEntry[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/plots/${ plotId }/entries${ toQuery( { entry_type: entryType } ) }` } ),

    /**
     * Creates a new entry against a plot, action, or rumor from
     * the given request body. Returns the newly created PlotEntry
     * record.
     */
    create: ( plotId: number, data: CreateEntryRequest ): Promise<PlotEntry> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/plots/${ plotId }/entries`, method: 'POST', data } ),

    /**
     * Updates an existing entry's content by id. Returns the
     * updated PlotEntry record.
     */
    update: ( id: number, content: string ): Promise<PlotEntry> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/entries/${ id }`, method: 'PUT', data: { content } } ),

    /**
     * Deletes a plot entry by id. Resolves with no content on
     * success.
     */
    delete: ( id: number ): Promise<void> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/entries/${ id }`, method: 'DELETE' } ),
} );

// ---------------------------------------------------------------------------
// Connections (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's connections: links
 * between characters, plots, world objects, and tags.
 */
export const connections = ( gameSlug: string ) => ( {
    /**
     * Fetches connections matching the given source/target filters.
     * Any combination of source and target type/id may be given to
     * narrow the result.
     */
    list: ( params: { source_type?: EntityType; source_id?: number; target_type?: EntityType; target_id?: number } = {} ): Promise<Connection[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/connections${ toQuery( params as Record<string, unknown> ) }` } ),

    /**
     * Fetches every connection involving a single entity, in
     * either direction. A convenience wrapper over list() for the
     * common "show me everything linked to this" case.
     */
    forEntity: ( entityType: EntityType, entityId: number ): Promise<Connection[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/connections${ toQuery( { entity_type: entityType, entity_id: entityId } ) }` } ),

    /**
     * Creates a new connection between two entities from the given
     * request body. Returns the newly created Connection record.
     */
    create: ( data: CreateConnectionRequest ): Promise<Connection> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/connections`, method: 'POST', data } ),

    /**
     * Updates an existing connection's label and/or notes by id.
     * Returns the updated Connection record.
     */
    update: ( id: number, data: UpdateConnectionRequest ): Promise<Connection> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/connections/${ id }`, method: 'PUT', data } ),

    /**
     * Deletes a connection by id. Resolves with no content on
     * success.
     */
    delete: ( id: number ): Promise<void> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/connections/${ id }`, method: 'DELETE' } ),
} );

// ---------------------------------------------------------------------------
// Query fields (global)
// ---------------------------------------------------------------------------

/**
 * REST client for the global list of fields the query builder can
 * search on. Not scoped to a chronicle, since the field catalog is
 * shared across every chronicle.
 */
export const queryFields = {
    /**
     * Fetches the queryable fields for a given inventory (such as
     * characters). Returns each field's key, display title, value
     * type, and whether it is currently mapped to real sheet data.
     */
    list: ( inventory = 'char' ): Promise<QueryField[]> =>
        apiFetch( { path: `${ BASE }/query-fields${ toQuery( { inventory } ) }` } ),
};

// ---------------------------------------------------------------------------
// Query, Statistics and Saved Queries (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's query tool: running
 * ad hoc character queries and statistics, and managing saved
 * queries.
 */
export const query = ( gameSlug: string ) => ( {
    /**
     * Runs a query against this chronicle's characters and returns
     * the matching characters directly.
     */
    run: ( data: RunQueryRequest ): Promise<QueryResultCharacter[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/query`, method: 'POST', data } ),

    /**
     * Same query as run(), but reads total and totalPages from the
     * X-WP-Total / X-WP-TotalPages response headers instead of
     * assuming the response body carries them.
     */
    runPaginated: async (
        data: RunQueryRequest
    ): Promise<{ items: QueryResultCharacter[]; total: number; totalPages: number }> => {
        const response: Response = await apiFetch( {
            path: `${ BASE }/${ gameSlug }/query`, method: 'POST', data, parse: false,
        } );
        const items = ( await response.json() ) as QueryResultCharacter[];
        return {
            items,
            total: parseInt( response.headers.get( 'X-WP-Total' ) ?? '0', 10 ),
            totalPages: parseInt( response.headers.get( 'X-WP-TotalPages' ) ?? '0', 10 ),
        };
    },

    /**
     * Runs a statistics aggregate over the characters matching a
     * set of query conditions. Returns value buckets, the
     * characters in each bucket, and the overall total and maximum.
     */
    statistics: ( data: RunStatisticsRequest ): Promise<StatisticsResult> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/statistics`, method: 'POST', data } ),

    savedQueries: {
        /**
         * Fetches every query this chronicle has saved, including
         * automatically retained recent searches.
         */
        list: (): Promise<SavedQuery[]> =>
            apiFetch( { path: `${ BASE }/${ gameSlug }/queries` } ),

        /**
         * Saves a new query from the given request body. Returns
         * the newly created SavedQuery record.
         */
        create: ( data: SaveQueryRequest ): Promise<SavedQuery> =>
            apiFetch( { path: `${ BASE }/${ gameSlug }/queries`, method: 'POST', data } ),

        /**
         * Updates an existing saved query by id with the given
         * partial request body. Returns the updated SavedQuery
         * record.
         */
        update: ( id: number, data: Partial<SaveQueryRequest> ): Promise<SavedQuery> =>
            apiFetch( { path: `${ BASE }/${ gameSlug }/queries/${ id }`, method: 'PUT', data } ),

        /**
         * Deletes a saved query by id. Resolves with no content on
         * success.
         */
        delete: ( id: number ): Promise<void> =>
            apiFetch( { path: `${ BASE }/${ gameSlug }/queries/${ id }`, method: 'DELETE' } ),
    },
} );

// ---------------------------------------------------------------------------
// World Objects (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's world objects:
 * items, locations, and rotes shared across the chronicle.
 */
export const worldObjects = ( gameSlug: string ) => ( {
    /**
     * Fetches the list of world objects matching the given
     * filters. Supports the standard pagination plus filtering by
     * object type, rarity, and a free-text search.
     */
    list: ( params: WorldObjectCollectionParams = {} ): Promise<WorldObject[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/world-objects${ toQuery( params as Record<string, unknown> ) }` } ),

    /**
     * Same collection as list(), but reads total and totalPages
     * from the X-WP-Total / X-WP-TotalPages response headers
     * instead of assuming the response body carries them.
     */
    listPaginated: async (
        params: WorldObjectCollectionParams = {}
    ): Promise<{ items: WorldObject[]; total: number; totalPages: number }> => {
        const response: Response = await apiFetch( {
            path: `${ BASE }/${ gameSlug }/world-objects${ toQuery( params as Record<string, unknown> ) }`,
            parse: false,
        } );
        const items = ( await response.json() ) as WorldObject[];
        return {
            items,
            total: parseInt( response.headers.get( 'X-WP-Total' ) ?? '0', 10 ),
            totalPages: parseInt( response.headers.get( 'X-WP-TotalPages' ) ?? '0', 10 ),
        };
    },

    /**
     * Fetches a single world object by id. Returns the full
     * WorldObject record, including its connected characters.
     */
    get: ( id: number ): Promise<WorldObject> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/world-objects/${ id }` } ),

    /**
     * Creates a new world object from the given request body.
     * Returns the newly created WorldObject record.
     */
    create: ( data: CreateWorldObjectRequest ): Promise<WorldObject> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/world-objects`, method: 'POST', data } ),

    /**
     * Updates an existing world object by id with the given
     * partial request body. Returns the updated WorldObject record
     * as stored after the change.
     */
    update: ( id: number, data: UpdateWorldObjectRequest ): Promise<WorldObject> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/world-objects/${ id }`, method: 'PUT', data } ),

    /**
     * Deletes a world object by id. Resolves with no content on
     * success.
     */
    delete: ( id: number ): Promise<void> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/world-objects/${ id }`, method: 'DELETE' } ),
} );

// ---------------------------------------------------------------------------
// Boons / Harpy Ledger (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's boons: the harpy
 * ledger of favors owed between characters.
 */
export const boons = ( gameSlug: string ) => ( {
    /**
     * Fetches the boon ledger matching the given filters. Supports
     * filtering by the character involved, boon level, and
     * repayment status.
     */
    ledger: ( params: BoonLedgerParams = {} ): Promise<Boon[]> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/boons${ toQuery( params as Record<string, unknown> ) }` } ),

    /**
     * Records a new boon between two characters from the given
     * request body. Returns the newly created Boon record.
     */
    create: ( data: CreateBoonRequest ): Promise<Boon> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/boons`, method: 'POST', data } ),

    /**
     * Marks a boon as repaid by id. Returns the updated world
     * object record reflecting the new repayment status.
     */
    repay: ( id: number ): Promise<WorldObject> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/boons/${ id }/repay`, method: 'PUT', data: {} } ),
} );

// ---------------------------------------------------------------------------
// Import (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's Grapevine exchange
 * file imports: uploading a file, polling job status, and
 * committing the resolved result.
 */
export const gexImport = ( gameSlug: string ) => ( {
    /**
     * Uploads a Grapevine exchange file for parsing. Sends a real
     * multipart FormData body rather than JSON, since apiFetch's
     * data option always JSON-encodes. Returns the initial parsed
     * preview.
     */
    parse: ( file: File ): Promise<ImportPreview> => {
        const body = new FormData();
        body.append( 'file', file );
        return apiFetch( { path: `${ BASE }/${ gameSlug }/import/parse`, method: 'POST', body } );
    },

    /**
     * Fetches the current preview for a previously started import
     * job by id. Returns the same shape as parse(), reflecting the
     * job's latest state.
     */
    getJob: ( jobId: string ): Promise<ImportPreview> =>
        apiFetch( { path: `${ BASE }/${ gameSlug }/import/${ jobId }` } ),

    /**
     * Commits a previewed import job, applying the given
     * resolutions for any duplicates and flagged or unresolved
     * traits. Returns a summary of what was actually written.
     */
    commit: ( jobId: string, resolutions?: ImportResolutions ): Promise<ImportCommitResult> =>
        apiFetch( {
            path: `${ BASE }/${ gameSlug }/import/${ jobId }/commit`,
            method: 'POST',
            data: { resolutions: resolutions ?? {} },
        } ),
} );

// ---------------------------------------------------------------------------
// Game file (.gv3, GVBG) import — non-game-scoped
// ---------------------------------------------------------------------------

/**
 * REST client factory for full game file (.gv3, GVBG) imports:
 * uploading a file, polling job status, and committing it as a
 * new or merged chronicle. Not scoped to any existing chronicle,
 * since a new one may be created by the import itself.
 */
export const gameImport = () => ( {
    /**
     * Uploads a game file for parsing. Sends a real multipart
     * FormData body rather than JSON. Returns the initial parsed
     * preview, including the source chronicle's title.
     */
    parse: ( file: File ): Promise<GameImportPreview> => {
        const body = new FormData();
        body.append( 'file', file );
        return apiFetch( { path: `${ BASE }/import/game/parse`, method: 'POST', body } );
    },

    /**
     * Fetches the current preview for a previously started game
     * import job by id. When target names a candidate chronicle,
     * refines the preview's duplicate lists against it; omitted
     * shows no duplicates yet, since no merge target is chosen.
     */
    getJob: ( jobId: string, target?: string ): Promise<GameImportPreview> =>
        apiFetch( { path: `${ BASE }/import/game/${ jobId }${ target ? `?target=${ encodeURIComponent( target ) }` : '' }` } ),

    /**
     * Commits a previewed game import job to the given target,
     * either as a new chronicle or merged into an existing one,
     * applying the given duplicate/trait resolutions. Returns a
     * summary of what was written and the resulting chronicle.
     */
    commit: ( jobId: string, target: GameImportTarget, resolutions?: ImportResolutions ): Promise<GameImportCommitResult> =>
        apiFetch( {
            path: `${ BASE }/import/game/${ jobId }/commit`,
            method: 'POST',
            data: { target, resolutions: resolutions ?? {} },
        } ),
} );

// ---------------------------------------------------------------------------
// Default export: grouped API object
// ---------------------------------------------------------------------------

/**
 * The full REST API client, grouping every resource's client
 * object under one default export for convenient single-import
 * usage throughout the plugin.
 */
const api = {
    games,
    schemaBlocks,
    creatureStacks,
    templates,
    templatesGlobal,
    approvalRules,
    characters,
    changes,
    snapshots,
    sheetStyle,
    experience,
    plots,
    plotEntries,
    connections,
    queryFields,
    query,
    worldObjects,
    boons,
    gexImport,
    gameImport,
    wpUsers,
    gameMembers,
    authorizationSettings,
    dataManagement,
    gameStats,
    docs,
    credits,
};
export default api;
