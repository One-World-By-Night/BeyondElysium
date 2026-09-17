/**
 * Type definitions for world objects - items, locations, rotes,
 * and boons - shared across a chronicle. Covers the base
 * WorldObject shape, boon-specific extensions and the harpy
 * ledger, and the per-object-type property schema used by the
 * editor form.
 */
import type { AudienceValue, AudienceRules } from './plot';
import type { Attachment } from './attachment';

/**
 * The kind of world object: a physical item, a location, a mage
 * rote, or a boon owed between characters.
 */
export type ObjectType = 'item' | 'location' | 'rote' | 'boon';

/**
 * A character linked to a world object, such as an item's current
 * owner. Carries the character's id and name plus an optional
 * label describing the nature of the link.
 */
export interface ConnectedCharacter {
	id: number;
	name: string;
	label: string | null;
}

/**
 * One of a location's four named links (1.1.0 §3.9 item 2): who owns it, whose domain it
 * is, whose haven it is, or who's based there. Deliberately separate from the freeform
 * `ConnectedCharacter` above - a location link always has one of these four exact labels.
 */
export type LocationLinkLabel = 'owner' | 'domain' | 'haven' | 'based_at';

/** One location link, as `Locations_Controller::shape_link()` returns it. */
export interface LocationLink {
	id: number;
	label: LocationLinkLabel;
	source_type: 'character';
	source_id: number;
	name: string;
}

/** A location's own bare ancestor/child entry - just enough for a breadcrumb or a nested list. */
export interface LocationSummary {
	id: number;
	name: string;
}

/**
 * The display-over-Grapevine-text substitution (1.1.0 §3.9 item 3): the linked owner's name
 * and the parent location's name, wherever a real link exists, falling back to the location's
 * own typed `owner`/`where` properties otherwise. Only present on a location's single-object
 * fetch.
 */
export interface LocationDisplay {
	owner: string;
	where: string;
}

/**
 * A single item, location, or rote belonging to a chronicle.
 * Holds its display fields plus a free-form properties bag whose
 * shape depends on object_type, matching WORLD_OBJECT_SCHEMAS.
 */
export interface WorldObject {
	id: number;
	game_id: number;
	object_type: ObjectType;
	name: string;
	description: string | null;
	rarity: string | null;
	cost: string | null;
	limitations: string | null;
	properties: Record< string, unknown >;
	/** Meaningful for item/location only; ignored by the audience filter for rote/boon (1.1.0 §2.5). */
	audience: AudienceValue;
	/** Only meaningful when audience is `restricted`; null otherwise. */
	audience_rules: AudienceRules | null;
	/** "Inside of" (1.1.0 §3.9 item 1) - a location nested inside another; locations only. */
	parent_id: number | null;
	/** The source item this one was copied from (1.1.0 §3.12 item 1); items only. */
	based_on_id: number | null;
	created_by: number;
	created_at: string;
	updated_at: string;
	/** Only present on the single-object fetch. */
	connected_characters?: ConnectedCharacter[];
	/** Only present on the single-object fetch, and only for item/location. */
	attachments?: Attachment[];
	/** Only present on a location's single-object fetch. Nearest ancestor first. */
	ancestors?: LocationSummary[];
	/** Only present on a location's single-object fetch. */
	children?: LocationSummary[];
	/** Only present on a location's single-object fetch. */
	display?: LocationDisplay;
	/** Only present on a single-object fetch of a copy (1.1.0 §3.12 item 1) - the source's own id and name, or null if the source has since been deleted. */
	based_on?: { id: number; name: string } | null;
	/** Items only (1.1.0 §3.12 item 2) - derived from `uses_max`/`uses_left`, never stored. */
	used_up?: boolean;
	/** Items only (1.1.0 §3.12 item 2) - derived from `expires_on`, never stored. */
	expired?: boolean;
}

/** One entry in an item's own history (1.1.0 §3.12 item 3). */
export interface ItemEvent {
	id: number;
	event:
		| 'given'
		| 'taken'
		| 'traded'
		| 'stolen'
		| 'lost'
		| 'used'
		| 'copied'
		| 'proposed'
		| 'adjusted';
	character_id: number | null;
	from_character_id: number | null;
	note: string | null;
	recorded_by: number;
	created_at: string;
}

/** How an item changed hands (1.1.0 §3.12 item 4). */
export type ItemTransferHow = 'given' | 'traded' | 'stolen' | 'lost';

/** Request body for transferring an item to a new character, or losing it. */
export interface TransferItemRequest {
	to_character_id?: number | null;
	how: ItemTransferHow;
	note?: string;
}

/** Request body for spending one use of an item (1.1.0 §3.12 item 2). */
export interface UseItemRequest {
	character_id: number;
	note?: string;
}

/**
 * Request body for copying an item for a specific character (1.1.0 §3.12 item 1). `name`
 * defaults to the source item's own name when omitted.
 */
export interface CopyForCharacterRequest {
	character_id: number;
	name?: string;
}

/** Which copies a world-object listing should include (1.1.0 §3.12 item 1). Default 'exclude'. */
export type CopiesFilter = 'exclude' | 'only' | 'include';

/**
 * Request body for creating a new world object. object_type and
 * name are required; every other field, including the type-
 * specific properties bag, is optional.
 */
export interface CreateWorldObjectRequest {
	object_type: ObjectType;
	name: string;
	description?: string;
	rarity?: string;
	cost?: string;
	limitations?: string;
	properties?: Record< string, unknown >;
	audience?: AudienceValue;
	audience_rules?: AudienceRules | null;
	/** Locations only - the location this one is "inside of." */
	parent_id?: number | null;
}

/**
 * Request body for updating an existing world object. Every field
 * is optional; only the fields included in the request are
 * changed.
 */
export interface UpdateWorldObjectRequest {
	name?: string;
	description?: string;
	rarity?: string;
	cost?: string;
	limitations?: string;
	properties?: Record< string, unknown >;
	audience?: AudienceValue;
	audience_rules?: AudienceRules | null;
	/** Locations only - the location this one is "inside of." null clears it. */
	parent_id?: number | null;
}

/**
 * Query parameters accepted by the world objects collection
 * endpoint. Supports pagination, filtering by object type or
 * rarity, a free-text search, and arbitrary property filters
 * keyed by property name.
 */
export interface WorldObjectCollectionParams {
	object_type?: ObjectType;
	rarity?: string;
	search?: string;
	/** Which copies to include (1.1.0 §3.12 item 1). Default 'exclude'. */
	copies?: CopiesFilter;
	page?: number;
	per_page?: number;
	[ propertyFilter: string ]: unknown;
}

/**
 * One side of a boon: the character who owes it or the character
 * to whom it is owed, identified by id and display name.
 */
export interface BoonParty {
	id: number;
	name: string;
}

/**
 * A single boon owed between two characters. Extends WorldObject
 * with the two parties involved; object_type is always fixed to
 * "boon".
 */
export interface Boon extends WorldObject {
	object_type: 'boon';
	owed_by: BoonParty;
	owed_to: BoonParty;
}

/**
 * Request body for recording a new boon. Identifies both
 * characters involved and the boon's level; terms and boon_date
 * are optional supporting detail.
 */
export interface CreateBoonRequest {
	owed_by_character_id: number;
	owed_to_character_id: number;
	boon_level: string;
	terms?: string;
	boon_date?: string;
}

/**
 * Query parameters for the harpy ledger view of boons. Supports
 * filtering by the character involved, boon level, and repayment
 * status.
 */
export interface BoonLedgerParams {
	character_id?: number;
	level?: string;
	status?: string;
}

/**
 * The editable property schema for each object type, used to
 * render the world object editor form. Maps each object_type to
 * its set of property names and the input type each one uses.
 */
export const WORLD_OBJECT_SCHEMAS: Record<
	ObjectType,
	Record< string, 'string' | 'text' | 'int' | 'date' | 'trait_list' >
> = {
	item: {
		item_type: 'string',
		item_subtype: 'string',
		level: 'int',
		bonus: 'int',
		damage_type: 'string',
		damage_amount: 'int',
		concealability: 'string',
		powers: 'text',
		appearance: 'text',
		tempers: 'trait_list',
		negatives: 'trait_list',
		abilities: 'trait_list',
		availability: 'trait_list',
		// 1.1.0 §3.12 item 2 - used_up/expired derive from these, never stored.
		uses_max: 'int',
		uses_left: 'int',
		expires_on: 'date',
	},
	location: {
		location_type: 'string',
		level: 'int',
		owner: 'string',
		where: 'string',
		appearance: 'text',
		access: 'string',
		security: 'string',
		security_traits: 'int',
		security_retests: 'int',
		gauntlet: 'int',
		umbra: 'string',
		affinity: 'string',
		totem: 'string',
		links: 'trait_list',
	},
	rote: {
		level: 'int',
		duration: 'string',
		description: 'text',
		grades: 'string',
		spheres: 'trait_list',
	},
	boon: {
		boon_level: 'string',
		boon_date: 'date',
		terms: 'text',
		status: 'string',
		repaid_date: 'date',
	},
};
