/**
 * REST API client for the Beyond Elysium plugin.
 */
import apiFetch from '@wordpress/api-fetch';
import type {
	Game,
	ChronicleContentCounts,
	MyGame,
	MyCapabilities,
	SchemaBlock,
	CreatureStack,
	ResolvedStack,
	CreateGameRequest,
	UpdateGameRequest,
	UpdateChronicleSetupRequest,
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
	PlayerWithoutActiveCharacter,
	SetupStatus,
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
	BulkPoolResetRequest,
	BulkPoolResetResponse,
	BulkStatusRequest,
	BulkStatusResponse,
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
	ExportCharacterOptions,
	ExportCharacterResponse,
	PointAudit,
	NpcProfile,
	UpdateNpcProfileRequest,
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
	EntryAudienceValue,
	CharacterOption,
} from '../types/plot';
import type { Attachment, AttachmentEntityType } from '../types/attachment';
import type {
	GameSession,
	SessionAttendance,
	CreateSessionRequest,
	UpdateSessionRequest,
	RecordAttendanceRequest,
	AwardAttendanceXpResponse,
	SessionSettings,
	SessionSettingsResponse,
	AfterGameReport,
	AfterGameReportRequest,
	AwardReportXpResponse,
	SpotlightRow,
} from '../types/session';
import type {
	ReleaseBatch,
	CreateReleaseBatchRequest,
	UpdateReleaseBatchRequest,
	ReleaseBatchItems,
	ReleaseNowSingleRequest,
} from '../types/releaseBatch';
import type { DowntimeQueueRow } from '../types/downtime';
import type {
	StaffQueue,
	StaffMember,
	StaffQueueCastingRow,
} from '../types/staffQueue';
import type {
	NpcCasting,
	CreateNpcCastingRequest,
	UpdateNpcCastingRequest,
	EligibleMember,
	CastingBriefDocument,
} from '../types/npcCasting';
import type {
	Secret,
	SecretEntityType,
	CreateSecretRequest,
	UpdateSecretRequest,
	SecretReveal,
	CreateSecretRevealRequest,
	MySecretRow,
} from '../types/secret';
import type {
	Faction,
	FactionRequest,
	FactionMember,
	FactionMemberCandidate,
	Position,
	PositionRequest,
	PositionHistoryRow,
	PositionPresets,
} from '../types/faction';
import type {
	AprSettings,
	AprSettingsRequest,
	AprBackgroundOption,
	SpendableBackground,
	BackgroundUse,
	RecordBackgroundUseRequest,
	UpdateBackgroundUseRequest,
} from '../types/apr';
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
	CopyForCharacterRequest,
	ItemEvent,
	TransferItemRequest,
	UseItemRequest,
	Boon,
	CreateBoonRequest,
	BoonLedgerParams,
	LocationLink,
	LocationLinkLabel,
} from '../types/world';
import type {
	ImportPreview,
	ImportCommitResult,
	ImportResolutions,
	GameImportPreview,
	GameImportTarget,
	GameImportCommitResult,
} from '../types/import';
import type { VerifyResponse } from '../types/verify';
import type {
	Transfer,
	InitiateTransferResponse,
	TransferReview,
	TransferAcceptResult,
} from '../types/transfer';
import type {
	Submission,
	SubmissionPreviewResponse,
	SubmissionReview,
	SubmissionAcceptResult,
	SubmissionVerification,
} from '../types/submission';

const BASE = '/be/v1';

/**
 * Converts a plain params object into a URL query string.
 */
function toQuery( params: Record< string, unknown > ): string {
	const parts: string[] = [];
	for ( const [ key, val ] of Object.entries( params ) ) {
		if ( val !== undefined && val !== null && val !== '' ) {
			parts.push(
				`${ encodeURIComponent( key ) }=${ encodeURIComponent(
					String( val )
				) }`
			);
		}
	}
	return parts.length ? '?' + parts.join( '&' ) : '';
}

/**
 * Fetches one page of a list route along with its totals.
 */
async function fetchPage< T >( options: {
	path: string;
	method?: string;
	data?: unknown;
} ): Promise< { items: T[]; total: number; totalPages: number } > {
	let response: Response;
	try {
		response = await apiFetch( { ...options, parse: false } );
	} catch ( error: unknown ) {
		if (
			error !== null &&
			typeof error === 'object' &&
			typeof ( error as Response ).json === 'function'
		) {
			const body = await ( error as Response )
				.json()
				.catch( () => undefined );
			throw body ?? error;
		}
		throw error;
	}
	const items = ( await response.json() ) as T[];
	return {
		items,
		total: parseInt( response.headers.get( 'X-WP-Total' ) ?? '0', 10 ),
		totalPages: parseInt(
			response.headers.get( 'X-WP-TotalPages' ) ?? '0',
			10
		),
	};
}

// ---------------------------------------------------------------------------
// Games
// ---------------------------------------------------------------------------

/**
 * REST client for the games (chronicles) collection: listing, fetching, creating, updating, and deleting a chronicle.
 */
export const games = {
	/**
	 * Fetches the list of games matching the given filters.
	 */
	list: ( params: GameCollectionParams = {} ): Promise< Game[] > =>
		apiFetch( {
			path: `${ BASE }/games${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Fetches a single game by its slug.
	 */
	get: ( slug: string ): Promise< Game > =>
		apiFetch( { path: `${ BASE }/games/${ slug }` } ),

	/**
	 * Creates a new game/chronicle from the given request body.
	 */
	create: ( data: CreateGameRequest ): Promise< Game > =>
		apiFetch( { path: `${ BASE }/games`, method: 'POST', data } ),

	/**
	 * Updates an existing game identified by slug with the given partial request body.
	 */
	update: ( slug: string, data: UpdateGameRequest ): Promise< Game > =>
		apiFetch( { path: `${ BASE }/games/${ slug }`, method: 'PUT', data } ),

	/**
	 * Saves the three Chronicle Setup settings an HST may set for their own chronicle: creature types, sub-faction
	 * restrictions, and new-character approval.
	 */
	updateChronicleSetup: (
		gameSlug: string,
		data: UpdateChronicleSetupRequest
	): Promise< Game > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/chronicle-setup`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Counts everything deleting a chronicle would delete with it.
	 */
	contentCounts: ( slug: string ): Promise< ChronicleContentCounts > =>
		apiFetch( { path: `${ BASE }/games/${ slug }/content` } ),

	/**
	 * Deletes a game/chronicle by slug.
	 */
	delete: ( slug: string, withContent = false ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/games/${ slug }${
				withContent ? '?with_content=1' : ''
			}`,
			method: 'DELETE',
		} ),

	/**
	 * Fetches every chronicle the current user actually holds a real membership row in, each with the role they hold
	 * there.
	 */
	mine: (): Promise< MyGame[] > => apiFetch( { path: `${ BASE }/my/games` } ),

	/**
	 * Fetches what the current user can actually do in one specific chronicle, resolved per chronicle.
	 */
	myCapabilities: (
		slug: string
	): Promise< { capabilities: MyCapabilities; accent_color: string } > =>
		apiFetch( { path: `${ BASE }/${ slug }/my/capabilities` } ),

	/**
	 * Fetches the site-wide brand accent default.
	 */
	getBranding: (): Promise< { accent_color: string } > =>
		apiFetch( { path: `${ BASE }/branding` } ),

	/**
	 * Sets the site-wide brand accent default.
	 */
	updateBranding: (
		accentColor: string
	): Promise< { accent_color: string } > =>
		apiFetch( {
			path: `${ BASE }/branding`,
			method: 'PUT',
			data: { accent_color: accentColor },
		} ),
};

// ---------------------------------------------------------------------------
// Schema Blocks
// ---------------------------------------------------------------------------

/**
 * REST client for the schema blocks collection: the reusable sheet-section definitions (trait lists, tiered powers,
 * resource pools, identity fields) that creature stacks are built from.
 */
export const schemaBlocks = {
	/**
	 * Fetches the list of schema blocks matching the given filters.
	 */
	list: (
		params: SchemaBlockCollectionParams = {}
	): Promise< SchemaBlock[] > =>
		apiFetch( {
			path: `${ BASE }/schema-blocks${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Same collection as list(), with its totals.
	 */
	listPaginated: (
		params: SchemaBlockCollectionParams = {}
	): Promise< { items: SchemaBlock[]; total: number; totalPages: number } > =>
		fetchPage< SchemaBlock >( {
			path: `${ BASE }/schema-blocks${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Fetches a single schema block by slug.
	 */
	get: ( slug: string, gameSlug?: string ): Promise< SchemaBlock > =>
		apiFetch( {
			path: `${ BASE }/schema-blocks/${ slug }${
				gameSlug ? `?game_slug=${ encodeURIComponent( gameSlug ) }` : ''
			}`,
		} ),

	/**
	 * Creates a new schema block from the given request body. slug, name, and section_type identify and classify it.
	 */
	create: (
		data: CreateSchemaBlockRequest,
		gameSlug?: string
	): Promise< SchemaBlock > =>
		apiFetch( {
			path: `${ BASE }/${
				gameSlug ? `${ gameSlug }/` : ''
			}schema-blocks`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates an existing schema block by slug.
	 */
	update: (
		slug: string,
		data: UpdateSchemaBlockRequest,
		gameSlug?: string
	): Promise< SchemaBlock > =>
		apiFetch( {
			path: `${ BASE }/${
				gameSlug ? `${ gameSlug }/` : ''
			}schema-blocks/${ slug }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a schema block by slug.
	 */
	delete: ( slug: string, gameSlug?: string ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${
				gameSlug ? `${ gameSlug }/` : ''
			}schema-blocks/${ slug }`,
			method: 'DELETE',
		} ),
};

// ---------------------------------------------------------------------------
// Creature Stacks
// ---------------------------------------------------------------------------

/**
 * REST client for the creature stacks collection: the creature types (Vampire, Werewolf, and so on) that define which
 * schema blocks make up a character sheet.
 */
export const creatureStacks = {
	/**
	 * Fetches the list of creature stacks matching the given filters.
	 */
	list: (
		params: CreatureStackCollectionParams = {}
	): Promise< CreatureStack[] > =>
		apiFetch( {
			path: `${ BASE }/creature-stacks${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Fetches a single creature stack by slug, without resolving its referenced schema blocks.
	 */
	get: ( slug: string ): Promise< CreatureStack > =>
		apiFetch( { path: `${ BASE }/creature-stacks/${ slug }` } ),

	/**
	 * Fetches a creature stack together with the real SchemaBlock record for every block its sections reference.
	 */
	resolve: (
		slug: string,
		gameSlug?: string,
		forCreation?: boolean
	): Promise< ResolvedStack > =>
		apiFetch( {
			path: `${ BASE }/creature-stacks/${ slug }?resolve=true${
				gameSlug ? `&game_slug=${ encodeURIComponent( gameSlug ) }` : ''
			}${ forCreation ? '&for_creation=true' : '' }`,
		} ),

	/**
	 * Creates a new creature stack from the given request body.
	 */
	create: ( data: CreateCreatureStackRequest ): Promise< CreatureStack > =>
		apiFetch( { path: `${ BASE }/creature-stacks`, method: 'POST', data } ),

	/**
	 * Updates an existing creature stack by slug with the given partial request body.
	 */
	update: (
		slug: string,
		data: UpdateCreatureStackRequest
	): Promise< CreatureStack > =>
		apiFetch( {
			path: `${ BASE }/creature-stacks/${ slug }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a creature stack by slug.
	 */
	delete: ( slug: string ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/creature-stacks/${ slug }`,
			method: 'DELETE',
		} ),
};

// ---------------------------------------------------------------------------
// Templates (game-scoped resolve)
// ---------------------------------------------------------------------------

/**
 * REST client for the global templates collection: the reusable, non-chronicle-specific sheet layouts.
 */
export const templatesGlobal = {
	/**
	 * Fetches every global template.
	 */
	list: ( params: { per_page?: number } = {} ): Promise< Template[] > =>
		apiFetch( {
			path: `${ BASE }/templates${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Fetches a single global template by its numeric id.
	 */
	get: ( id: number ): Promise< Template > =>
		apiFetch( { path: `${ BASE }/templates/${ id }` } ),

	/**
	 * Creates a new global template from the given request body. name, template_type, and layout are required.
	 */
	create: ( data: CreateTemplateRequest ): Promise< Template > =>
		apiFetch( { path: `${ BASE }/templates`, method: 'POST', data } ),

	/**
	 * Updates an existing global template by id with the given partial request body.
	 */
	update: ( id: number, data: UpdateTemplateRequest ): Promise< Template > =>
		apiFetch( {
			path: `${ BASE }/templates/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a global template by id.
	 */
	delete: ( id: number ): Promise< void > =>
		apiFetch( { path: `${ BASE }/templates/${ id }`, method: 'DELETE' } ),
};
/**
 * REST client factory for a single chronicle's template resolution.
 */
export const templates = ( gameSlug: string ) => ( {
	/**
	 * Fetches this chronicle's own templates.
	 */
	list: ( params: { per_page?: number } = {} ): Promise< Template[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/templates${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Creates a template for this chronicle only. name, template_type, and layout are required.
	 */
	create: ( data: CreateTemplateRequest ): Promise< Template > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/templates`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates one of this chronicle's own templates by id.
	 */
	update: ( id: number, data: UpdateTemplateRequest ): Promise< Template > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/templates/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes one of this chronicle's own templates.
	 */
	delete: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/templates/${ id }`,
			method: 'DELETE',
		} ),

	/**
	 * Resolves the sheet layout to use for a given stack and template type within this chronicle.
	 */
	resolve: (
		stackSlug: string,
		templateType: string
	): Promise< TemplateResolveResponse > =>
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

export type ApprovalRuleTargetType =
	| 'item'
	| 'power'
	| 'level'
	| 'item_range'
	| 'pool_range'
	| 'field_option';

export interface ApprovalRule {
	id: string;
	block_slug: string;
	block_name: string;
	target_type: ApprovalRuleTargetType;
	target_name: string;
	level: number | null;
	/**
	 * item_range/pool_range: [from, to]. field_option: the option string.
	 */
	extra: [ number, number ] | string | null;
	approval: string | null;
	reason: string | null;
}

export interface ApprovalRuleOptions {
	approval_levels: string[];
	reason_presets: string[];
}

export interface ApprovalRuleRequest {
	block_slug: string;
	target_type: ApprovalRuleTargetType;
	target_name: string;
	level?: number;
	/**
	 * Required for item_range/pool_range targets.
	 */
	from?: number;
	/**
	 * Required for item_range/pool_range targets.
	 */
	to?: number;
	/**
	 * Required for field_option targets.
	 */
	option?: string;
	approval?: string;
	reason?: string;
}

/**
 * REST client factory for a single chronicle's approval rules.
 */
export const approvalRules = ( gameSlug: string ) => ( {
	/**
	 * Fetches every approval rule currently set across this chronicle's blocks.
	 */
	list: (): Promise< ApprovalRule[] > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/approval-rules` } ),

	/**
	 * Fetches the fixed approval-level and reason-preset vocabulary the form offers.
	 */
	options: (): Promise< ApprovalRuleOptions > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/approval-rules/options` } ),

	/**
	 * Fetches whether a change no rule has an opinion on is approved automatically.
	 */
	defaultPolicy: (): Promise< { auto_approve: boolean } > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/approval-rules/default` } ),

	/**
	 * Sets the chronicle's default approval policy.
	 */
	setDefaultPolicy: (
		autoApprove: boolean
	): Promise< { auto_approve: boolean } > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/approval-rules/default`,
			method: 'PUT',
			data: { auto_approve: autoApprove },
		} ),

	/**
	 * Creates (sets) a rule on the named item, power, or power level.
	 */
	create: ( data: ApprovalRuleRequest ): Promise< ApprovalRule > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/approval-rules`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates an existing rule, identified by the opaque id list() returned for it.
	 */
	update: (
		id: string,
		data: Partial< ApprovalRuleRequest >
	): Promise< ApprovalRule > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/approval-rules/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Clears a rule back to unset.
	 */
	remove: ( id: string ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/approval-rules/${ id }`,
			method: 'DELETE',
		} ),
} );

// ---------------------------------------------------------------------------
// AI Assist
// ---------------------------------------------------------------------------

export interface AiAssistGenerateRequest {
	field_context: string;
	current_text: string;
	instruction: string;
}

export interface AiAssistGenerateResponse {
	suggestion: string;
}

export interface AiAssistSiteSettings {
	provider: 'openai' | 'claude';
	has_openai_key: boolean;
	has_claude_key: boolean;
	/**
	 * A self-hosted or otherwise compatible endpoint override.
	 */
	openai_base_url: string;
	openai_model: string;
	claude_base_url: string;
	claude_model: string;
}

export interface AiAssistChronicleSettings {
	enabled: boolean;
	provider: 'openai' | 'claude';
	has_openai_key: boolean;
	has_claude_key: boolean;
	openai_base_url: string;
	openai_model: string;
	claude_base_url: string;
	claude_model: string;
}

export interface AiAssistTestRequest {
	provider: 'openai' | 'claude';
	/**
	 * The value to test, which may not be saved yet.
	 */
	key: string;
	base_url?: string;
	model?: string;
}

export interface AiAssistTestResponse {
	message: string;
}

/**
 * Site-wide AI assist: settings that belong to no chronicle (Schema Block descriptions, Credits).
 */
export const aiAssistSite = {
	generate: (
		data: AiAssistGenerateRequest
	): Promise< AiAssistGenerateResponse > =>
		apiFetch( { path: `${ BASE }/ai-assist`, method: 'POST', data } ),

	getSettings: (): Promise< AiAssistSiteSettings > =>
		apiFetch( { path: `${ BASE }/ai-assist/settings` } ),

	/**
	 * A key field left out of data entirely is untouched.
	 */
	updateSettings: (
		data: Partial< {
			provider: string;
			openai_key: string;
			claude_key: string;
			openai_base_url: string;
			openai_model: string;
			claude_base_url: string;
			claude_model: string;
		} >
	): Promise< AiAssistSiteSettings > =>
		apiFetch( {
			path: `${ BASE }/ai-assist/settings`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Tests a provider/key/endpoint combination directly, independent of what (if anything) is currently saved.
	 */
	testConnection: (
		data: AiAssistTestRequest
	): Promise< AiAssistTestResponse > =>
		apiFetch( { path: `${ BASE }/ai-assist/test`, method: 'POST', data } ),
};

export interface SigningConstant {
	defined: boolean;
	readable: boolean | null;
}

export interface SigningStatus {
	/**
	 * The site-wide opt-in.
	 */
	enabled: boolean;
	/**
	 * Whether a usable certificate is configured, independent of the opt-in.
	 */
	available: boolean;
	code: string;
	/**
	 * Both of the above: whether a print made right now would actually be signed.
	 */
	signing_now: boolean;
	constants: Record< string, SigningConstant >;
	can_generate: boolean;
	openssl_extension: boolean;
}

export interface GeneratedCertificate {
	certificate: string;
	private_key: string;
	common_name: string;
	expires: string;
}

/**
 * Secure printing.
 */
export const signing = {
	status: (): Promise< SigningStatus > =>
		apiFetch( { path: `${ BASE }/signing/status` } ),

	updateSettings: ( enabled: boolean ): Promise< { enabled: boolean } > =>
		apiFetch( {
			path: `${ BASE }/signing/settings`,
			method: 'PUT',
			data: { enabled },
		} ),

	generateCertificate: ( data: {
		passphrase: string;
		common_name?: string;
		days?: number;
	} ): Promise< GeneratedCertificate > =>
		apiFetch( {
			path: `${ BASE }/signing/certificate`,
			method: 'POST',
			data,
		} ),
};

/**
 * Chronicle-scoped AI assist: everything else (character/plot/rumor/world-object text).
 */
export const aiAssist = ( gameSlug: string ) => ( {
	generate: (
		data: AiAssistGenerateRequest
	): Promise< AiAssistGenerateResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/ai-assist`,
			method: 'POST',
			data,
		} ),

	getSettings: (): Promise< AiAssistChronicleSettings > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/ai-assist/settings` } ),

	updateSettings: (
		data: Partial< {
			enabled: boolean;
			provider: string;
			openai_key: string;
			claude_key: string;
			openai_base_url: string;
			openai_model: string;
			claude_base_url: string;
			claude_model: string;
		} >
	): Promise< AiAssistChronicleSettings > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/ai-assist/settings`,
			method: 'PUT',
			data,
		} ),

	testConnection: (
		data: AiAssistTestRequest
	): Promise< AiAssistTestResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/ai-assist/test`,
			method: 'POST',
			data,
		} ),
} );

// ---------------------------------------------------------------------------
// Characters (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's characters.
 */
export const characters = ( gameSlug: string ) => ( {
	/**
	 * Fetches the list of characters in this chronicle matching the given filters.
	 */
	list: ( params: CharacterCollectionParams = {} ): Promise< Character[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Same collection as list().
	 */
	listPaginated: (
		params: CharacterCollectionParams = {}
	): Promise< { items: Character[]; total: number; totalPages: number } > =>
		fetchPage< Character >( {
			path: `${ BASE }/${ gameSlug }/characters${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Fetches a single character by id within this chronicle.
	 */
	get: ( id: number ): Promise< Character > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/characters/${ id }` } ),

	/**
	 * Saves a new held-entry order for a `player_order`-flagged block (Blood Magic, Rituals).
	 */
	saveOrder: (
		id: number,
		blockSlug: string,
		order: number[],
		names: string[]
	): Promise< { order: unknown[] } > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ id }/order/${ blockSlug }`,
			method: 'PUT',
			data: { order, names },
		} ),

	/**
	 * Creates a new character in this chronicle from the given request body. name and stack_slug are required.
	 */
	create: ( data: CreateCharacterRequest ): Promise< Character > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates an existing character by id with the given partial request body.
	 */
	update: (
		id: number,
		data: UpdateCharacterRequest
	): Promise< Character > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a character by id.
	 */
	delete: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ id }`,
			method: 'DELETE',
		} ),

	/**
	 * Prices a batch of proposed changes for a character without submitting them.
	 */
	previewChanges: (
		id: number,
		changes: ChangeRequest[]
	): Promise< PreviewChangesResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ id }/preview-changes`,
			method: 'POST',
			data: { changes },
		} ),

	/**
	 * Fetches the characters in this chronicle belonging to the current player.
	 */
	myCharacters: (): Promise< Character[] > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/my/characters` } ),

	/**
	 * Fetches the fixed status vocabulary for a bulk-status picker.
	 */
	statuses: (): Promise< { statuses: string[] } > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/characters/statuses` } ),

	/**
	 * Sets the same status on a group of characters at once.
	 */
	bulkStatus: ( data: BulkStatusRequest ): Promise< BulkStatusResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/bulk-status`,
			method: 'POST',
			data,
		} ),

	/**
	 * Exports a character to a Grapevine `.gex` XML document.
	 */
	export: (
		id: number,
		options: ExportCharacterOptions = {}
	): Promise< ExportCharacterResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ id }/export`,
			method: 'POST',
			data: { format: 'gex-xml', ...options },
		} ),

	/**
	 * Fetches the itemised point audit for one character.
	 */
	pointAudit: ( id: number ): Promise< PointAudit > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ id }/point-audit`,
		} ),
} );

/**
 * REST client for looking up WordPress user accounts.
 */
export const wpUsers = {
	/**
	 * Searches WordPress user accounts by display name or email.
	 */
	search: ( search = '' ): Promise< WpUserSummary[] > =>
		apiFetch( {
			path: `${ BASE }/wp-users${ toQuery( {
				search: search || undefined,
			} ) }`,
		} ),

	/**
	 * A chronicle Storyteller's search for an account to assign as a player: at least three letters of a name, no email
	 * addresses back unless the search is that exact address.
	 */
	searchForChronicle: (
		gameSlug: string,
		search: string
	): Promise< WpUserSummary[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/wp-users${ toQuery( {
				search: search || undefined,
			} ) }`,
		} ),
};

// ---------------------------------------------------------------------------
// Chronicle-scoped authorization
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's membership list: which WordPress accounts hold which role (head
 * storyteller, assistant storyteller, narrator, player) within it.
 */
export const gameMembers = ( gameSlug: string ) => ( {
	/**
	 * Fetches every member of this chronicle along with their role. name and user_email are enriched server-side for
	 * display.
	 */
	list: (): Promise< GameMember[] > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/members` } ),

	/**
	 * Adds a WordPress user to this chronicle with the given role, or changes their role if they are already a member.
	 */
	set: ( wpUserId: number, role: GameMemberRole ): Promise< GameMember > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/members`,
			method: 'POST',
			data: { wp_user_id: wpUserId, role },
		} ),

	/**
	 * Removes a WordPress user's membership from this chronicle entirely.
	 */
	remove: ( wpUserId: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/members/${ wpUserId }`,
			method: 'DELETE',
		} ),
} );

/**
 * REST client for the plugin's site-wide authorization configuration: whether external access-control integration is
 * enabled.
 */
export const authorizationSettings = {
	/**
	 * Fetches the current authorization configuration.
	 */
	get: (): Promise< AuthorizationSettings > =>
		apiFetch( { path: `${ BASE }/authorization-settings` } ),

	/**
	 * Enables or disables external access-control integration site-wide.
	 */
	update: ( ascEnabled: boolean ): Promise< AuthorizationSettings > =>
		apiFetch( {
			path: `${ BASE }/authorization-settings`,
			method: 'PUT',
			data: { asc_enabled: ascEnabled },
		} ),
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
	tables: Record< string, Record< string, unknown >[] >;
}

export const dataManagement = {
	/**
	 * Fetches whether uninstalling this plugin is currently set to delete its data.
	 */
	get: (): Promise< DataManagementSettings > =>
		apiFetch( { path: `${ BASE }/data-management` } ),

	/**
	 * Turns the uninstall delete-data behavior on or off, site-wide.
	 */
	update: ( deleteOnUninstall: boolean ): Promise< DataManagementSettings > =>
		apiFetch( {
			path: `${ BASE }/data-management`,
			method: 'PUT',
			data: { delete_on_uninstall: deleteOnUninstall },
		} ),

	/**
	 * Fetches a full export of every plugin table, for a manual backup.
	 */
	export: (): Promise< DataExport > =>
		apiFetch( { path: `${ BASE }/data-management/export` } ),
};

// ---------------------------------------------------------------------------
// Docs — the wp-admin "Docs" page's own content source
// ---------------------------------------------------------------------------

/**
 * REST client for the plugin's built-in documentation pages shown in wp-admin.
 */
export const docs = {
	/**
	 * Fetches the content of one built-in documentation page by its slug.
	 */
	get: (
		slug: 'st-guide' | 'admin-guide' | 'player-guide' | 'rest-api'
	): Promise< { slug: string; content: string } > =>
		apiFetch( { path: `${ BASE }/docs/${ slug }` } ),

	/**
	 * Fetches one screen's help page (`docs/help/{key}.md`), the Markdown a screen's `?` opens in the help panel.
	 */
	help: ( key: string ): Promise< { key: string; content: string } > =>
		apiFetch( {
			path: `${ BASE }/docs/help/${ encodeURIComponent( key ) }`,
		} ),
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
 * REST client for the plugin's credits text and in-memoriam list, shared site-wide.
 */
export const credits = {
	/**
	 * Fetches the current credits text and in-memoriam list.
	 */
	get: (): Promise< CreditsResponse > =>
		apiFetch( { path: `${ BASE }/credits` } ),

	/**
	 * Updates the credits text and/or in-memoriam list.
	 */
	update: ( data: Partial< CreditsResponse > ): Promise< CreditsResponse > =>
		apiFetch( { path: `${ BASE }/credits`, method: 'PUT', data } ),
};

// ---------------------------------------------------------------------------
// Game stats
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's Storyteller dashboard statistics.
 */
export const gameStats = ( gameSlug: string ) => ( {
	/**
	 * Fetches the aggregate dashboard numbers for this chronicle: character counts, pending change count, active plot
	 * count, recent activity, and the roster-health count.
	 */
	get: (): Promise< GameStats > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/stats` } ),

	/**
	 * Fetches the actual player list behind `players_without_active_character`'s count.
	 */
	playersWithoutActiveCharacter: (): Promise<
		PlayerWithoutActiveCharacter[]
	> =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/stats/players-without-active-character`,
		} ),
} );

/**
 * REST client factory for the Chronicle Setup checklist.
 */
export const setupStatus = ( gameSlug: string ) => ( {
	get: (): Promise< SetupStatus > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/setup-status` } ),
} );

// ---------------------------------------------------------------------------
// Changes (game + character scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's character changes: submitting, reviewing, and listing edits, plus the
 * game-wide approval queue and the player's own pending changes.
 */
export const changes = ( gameSlug: string ) => ( {
	/**
	 * Fetches the changes recorded against a single character.
	 */
	list: (
		characterId: number,
		params: ChangeCollectionParams = {}
	): Promise< CharacterChange[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/changes${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Submits a new change against a character from the given request body.
	 */
	create: (
		characterId: number,
		data: ChangeRequest
	): Promise< CharacterChange > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/changes`,
			method: 'POST',
			data,
		} ),

	/**
	 * Approves or rejects a pending change by id.
	 */
	review: (
		changeId: number,
		data: ChangeReviewRequest
	): Promise< CharacterChange > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/changes/${ changeId }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Fetches the game-wide change approval queue, spanning every character in the chronicle.
	 */
	queue: (
		params: QueueCollectionParams = {}
	): Promise< { items: QueueChange[]; total: number; totalPages: number } > =>
		fetchPage< QueueChange >( {
			path: `${ BASE }/${ gameSlug }/changes${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Approves a batch of pending changes by id in one request. reviewTokens maps each id to the review_token the queue
	 * issued.
	 */
	batchApprove: (
		changeIds: number[],
		reviewTokens: Record< number, string > = {}
	): Promise< BatchApproveResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/changes/batch-approve`,
			method: 'POST',
			data: { change_ids: changeIds, review_tokens: reviewTokens },
		} ),

	/**
	 * Fetches the current player's own pending changes across every one of their characters.
	 */
	myChanges: (): Promise< QueueChange[] > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/my/changes` } ),
} );

// ---------------------------------------------------------------------------
// Snapshots (game + character scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's character snapshots: saved point-in-time copies of a character's sheet
 * data.
 */
export const snapshots = ( gameSlug: string ) => ( {
	/**
	 * Fetches the list of snapshots saved for a character.
	 */
	list: (
		characterId: number,
		params: SnapshotCollectionParams = {}
	): Promise< CharacterSnapshot[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/snapshots${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Fetches a single saved snapshot by id for a character.
	 */
	get: (
		characterId: number,
		snapshotId: number
	): Promise< CharacterSnapshot > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/snapshots/${ snapshotId }`,
		} ),

	/**
	 * Saves a new snapshot of a character's current sheet data.
	 */
	create: ( characterId: number ): Promise< CharacterSnapshot > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/snapshots`,
			method: 'POST',
			data: {},
		} ),
} );

// ---------------------------------------------------------------------------
// Sheet Style (game + character scoped) — one cosmetic override per character.
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's character sheet style overrides: per-character cosmetic customization
 * such as fonts, colors, and section graphics.
 */
export const sheetStyle = ( gameSlug: string ) => ( {
	/**
	 * Fetches a character's saved sheet style override.
	 */
	get: ( characterId: number ): Promise< SheetStyle > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/sheet-style`,
		} ),

	/**
	 * Saves a character's sheet style override from the given request body.
	 */
	save: (
		characterId: number,
		data: SheetStyleInput
	): Promise< SheetStyle > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/sheet-style`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Clears a character's sheet style override entirely, returning it to the default appearance.
	 */
	reset: ( characterId: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/sheet-style`,
			method: 'DELETE',
		} ),
} );

/**
 * REST client factory for a chronicle's signed-PDF character sheets.
 */
export const sheets = ( gameSlug: string ) => ( {
	/**
	 * Builds the signed-PDF download URL for one or more characters, reading the REST root and nonce from
	 * `window.beyondElysium`.
	 */
	pdfUrl: (
		characterIds: number[],
		options: {
			background?: boolean;
			notes?: boolean;
			xpHistory?: boolean;
			fullPowerNames?: boolean;
			/**
			 * Default true server-side.
			 */
			showCost?: boolean;
		} = {}
	): string => {
		const params = new URLSearchParams( {
			character_ids: characterIds.join( ',' ),
		} );
		if ( options.background ) {
			params.set( 'background', '1' );
		}
		if ( options.notes ) {
			params.set( 'notes', '1' );
		}
		if ( options.xpHistory ) {
			params.set( 'xp_history', '1' );
		}
		if ( options.fullPowerNames ) {
			params.set( 'full_power_names', '1' );
		}
		if ( options.showCost === false ) {
			params.set( 'show_cost', '0' );
		}
		params.set( '_wpnonce', window.beyondElysium?.nonce ?? '' );

		const root =
			window.beyondElysium?.restUrl ??
			`${ window.location.origin }/wp-json/be/v1/`;
		return `${ root }${ gameSlug }/sheets/pdf?${ params.toString() }`;
	},

	/**
	 * Preflight: is this chronicle's sheet signing actually configured?
	 */
	availability: (): Promise< { ok: boolean; code: string } > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/sheets/availability` } ),
} );

/**
 * REST client factory for the 19 GV301 reports.
 */
export const reports = ( gameSlug: string ) => ( {
	/**
	 * The report registry: key, title, shape, entity.
	 */
	list: (): Promise<
		Array< {
			key: string;
			title: string;
			shape: string;
			entity: string | null;
		} >
	> => apiFetch( { path: `${ BASE }/${ gameSlug }/reports` } ),

	/**
	 * The plain JSON form of one resolved report.
	 */
	document: (
		reportKey: string,
		options: { characterId?: number } = {}
	): Promise< Record< string, unknown > > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/reports/${ reportKey }${ toQuery(
				options.characterId ? { character_id: options.characterId } : {}
			) }`,
		} ),

	/**
	 * Whether each card report (item-cards, location-cards, rote-cards) is available for a character.
	 */
	availability: (
		characterId?: number
	): Promise< Record< string, boolean > > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/reports/availability${ toQuery(
				characterId ? { character_id: characterId } : {}
			) }`,
		} ),

	/**
	 * Builds the signed-PDF download URL for one report.
	 */
	pdfUrl: (
		reportKey: string,
		options: {
			statField?: string;
			statType?: string;
			characterId?: number;
		} = {}
	): string => {
		const params = new URLSearchParams();
		if ( options.statField ) {
			params.set( 'stat_field', options.statField );
		}
		if ( options.statType ) {
			params.set( 'stat_type', options.statType );
		}
		if ( options.characterId ) {
			params.set( 'character_id', String( options.characterId ) );
		}
		params.set( '_wpnonce', window.beyondElysium?.nonce ?? '' );

		const root =
			window.beyondElysium?.restUrl ??
			`${ window.location.origin }/wp-json/be/v1/`;
		return `${ root }${ gameSlug }/reports/${ reportKey }/pdf?${ params.toString() }`;
	},
} );

// ---------------------------------------------------------------------------
// Experience (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's experience-point operations.
 */
export const experience = ( gameSlug: string ) => ( {
	/**
	 * Awards the same amount of XP to a group of characters at once, with a shared reason recorded against each award.
	 */
	bulkAward: ( data: BulkXPRequest ): Promise< BulkXPResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/experience/bulk-award`,
			method: 'POST',
			data,
		} ),
} );

// ---------------------------------------------------------------------------
// Resource pools (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's resource-pool bulk maintenance.
 */
export const resourcePools = ( gameSlug: string ) => ( {
	/**
	 * Resets one named resource pool's temporary rating back to its permanent one, across a group of characters at once.
	 */
	bulkReset: (
		data: BulkPoolResetRequest
	): Promise< BulkPoolResetResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/resource-pools/bulk-reset`,
			method: 'POST',
			data,
		} ),
} );

// ---------------------------------------------------------------------------
// Plots (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's plots: listing, fetching, creating, updating, and deleting plots,
 * actions, and rumors, plus action allocation and rumor generation.
 */
export const plots = ( gameSlug: string ) => ( {
	/**
	 * Fetches the list of plots in this chronicle matching the given filters.
	 */
	list: ( params: PlotCollectionParams = {} ): Promise< Plot[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/plots${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Same collection as list().
	 */
	listPaginated: (
		params: PlotCollectionParams = {}
	): Promise< { items: Plot[]; total: number; totalPages: number } > =>
		fetchPage< Plot >( {
			path: `${ BASE }/${ gameSlug }/plots${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Fetches a single plot, action, or rumor by id.
	 */
	get: ( id: number ): Promise< Plot > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/plots/${ id }` } ),

	/**
	 * Fetches the plots resolved as relevant to the current player, through both direct connections and target-query
	 * matching.
	 */
	myPlots: (): Promise< MyPlotsResponse > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/my/plots` } ),

	/**
	 * Creates a new plot, action, or rumor from the given request body.
	 */
	create: ( data: CreatePlotRequest ): Promise< Plot > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/plots`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates an existing plot, action, or rumor by id with the given partial request body.
	 */
	update: ( id: number, data: UpdatePlotRequest ): Promise< Plot > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/plots/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a plot, action, or rumor by id.
	 */
	delete: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/plots/${ id }`,
			method: 'DELETE',
		} ),

	/**
	 * Allocates a character's available actions across their held powers for a given game date. commit false previews the
	 * allocation only.
	 */
	allocateActions: (
		characterId: number,
		gameDate: string,
		commit = false,
		parentPlotId?: number
	): Promise< AllocateActionsResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/plots/allocate-actions`,
			method: 'POST',
			data: {
				character_id: characterId,
				game_date: gameDate,
				commit,
				parent_plot_id: parentPlotId,
			},
		} ),

	/**
	 * Generates candidate rumors for a given game date. commit false previews the candidates only.
	 */
	generateRumors: (
		gameDate: string,
		commit = false
	): Promise< GenerateRumorsResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/plots/generate-rumors`,
			method: 'POST',
			data: { game_date: gameDate, commit },
		} ),

	/**
	 * Lists who may be invited into a player plot: active, non-NPC characters not already connected to it.
	 */
	memberCandidates: ( id: number ): Promise< CharacterOption[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/plots/${ id }/member-candidates`,
		} ),

	/**
	 * Adds a character to a player plot as an invited co-narrator.
	 */
	addMember: ( id: number, characterId: number ): Promise< Connection > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/plots/${ id }/members`,
			method: 'POST',
			data: { character_id: characterId },
		} ),

	/**
	 * Removes a character from a player plot's membership by its connection id (not the character id).
	 */
	removeMember: ( id: number, connectionId: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/plots/${ id }/members/${ connectionId }`,
			method: 'DELETE',
		} ),

	/**
	 * Every character who can currently see this plot.
	 */
	visibleCharacters: ( id: number ): Promise< CharacterOption[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/plots/${ id }/visible-characters`,
		} ),
} );

// ---------------------------------------------------------------------------
// Action & Rumor settings and the background-use ledger (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's Action & Rumor configuration and its background-use ledger.
 */
export const apr = ( gameSlug: string ) => ( {
	/**
	 * Fetches the chronicle's full thirteen-knob configuration.
	 */
	getSettings: (): Promise< AprSettings > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/apr-settings` } ),

	/**
	 * Updates any subset of the chronicle's knobs.
	 */
	updateSettings: ( data: AprSettingsRequest ): Promise< AprSettings > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/apr-settings`,
			method: 'PUT',
			data: { apr: data },
		} ),

	/**
	 * Fetches the fork-aware union of every background/influence name, for the background_actions picker.
	 */
	backgroundOptions: (): Promise< AprBackgroundOption[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/apr-settings/backgrounds`,
		} ),

	/**
	 * Fetches the backgrounds a character holds, each annotated with its live budget when one exists.
	 */
	spendable: ( characterId: number ): Promise< SpendableBackground[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/spendable`,
		} ),

	/**
	 * Fetches a character's recorded background uses for one game date.
	 */
	backgroundUses: (
		characterId: number,
		gameDate: string
	): Promise< BackgroundUse[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/background-uses?game_date=${ encodeURIComponent(
				gameDate
			) }`,
		} ),

	/**
	 * Records one background use.
	 */
	recordUse: (
		characterId: number,
		data: RecordBackgroundUseRequest
	): Promise< BackgroundUse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/background-uses`,
			method: 'POST',
			data,
		} ),

	/**
	 * Edits a use's text, result, or cost.
	 */
	updateUse: (
		id: number,
		data: UpdateBackgroundUseRequest
	): Promise< BackgroundUse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/background-uses/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes one use - Grapevine's "Clear this use".
	 */
	deleteUse: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/background-uses/${ id }`,
			method: 'DELETE',
		} ),

	/**
	 * Clears every use for one character, optionally bounded to a game-date range.
	 */
	clearForCharacter: (
		characterId: number,
		from?: string,
		to?: string
	): Promise< { cleared: number } > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/background-uses/clear`,
			method: 'POST',
			data: { from, to },
		} ),

	/**
	 * Clears every use for one game date across the whole chronicle.
	 */
	clearForDate: ( gameDate: string ): Promise< { cleared: number } > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/background-uses/clear-date`,
			method: 'POST',
			data: { game_date: gameDate },
		} ),
} );

// ---------------------------------------------------------------------------
// Plot Entries (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's plot entries: the individual timeline entries (actions, responses,
 * notes, resolutions) recorded against a plot.
 */
export const plotEntries = ( gameSlug: string ) => ( {
	/**
	 * Fetches the entries recorded against a single plot, action, or rumor, optionally filtered to one entry type.
	 */
	list: ( plotId: number, entryType?: string ): Promise< PlotEntry[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/plots/${ plotId }/entries${ toQuery(
				{ entry_type: entryType }
			) }`,
		} ),

	/**
	 * Creates a new entry against a plot, action, or rumor from the given request body.
	 */
	create: (
		plotId: number,
		data: CreateEntryRequest
	): Promise< PlotEntry > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/plots/${ plotId }/entries`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates an existing entry's content, and optionally its audience, by id.
	 */
	update: (
		id: number,
		data: {
			content: string;
			audience?: EntryAudienceValue;
			audience_character_ids?: number[];
		}
	): Promise< PlotEntry > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/entries/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a plot entry by id.
	 */
	delete: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/entries/${ id }`,
			method: 'DELETE',
		} ),
} );

// ---------------------------------------------------------------------------
// Connections (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's connections: links between characters, plots, world objects, and tags.
 */
export const connections = ( gameSlug: string ) => ( {
	/**
	 * Fetches connections matching the given source/target filters.
	 */
	list: (
		params: {
			source_type?: EntityType;
			source_id?: number;
			target_type?: EntityType;
			target_id?: number;
		} = {}
	): Promise< Connection[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/connections${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Fetches every connection involving a single entity, in either direction.
	 */
	forEntity: (
		entityType: EntityType,
		entityId: number
	): Promise< Connection[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/connections${ toQuery( {
				entity_type: entityType,
				entity_id: entityId,
			} ) }`,
		} ),

	/**
	 * Creates a new connection between two entities from the given request body.
	 */
	create: ( data: CreateConnectionRequest ): Promise< Connection > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/connections`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates an existing connection's label and/or notes by id.
	 */
	update: (
		id: number,
		data: UpdateConnectionRequest
	): Promise< Connection > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/connections/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a connection by id.
	 */
	delete: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/connections/${ id }`,
			method: 'DELETE',
		} ),
} );

// ---------------------------------------------------------------------------
// Query fields (global)
// ---------------------------------------------------------------------------

/**
 * REST client for the global list of fields the query builder can search on.
 */
export const queryFields = {
	/**
	 * Fetches the queryable fields for a given inventory (such as characters).
	 */
	list: ( inventory = 'char' ): Promise< QueryField[] > =>
		apiFetch( {
			path: `${ BASE }/query-fields${ toQuery( { inventory } ) }`,
		} ),
};

// ---------------------------------------------------------------------------
// Query, Statistics and Saved Queries (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's query tool: running ad hoc character queries and statistics, and
 * managing saved queries.
 */
export const query = ( gameSlug: string ) => ( {
	/**
	 * Runs a query against this chronicle's characters and returns the matching characters directly.
	 */
	run: ( data: RunQueryRequest ): Promise< QueryResultCharacter[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/query`,
			method: 'POST',
			data,
		} ),

	/**
	 * Same query as run(), but reads total and totalPages from the X-WP-Total / X-WP-TotalPages response headers.
	 */
	runPaginated: (
		data: RunQueryRequest
	): Promise< {
		items: QueryResultCharacter[];
		total: number;
		totalPages: number;
	} > =>
		fetchPage< QueryResultCharacter >( {
			path: `${ BASE }/${ gameSlug }/query`,
			method: 'POST',
			data,
		} ),

	/**
	 * Runs a statistics aggregate over the characters matching a set of query conditions.
	 */
	statistics: ( data: RunStatisticsRequest ): Promise< StatisticsResult > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/statistics`,
			method: 'POST',
			data,
		} ),

	savedQueries: {
		/**
		 * Fetches every query this chronicle has saved, including automatically retained recent searches.
		 */
		list: (): Promise< SavedQuery[] > =>
			apiFetch( { path: `${ BASE }/${ gameSlug }/queries` } ),

		/**
		 * Saves a new query from the given request body.
		 */
		create: ( data: SaveQueryRequest ): Promise< SavedQuery > =>
			apiFetch( {
				path: `${ BASE }/${ gameSlug }/queries`,
				method: 'POST',
				data,
			} ),

		/**
		 * Updates an existing saved query by id with the given partial request body.
		 */
		update: (
			id: number,
			data: Partial< SaveQueryRequest >
		): Promise< SavedQuery > =>
			apiFetch( {
				path: `${ BASE }/${ gameSlug }/queries/${ id }`,
				method: 'PUT',
				data,
			} ),

		/**
		 * Deletes a saved query by id.
		 */
		delete: ( id: number ): Promise< void > =>
			apiFetch( {
				path: `${ BASE }/${ gameSlug }/queries/${ id }`,
				method: 'DELETE',
			} ),
	},
} );

// ---------------------------------------------------------------------------
// World Objects (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's world objects: items, locations, and rotes shared across the
 * chronicle.
 */
export const worldObjects = ( gameSlug: string ) => ( {
	/**
	 * Fetches the list of world objects matching the given filters.
	 */
	list: (
		params: WorldObjectCollectionParams = {}
	): Promise< WorldObject[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/world-objects${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Same collection as list().
	 */
	listPaginated: (
		params: WorldObjectCollectionParams = {}
	): Promise< { items: WorldObject[]; total: number; totalPages: number } > =>
		fetchPage< WorldObject >( {
			path: `${ BASE }/${ gameSlug }/world-objects${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Fetches a single world object by id.
	 */
	get: ( id: number ): Promise< WorldObject > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/world-objects/${ id }` } ),

	/**
	 * Creates a new world object from the given request body.
	 */
	create: ( data: CreateWorldObjectRequest ): Promise< WorldObject > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/world-objects`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates an existing world object by id with the given partial request body.
	 */
	update: (
		id: number,
		data: UpdateWorldObjectRequest
	): Promise< WorldObject > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/world-objects/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a world object by id.
	 */
	delete: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/world-objects/${ id }`,
			method: 'DELETE',
		} ),

	/**
	 * Copies an item for a specific character.
	 */
	copyForCharacter: (
		id: number,
		data: CopyForCharacterRequest
	): Promise< WorldObject > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/world-objects/${ id }/copy-for-character`,
			method: 'POST',
			data,
		} ),

	/**
	 * Spends one use of an item.
	 */
	use: ( id: number, data: UseItemRequest ): Promise< WorldObject > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/world-objects/${ id }/use`,
			method: 'POST',
			data,
		} ),

	/**
	 * Transfers an item to a new character, or clears its holder for `how: 'lost'`.
	 */
	transfer: (
		id: number,
		data: TransferItemRequest
	): Promise< WorldObject > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/world-objects/${ id }/transfer`,
			method: 'POST',
			data,
		} ),

	/**
	 * Fetches an item's own history, oldest first.
	 */
	events: ( id: number ): Promise< ItemEvent[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/world-objects/${ id }/events`,
		} ),

	/**
	 * Revokes every verification code ever printed for one item.
	 */
	revokeCards: ( id: number ): Promise< { revoked: number } > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/world-objects/${ id }/revoke-cards`,
			method: 'POST',
		} ),
} );

// ---------------------------------------------------------------------------
// Location links (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a location's four named links and the "who's here" roster read through the same route.
 */
export const locations = ( gameSlug: string ) => ( {
	/**
	 * Every link a manager can see, or just the "who's here" NPC roster for anyone else.
	 */
	links: ( locationId: number ): Promise< LocationLink[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/locations/${ locationId }/links`,
		} ),

	/**
	 * Creates a link. be_manage_world_objects only.
	 */
	createLink: (
		locationId: number,
		data: {
			label: LocationLinkLabel;
			source_type: 'character';
			source_id: number;
		}
	): Promise< LocationLink > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/locations/${ locationId }/links`,
			method: 'POST',
			data,
		} ),

	/**
	 * Removes a link. be_manage_world_objects only.
	 */
	deleteLink: ( locationId: number, linkId: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/locations/${ locationId }/links/${ linkId }`,
			method: 'DELETE',
		} ),
} );

// ---------------------------------------------------------------------------
// Secrets and reveals (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for Storyteller-authored secrets and their reveals.
 */
export const secrets = ( gameSlug: string ) => ( {
	/**
	 * Every secret on one entity the viewer can see.
	 */
	list: (
		entityType: SecretEntityType,
		entityId: number
	): Promise< Secret[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/secrets${ toQuery( {
				entity_type: entityType,
				entity_id: entityId,
			} ) }`,
		} ),

	/**
	 * "What I Know" - every secret revealed to one of the caller's own characters.
	 */
	mine: (): Promise< MySecretRow[] > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/my/secrets` } ),

	/**
	 * Creates a secret. be_manage_plots only.
	 */
	create: ( data: CreateSecretRequest ): Promise< Secret > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/secrets`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates a secret. be_manage_plots only.
	 */
	update: ( id: number, data: UpdateSecretRequest ): Promise< Secret > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/secrets/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a secret and every one of its reveals. be_manage_plots only.
	 */
	remove: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/secrets/${ id }`,
			method: 'DELETE',
		} ),

	/**
	 * Every reveal of one secret. be_manage_plots only.
	 */
	reveals: ( secretId: number ): Promise< SecretReveal[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/secrets/${ secretId }/reveals`,
		} ),

	/**
	 * Reveals a secret to a character. be_manage_plots only.
	 */
	createReveal: (
		secretId: number,
		data: CreateSecretRevealRequest
	): Promise< SecretReveal > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/secrets/${ secretId }/reveals`,
			method: 'POST',
			data,
		} ),

	/**
	 * Removes a reveal. be_manage_plots only.
	 */
	deleteReveal: ( secretId: number, revealId: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/secrets/${ secretId }/reveals/${ revealId }`,
			method: 'DELETE',
		} ),
} );

// ---------------------------------------------------------------------------
// Factions and their members (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for chronicle factions and their membership.
 */
export const factions = ( gameSlug: string ) => ( {
	/**
	 * Every faction the viewer can see.
	 */
	list: (): Promise< Faction[] > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/factions` } ),

	get: ( id: number ): Promise< Faction > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/factions/${ id }` } ),

	/**
	 * Creates a faction directly. be_manage_factions only.
	 */
	create: ( data: FactionRequest ): Promise< Faction > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/factions`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates a faction. be_manage_factions only.
	 */
	update: ( id: number, data: FactionRequest ): Promise< Faction > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/factions/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a faction and unlinks its positions. be_manage_factions only.
	 */
	remove: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/factions/${ id }`,
			method: 'DELETE',
		} ),

	/**
	 * The member roster with rank and leader flags.
	 */
	members: ( id: number ): Promise< FactionMember[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/factions/${ id }/members`,
		} ),

	/**
	 * The name-only invite picker.
	 */
	memberCandidates: ( id: number ): Promise< FactionMemberCandidate[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/factions/${ id }/members/candidates`,
		} ),

	/**
	 * Adds a character to a faction.
	 */
	addMember: ( id: number, characterId: number ): Promise< FactionMember > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/factions/${ id }/members`,
			method: 'POST',
			data: { character_id: characterId },
		} ),

	/**
	 * Removes a member.
	 */
	removeMember: ( id: number, characterId: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/factions/${ id }/members/${ characterId }`,
			method: 'DELETE',
		} ),

	/**
	 * Sets a member's rank and/or leader flag.
	 */
	updateMember: (
		id: number,
		characterId: number,
		data: { rank?: string | null; is_leader?: boolean }
	): Promise< FactionMember > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/factions/${ id }/members/${ characterId }`,
			method: 'PATCH',
			data,
		} ),
} );

// ---------------------------------------------------------------------------
// Court/office positions (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for chronicle-wide offices, optionally scoped to a faction.
 */
export const positions = ( gameSlug: string ) => ( {
	/**
	 * Every position the viewer can see, optionally narrowed to one faction.
	 */
	list: ( factionId?: number ): Promise< Position[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/positions${ toQuery( {
				faction_id: factionId,
			} ) }`,
		} ),

	/**
	 * Creates a position. be_manage_factions only.
	 */
	create: ( data: PositionRequest ): Promise< Position > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/positions`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates a position, including its holder (recorded to history). be_manage_factions only.
	 */
	update: ( id: number, data: PositionRequest ): Promise< Position > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/positions/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a position and its holder history. be_manage_factions only.
	 */
	remove: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/positions/${ id }`,
			method: 'DELETE',
		} ),

	/**
	 * A position's own full holder history. be_manage_factions only.
	 */
	history: ( id: number ): Promise< PositionHistoryRow[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/positions/${ id }/history`,
		} ),

	/**
	 * The title preset groups for the picker. be_manage_factions only.
	 */
	presets: (): Promise< PositionPresets > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/position-presets` } ),
} );

// ---------------------------------------------------------------------------
// Attachments (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for file uploads on a plot, item, or location.
 */
export const attachments = ( gameSlug: string ) => ( {
	/**
	 * Uploads a file onto a plot, item, or location.
	 */
	upload: (
		entityType: AttachmentEntityType,
		entityId: number,
		file: File
	): Promise< Attachment > => {
		const body = new FormData();
		body.append( 'entity_type', entityType );
		body.append( 'entity_id', String( entityId ) );
		body.append( 'file', file );
		return apiFetch( {
			path: `${ BASE }/${ gameSlug }/attachments`,
			method: 'POST',
			body,
		} );
	},

	/**
	 * Builds the direct download URL for one attachment.
	 */
	downloadUrl: ( id: number ): string => {
		const params = new URLSearchParams( {
			_wpnonce: window.beyondElysium?.nonce ?? '',
		} );
		const root =
			window.beyondElysium?.restUrl ??
			`${ window.location.origin }/wp-json/be/v1/`;
		return `${ root }${ gameSlug }/attachments/${ id }?${ params.toString() }`;
	},

	/**
	 * Deletes an attachment by id: the database row and its file on disk together.
	 */
	delete: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/attachments/${ id }`,
			method: 'DELETE',
		} ),
} );

// ---------------------------------------------------------------------------
// Game Sessions (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's game sessions: the calendar, sign-in attendance, and awarding
 * attendance XP.
 */
export const sessions = ( gameSlug: string ) => ( {
	/**
	 * The chronicle's sessions, soonest first, optionally narrowed to a date range.
	 */
	list: (
		params: { from?: string; to?: string } = {}
	): Promise< GameSession[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Creates a new session. game_date is required and must be unique in this chronicle.
	 */
	create: ( data: CreateSessionRequest ): Promise< GameSession > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates an existing session.
	 */
	update: (
		id: number,
		data: UpdateSessionRequest
	): Promise< GameSession > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a session; refused with a 409 if it already has attendance recorded.
	 */
	delete: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions/${ id }`,
			method: 'DELETE',
		} ),

	/**
	 * Lists everyone recorded present at a session.
	 */
	getAttendance: ( sessionId: number ): Promise< SessionAttendance[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions/${ sessionId }/attendance`,
		} ),

	/**
	 * Records a sign-in: a real character, or a visitor by name.
	 */
	addAttendance: (
		sessionId: number,
		data: RecordAttendanceRequest
	): Promise< SessionAttendance > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions/${ sessionId }/attendance`,
			method: 'POST',
			data,
		} ),

	/**
	 * Removes one attendance row from a session.
	 */
	removeAttendance: (
		sessionId: number,
		attendanceId: number
	): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions/${ sessionId }/attendance/${ attendanceId }`,
			method: 'DELETE',
		} ),

	/**
	 * Awards attendance XP once to everyone signed in at a session.
	 */
	awardAttendanceXp: (
		sessionId: number,
		options: { amount?: number; force?: boolean } = {}
	): Promise< AwardAttendanceXpResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions/${ sessionId }/award-attendance-xp`,
			method: 'POST',
			data: options,
		} ),

	/**
	 * Updates this chronicle's session-related settings and/or its recurring release-schedule rules.
	 */
	updateSettings: (
		data: SessionSettings
	): Promise< SessionSettingsResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/session-settings`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Sets one character's own downtime deadline for this session.
	 */
	addDowntimeExtension: (
		sessionId: number,
		characterId: number,
		until: string
	): Promise< Record< string, string > > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions/${ sessionId }/downtime-extensions`,
			method: 'POST',
			data: { character_id: characterId, until },
		} ),

	/**
	 * Removes one character's downtime extension, returning them to the session's own deadline.
	 */
	removeDowntimeExtension: (
		sessionId: number,
		characterId: number
	): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions/${ sessionId }/downtime-extensions/${ characterId }`,
			method: 'DELETE',
		} ),

	/**
	 * After-game reports for a session.
	 */
	getReports: ( sessionId: number ): Promise< AfterGameReport[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions/${ sessionId }/reports`,
		} ),

	/**
	 * Files a report for the caller's own character.
	 */
	createReport: (
		sessionId: number,
		data: AfterGameReportRequest
	): Promise< AfterGameReport > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions/${ sessionId }/reports`,
			method: 'POST',
			data,
		} ),

	/**
	 * Edits the caller's own report, until the session's own reports_due_at.
	 */
	updateReport: (
		sessionId: number,
		data: AfterGameReportRequest
	): Promise< AfterGameReport > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions/${ sessionId }/reports`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Marks a report read by a Storyteller.
	 */
	markReportRead: ( reportId: number ): Promise< AfterGameReport > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/after-game-reports/${ reportId }/read`,
			method: 'POST',
		} ),

	/**
	 * Awards report XP once to every character with a report at a session.
	 */
	awardReportXp: (
		sessionId: number,
		options: { amount?: number; force?: boolean } = {}
	): Promise< AwardReportXpResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/sessions/${ sessionId }/award-report-xp`,
			method: 'POST',
			data: options,
		} ),

	/**
	 * The spotlight check - every active, non-NPC character's own attention profile, flagged first then least recent
	 * attention.
	 */
	getSpotlight: (): Promise< SpotlightRow[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/spotlight`,
		} ),
} );

// ---------------------------------------------------------------------------
// NPC Castings (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for NPC casting: who plays which NPC at which session, and their brief.
 */
export const castings = ( gameSlug: string ) => ( {
	/**
	 * Every casting for one session.
	 */
	list: ( sessionId: number ): Promise< NpcCasting[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/castings${ toQuery( {
				session_id: sessionId,
			} ) }`,
		} ),

	/**
	 * Casts a chronicle member to play an NPC for a session. be_manage_characters only.
	 */
	create: ( data: CreateNpcCastingRequest ): Promise< NpcCasting > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/castings`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates a casting's cast member and/or brief.
	 */
	update: (
		id: number,
		data: UpdateNpcCastingRequest
	): Promise< NpcCasting > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/castings/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Removes a casting.
	 */
	remove: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/castings/${ id }`,
			method: 'DELETE',
		} ),

	/**
	 * Every chronicle member, any role.
	 */
	eligibleMembers: (): Promise< EligibleMember[] > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/castings/members` } ),

	/**
	 * The current viewer's own upcoming castings (today or later), joined with the NPC's name and the session's date.
	 */
	myUpcoming: (): Promise< StaffQueueCastingRow[] > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/castings/my-upcoming` } ),

	/**
	 * The read-only brief: the NPC's resolved sections plus the casting's own brief text.
	 */
	brief: ( id: number ): Promise< CastingBriefDocument > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/castings/${ id }/brief` } ),

	/**
	 * Builds the signed-PDF download URL for one casting's brief.
	 */
	briefPdfUrl: ( id: number ): string => {
		const params = new URLSearchParams( {
			_wpnonce: window.beyondElysium?.nonce ?? '',
		} );
		const root =
			window.beyondElysium?.restUrl ??
			`${ window.location.origin }/wp-json/be/v1/`;
		return `${ root }${ gameSlug }/castings/${ id }/brief.pdf?${ params.toString() }`;
	},
} );

// ---------------------------------------------------------------------------
// Downtime queue (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for the Storyteller downtime queue.
 */
export const downtime = ( gameSlug: string ) => ( {
	/**
	 * One row per action plot for a game date, unanswered first.
	 */
	queue: ( gameDate: string ): Promise< DowntimeQueueRow[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/downtime/queue${ toQuery( {
				game_date: gameDate,
			} ) }`,
		} ),
} );

// ---------------------------------------------------------------------------
// My Queue and staff assignment (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for My Queue and the staff picker it and every assignee field share.
 */
export const myQueue = ( gameSlug: string ) => ( {
	/**
	 * The four My Queue sections for the current viewer in this chronicle.
	 */
	get: (): Promise< StaffQueue > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/my/queue` } ),
	/**
	 * Every hst/ast/narrator member of this chronicle.
	 */
	staff: (): Promise< StaffMember[] > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/staff` } ),
} );

// ---------------------------------------------------------------------------
// NPC public profiles ("Who's Who", game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for the Who's Who NPC directory and its public profiles.
 */
export const npcs = ( gameSlug: string ) => ( {
	/**
	 * Every NPC whose Who's Who profile the current viewer can see.
	 */
	list: (): Promise< NpcProfile[] > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/npcs` } ),

	/**
	 * One NPC's Who's Who profile.
	 */
	get: ( id: number ): Promise< NpcProfile > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/npcs/${ id }` } ),

	/**
	 * Updates an NPC's public-profile fields.
	 */
	updateProfile: (
		characterId: number,
		data: UpdateNpcProfileRequest
	): Promise< NpcProfile > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/characters/${ characterId }/profile`,
			method: 'PUT',
			data,
		} ),
} );

// ---------------------------------------------------------------------------
// Release Batches (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's release batches: scheduling rumors and downtime answers to go out
 * together, several between games.
 */
export const releaseBatches = ( gameSlug: string ) => ( {
	/**
	 * This chronicle's release batches, newest created first, optionally narrowed by status.
	 */
	list: ( status?: ReleaseBatch[ 'status' ] ): Promise< ReleaseBatch[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/release-batches${ toQuery(
				status ? { status } : {}
			) }`,
		} ),

	/**
	 * Creates a batch: scheduled when release_at is given, draft.
	 */
	create: ( data: CreateReleaseBatchRequest ): Promise< ReleaseBatch > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/release-batches`,
			method: 'POST',
			data,
		} ),

	/**
	 * Updates a draft or scheduled batch's name, release_at, and/or status.
	 */
	update: (
		id: number,
		data: UpdateReleaseBatchRequest
	): Promise< ReleaseBatch > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/release-batches/${ id }`,
			method: 'PUT',
			data,
		} ),

	/**
	 * Deletes a draft or scheduled batch, returning its items to draft.
	 */
	delete: ( id: number ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/release-batches/${ id }`,
			method: 'DELETE',
		} ),

	/**
	 * Lists a batch's held items: rumors, downtime answers, and (once ships) reveals.
	 */
	getItems: ( id: number ): Promise< ReleaseBatchItems > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/release-batches/${ id }/items`,
		} ),

	/**
	 * Adds a plot or entry to a batch: sets held and this batch's id on it.
	 */
	addItem: (
		id: number,
		type: 'plot' | 'entry',
		itemId: number
	): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/release-batches/${ id }/items`,
			method: 'POST',
			data: { type, id: itemId },
		} ),

	/**
	 * Removes one item from a batch, returning it to draft.
	 */
	removeItem: (
		id: number,
		type: 'plot' | 'entry',
		itemId: number
	): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/release-batches/${ id }/items/${ type }/${ itemId }`,
			method: 'DELETE',
		} ),

	/**
	 * Releases one existing batch immediately.
	 */
	releaseNow: ( id: number ): Promise< ReleaseBatch > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/release-batches/${ id }/release-now`,
			method: 'POST',
		} ),

	/**
	 * Creates a batch, fills it with the given items, and releases it in one call.
	 */
	releaseNowSingle: (
		data: ReleaseNowSingleRequest
	): Promise< ReleaseBatch > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/release-batches/release-now`,
			method: 'POST',
			data,
		} ),
} );

// ---------------------------------------------------------------------------
// Boons / Harpy Ledger (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's boons: the harpy ledger of favors owed between characters.
 */
export const boons = ( gameSlug: string ) => ( {
	/**
	 * Fetches the boon ledger matching the given filters.
	 */
	ledger: ( params: BoonLedgerParams = {} ): Promise< Boon[] > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/boons${ toQuery(
				params as Record< string, unknown >
			) }`,
		} ),

	/**
	 * Records a new boon between two characters from the given request body.
	 */
	create: ( data: CreateBoonRequest ): Promise< Boon > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/boons`,
			method: 'POST',
			data,
		} ),

	/**
	 * Marks a boon as repaid by id, with an optional note recording how it was actually settled.
	 */
	repay: ( id: number, note?: string ): Promise< WorldObject > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/boons/${ id }/repay`,
			method: 'PUT',
			data: note ? { repaid_note: note } : {},
		} ),
} );

// ---------------------------------------------------------------------------
// Import (game-scoped)
// ---------------------------------------------------------------------------

/**
 * REST client factory for a single chronicle's Grapevine exchange file imports: uploading a file, polling job status,
 * and committing the resolved result.
 */
export const gexImport = ( gameSlug: string ) => ( {
	/**
	 * Uploads a Grapevine exchange file for parsing.
	 */
	parse: ( file: File ): Promise< ImportPreview > => {
		const body = new FormData();
		body.append( 'file', file );
		return apiFetch( {
			path: `${ BASE }/${ gameSlug }/import/parse`,
			method: 'POST',
			body,
		} );
	},

	/**
	 * Fetches the current preview for a previously started import job by id, in the same shape as parse().
	 */
	getJob: ( jobId: string ): Promise< ImportPreview > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/import/${ jobId }` } ),

	/**
	 * Commits a previewed import job, applying the given resolutions for any duplicates and flagged or unresolved traits.
	 */
	commit: (
		jobId: string,
		resolutions?: ImportResolutions
	): Promise< ImportCommitResult > =>
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
 * REST client factory for full game file (.gv3, GVBG) imports: uploading a file, polling job status, and committing
 * it as a new or merged chronicle.
 */
export const gameImport = () => ( {
	/**
	 * Uploads a game file for parsing.
	 */
	parse: ( file: File ): Promise< GameImportPreview > => {
		const body = new FormData();
		body.append( 'file', file );
		return apiFetch( {
			path: `${ BASE }/import/game/parse`,
			method: 'POST',
			body,
		} );
	},

	/**
	 * Fetches the current preview for a previously started game import job by id.
	 */
	getJob: ( jobId: string, target?: string ): Promise< GameImportPreview > =>
		apiFetch( {
			path: `${ BASE }/import/game/${ jobId }${
				target ? `?target=${ encodeURIComponent( target ) }` : ''
			}`,
		} ),

	/**
	 * Commits a previewed game import job to the given target, either as a new chronicle or merged into an existing one,
	 * applying the given duplicate/trait resolutions.
	 */
	commit: (
		jobId: string,
		target: GameImportTarget,
		resolutions?: ImportResolutions
	): Promise< GameImportCommitResult > =>
		apiFetch( {
			path: `${ BASE }/import/game/${ jobId }/commit`,
			method: 'POST',
			data: { target, resolutions: resolutions ?? {} },
		} ),
} );

// ---------------------------------------------------------------------------
// Transfers
// ---------------------------------------------------------------------------

/**
 * REST client for one chronicle's transfers: its own outbound actions as the home chronicle, reviewing and deciding
 * offers as the host, and its combined transfer list.
 */
export const transfers = ( gameSlug: string ) => ( {
	/**
	 * Lists every transfer this chronicle is party to on this site.
	 */
	list: (): Promise< Transfer[] > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/transfers` } ),

	/**
	 * Initiates an outbound transfer for one character.
	 */
	initiate: (
		characterId: number,
		hostSite?: string,
		hostSlug?: string
	): Promise< InitiateTransferResponse > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/transfers/outbound`,
			method: 'POST',
			data: {
				character_id: characterId,
				host_site: hostSite ?? '',
				host_slug: hostSlug ?? '',
			},
		} ),

	/**
	 * Home ST manually marks a still-pending transfer as received abroad.
	 */
	acknowledge: ( transferId: number ): Promise< Transfer > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/transfers/${ transferId }/acknowledge`,
			method: 'POST',
		} ),

	/**
	 * Home ST permanently gives the character up.
	 */
	release: ( transferId: number ): Promise< Transfer > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/transfers/${ transferId }/release`,
			method: 'POST',
		} ),

	/**
	 * Home ST cancels a still-pending transfer, revoking an offer still waiting at the host.
	 */
	decline: ( transferId: number ): Promise< Transfer > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/transfers/${ transferId }/decline`,
			method: 'POST',
		} ),

	/**
	 * Host ST reviews a waiting offer, shown like a parsed import file.
	 */
	review: ( transferId: number ): Promise< TransferReview > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/transfers/${ transferId }/review`,
		} ),

	/**
	 * Host ST accepts a waiting offer with the same decisions an import commit takes.
	 */
	accept: (
		transferId: number,
		resolutions: ImportResolutions
	): Promise< TransferAcceptResult > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/transfers/${ transferId }/accept`,
			method: 'POST',
			data: { resolutions },
		} ),

	/**
	 * Host ST refuses a waiting offer.
	 */
	refuse: ( transferId: number ): Promise< Transfer > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/transfers/${ transferId }/refuse`,
			method: 'POST',
		} ),

	/**
	 * Host ST ends a visit.
	 */
	sendHome: ( transferId: number ): Promise< Transfer > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/transfers/${ transferId }/send-home`,
			method: 'POST',
		} ),

	/**
	 * Host ST keeps a visiting character for good.
	 */
	retain: ( transferId: number ): Promise< Transfer > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/transfers/${ transferId }/retain`,
			method: 'POST',
		} ),
} );

// ---------------------------------------------------------------------------
// Submissions
// ---------------------------------------------------------------------------

/**
 * REST client for one chronicle's player-sent Grapevine files: sending one (anyone signed in), withdrawing your own,
 * and a Storyteller's review, verification check, accept, and refuse.
 */
export const submissions = ( gameSlug: string ) => ( {
	/**
	 * This chronicle's own waiting submissions, newest first.
	 */
	list: (): Promise< Submission[] > =>
		apiFetch( { path: `${ BASE }/${ gameSlug }/submissions` } ),

	/**
	 * Reads an uploaded file and reports what it holds, storing nothing yet.
	 */
	preview: ( file: File ): Promise< SubmissionPreviewResponse > => {
		const body = new FormData();
		body.append( 'file', file );
		return apiFetch( {
			path: `${ BASE }/${ gameSlug }/submissions/preview`,
			method: 'POST',
			body,
		} );
	},

	/**
	 * Sends a file: picks the character (when the file holds more than one) and joining/visiting.
	 */
	create: (
		file: File,
		arrival: 'joining' | 'visiting',
		options?: { characterIndex?: number; homeChronicle?: string }
	): Promise< Submission > => {
		const body = new FormData();
		body.append( 'file', file );
		body.append( 'arrival', arrival );
		if ( options?.characterIndex !== undefined ) {
			body.append( 'character_index', String( options.characterIndex ) );
		}
		if ( options?.homeChronicle ) {
			body.append( 'home_chronicle', options.homeChronicle );
		}
		return apiFetch( {
			path: `${ BASE }/${ gameSlug }/submissions`,
			method: 'POST',
			body,
		} );
	},

	/**
	 * The sender withdraws their own still-waiting submission.
	 */
	withdraw: ( submissionId: number ): Promise< Submission > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/submissions/${ submissionId }/withdraw`,
			method: 'POST',
		} ),

	/**
	 * Storyteller reviews a waiting submission, shown like a parsed import file.
	 */
	review: ( submissionId: number ): Promise< SubmissionReview > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/submissions/${ submissionId }/review`,
		} ),

	/**
	 * Checks a waiting submission's own verification code, if it carries one.
	 */
	verification: ( submissionId: number ): Promise< SubmissionVerification > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/submissions/${ submissionId }/verification`,
		} ),

	/**
	 * Storyteller accepts a waiting submission with the same decisions an import commit takes.
	 */
	accept: (
		submissionId: number,
		resolutions: ImportResolutions,
		arrival?: 'joining' | 'visiting'
	): Promise< SubmissionAcceptResult > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/submissions/${ submissionId }/accept`,
			method: 'POST',
			data: { resolutions, ...( arrival ? { arrival } : {} ) },
		} ),

	/**
	 * Storyteller refuses a waiting submission.
	 */
	refuse: ( submissionId: number, note?: string ): Promise< Submission > =>
		apiFetch( {
			path: `${ BASE }/${ gameSlug }/submissions/${ submissionId }/refuse`,
			method: 'POST',
			data: note ? { note } : {},
		} ),
} );

/**
 * The caller's own last submissions across every chronicle they've sent one to, newest first.
 */
export const mySubmissions = () => ( {
	list: (): Promise< Submission[] > =>
		apiFetch( { path: `${ BASE }/my/submissions` } ),
} );

// ---------------------------------------------------------------------------
// Verification
// ---------------------------------------------------------------------------

/**
 * Resolves a short verification code (minted by exporting a character with the `verify` option) to what was attested
 * at issue time, plus whether it still matches the character today.
 */
export const verification = () => ( {
	resolve: ( code: string ): Promise< VerifyResponse > =>
		apiFetch( {
			path: `${ BASE }/verify/${ encodeURIComponent( code ) }`,
		} ),
} );

// ---------------------------------------------------------------------------
// Translations — site-wide
// ---------------------------------------------------------------------------

export interface TranslationUsage {
	block: string;
	section_type: string;
	role: string;
}

export type TranslationStatus =
	| 'draft'
	| 'needs_review'
	| 'approved'
	| 'conflict';

/**
 * One row of `list()`: a catalog term left-joined with its translation for the requested locale.
 */
export interface TranslationRow {
	id: string;
	source_key: string;
	source_text: string;
	used_in: TranslationUsage[];
	first_seen: string;
	last_seen: string | null;
	translation_id: string | null;
	translation: string | null;
	status: TranslationStatus | null;
	context: string | null;
}

export interface TranslationFilters {
	status?: 'untranslated' | TranslationStatus;
	block?: string;
	search?: string;
	has_translation?: boolean;
}

export interface TranslationStats {
	locale: string;
	total: number;
	translated: number;
	untranslated: number;
	by_status: Record< TranslationStatus, number >;
	by_block: Record< string, { total: number; translated: number } >;
}

export interface TranslationLocales {
	/**
	 * Locales with at least one real translation row already.
	 */
	with_rows: string[];
	/**
	 * Every locale WordPress itself has installed, en_US always first.
	 */
	installed: string[];
}

export interface TranslationBulkRow {
	source_text: string;
	translation: string;
	status?: TranslationStatus;
}

export interface TranslationBulkResult {
	updated: number;
	skipped: number;
}

export interface TranslationImportSample {
	source_text: string;
	outcome: 'added' | 'updated' | 'unmatched' | 'conflict';
	existing?: string;
	incoming?: string;
}

export interface TranslationImportResult {
	added: number;
	updated: number;
	unchanged: number;
	unmatched: number;
	conflicts: number;
	sample: TranslationImportSample[];
	dry_run: boolean;
}

/**
 * REST client for catalog term translation.
 */
export const translations = {
	/**
	 * One page of catalog terms for `locale`, left-joined with their translation.
	 */
	list: (
		locale: string,
		filters: TranslationFilters = {},
		page = 1,
		perPage = 100
	): Promise< {
		items: TranslationRow[];
		total: number;
		totalPages: number;
	} > =>
		fetchPage< TranslationRow >( {
			path: `${ BASE }/translations${ toQuery( {
				locale,
				...filters,
				page,
				per_page: perPage,
			} as Record< string, unknown > ) }`,
		} ),

	/**
	 * Per-locale totals, per-status counts, and a per-block breakdown, for the progress display.
	 */
	stats: ( locale: string ): Promise< TranslationStats > =>
		apiFetch( {
			path: `${ BASE }/translations/progress${ toQuery( { locale } ) }`,
		} ),

	/**
	 * Locales with real rows already, plus every locale WordPress itself has installed.
	 */
	locales: (): Promise< TranslationLocales > =>
		apiFetch( { path: `${ BASE }/translations/locales` } ),

	/**
	 * Creates or replaces one term's translation for a locale, by an existing string_id or a bare source_text.
	 */
	save: (
		locale: string,
		term: { stringId?: string; sourceText?: string },
		translation: string,
		status: TranslationStatus = 'draft'
	): Promise< {
		id: string;
		string_id: string;
		locale: string;
		translation: string;
		status: TranslationStatus;
	} > =>
		apiFetch( {
			path: `${ BASE }/translations`,
			method: 'POST',
			data: {
				locale,
				string_id: term.stringId,
				source_text: term.sourceText,
				translation,
				status,
			},
		} ),

	/**
	 * Updates an existing translation row's own translation text and/or status.
	 */
	update: (
		id: string,
		data: Partial< { translation: string; status: TranslationStatus } >
	): Promise< unknown > =>
		apiFetch( {
			path: `${ BASE }/translations/${ id }`,
			method: 'PATCH',
			data,
		} ),

	remove: ( id: string ): Promise< void > =>
		apiFetch( {
			path: `${ BASE }/translations/${ id }`,
			method: 'DELETE',
		} ),

	/**
	 * Bulk-sets many rows at once by source_text.
	 */
	bulk: (
		locale: string,
		rows: TranslationBulkRow[]
	): Promise< TranslationBulkResult > =>
		apiFetch( {
			path: `${ BASE }/translations/bulk`,
			method: 'POST',
			data: { locale, rows },
		} ),

	/**
	 * Builds the CSV export download URL for the current filters, matching `list()`'s own filter vocabulary exactly
	 * ("honouring the same filters as the list").
	 */
	exportUrl: ( locale: string, filters: TranslationFilters = {} ): string => {
		const params = new URLSearchParams( { locale } );
		if ( filters.status ) {
			params.set( 'status', filters.status );
		}
		if ( filters.block ) {
			params.set( 'block', filters.block );
		}
		if ( filters.search ) {
			params.set( 'search', filters.search );
		}
		if ( filters.has_translation !== undefined ) {
			params.set(
				'has_translation',
				filters.has_translation ? '1' : '0'
			);
		}
		params.set( '_wpnonce', window.beyondElysium?.nonce ?? '' );

		const root =
			window.beyondElysium?.restUrl ??
			`${ window.location.origin }/wp-json/be/v1/`;
		return `${ root }translations/export?${ params.toString() }`;
	},

	/**
	 * Uploads a CSV for import.
	 */
	import: (
		locale: string,
		file: File,
		dryRun: boolean
	): Promise< TranslationImportResult > => {
		const body = new FormData();
		body.append( 'locale', locale );
		body.append( 'dry_run', dryRun ? '1' : '0' );
		body.append( 'file', file );
		return apiFetch( {
			path: `${ BASE }/translations/import-csv`,
			method: 'POST',
			body,
		} );
	},

	/**
	 * Re-walks the real catalog and refreshes the string index against it.
	 */
	rescan: (): Promise< {
		added: number;
		updated: number;
		orphaned: number;
	} > =>
		apiFetch( { path: `${ BASE }/translations/rescan`, method: 'POST' } ),
};

// ---------------------------------------------------------------------------
// Default export: grouped API object
// ---------------------------------------------------------------------------

/**
 * The full REST API client, grouping every resource's client object under one default export for convenient
 * single-import usage throughout the plugin.
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
	sheets,
	reports,
	experience,
	resourcePools,
	plots,
	apr,
	plotEntries,
	connections,
	queryFields,
	query,
	worldObjects,
	locations,
	secrets,
	factions,
	positions,
	attachments,
	sessions,
	castings,
	releaseBatches,
	downtime,
	myQueue,
	npcs,
	boons,
	gexImport,
	gameImport,
	verification,
	transfers,
	submissions,
	mySubmissions,
	wpUsers,
	gameMembers,
	authorizationSettings,
	dataManagement,
	gameStats,
	setupStatus,
	docs,
	credits,
	aiAssist,
	aiAssistSite,
	signing,
	translations,
};
export default api;
