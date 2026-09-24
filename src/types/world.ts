/**
 * Type definitions for world objects.
 */
import type { AudienceValue, AudienceRules } from './plot';
import type { Attachment } from './attachment';

/**
 * The kind of world object: a physical item, a location, a mage rote, or a boon owed between characters.
 */
export type ObjectType = 'item' | 'location' | 'rote' | 'boon';

/**
 * A character linked to a world object, such as an item's current owner: the character's id and name plus an optional
 * label describing the link.
 */
export interface ConnectedCharacter {
	id: number;
	name: string;
	label: string | null;
}

/**
 * One of a location's four named links: who owns it, whose domain it is, whose haven it is, or who's based there.
 */
export type LocationLinkLabel = 'owner' | 'domain' | 'haven' | 'based_at';

/**
 * One location link, as `Locations_Controller::shape_link()` returns it.
 */
export interface LocationLink {
	id: number;
	label: LocationLinkLabel;
	source_type: 'character';
	source_id: number;
	name: string;
}

/**
 * A location's own bare ancestor/child entry.
 */
export interface LocationSummary {
	id: number;
	name: string;
}

/**
 * The linked owner's name and the parent location's name, wherever a real link exists, falling back to the location's
 * own typed `owner` and `where` properties.
 */
export interface LocationDisplay {
	owner: string;
	where: string;
}

/**
 * A single item, location, or rote belonging to a chronicle.
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
	/**
	 * Meaningful for item/location only.
	 */
	audience: AudienceValue;
	/**
	 * Only meaningful when audience is `restricted`.
	 */
	audience_rules: AudienceRules | null;
	/**
	 * "Inside of" - a location nested inside another.
	 */
	parent_id: number | null;
	/**
	 * The source item this one was copied from.
	 */
	based_on_id: number | null;
	created_by: number;
	created_at: string;
	updated_at: string;
	/**
	 * Only present on the single-object fetch.
	 */
	connected_characters?: ConnectedCharacter[];
	/**
	 * Only present on the single-object fetch, and only for item/location.
	 */
	attachments?: Attachment[];
	/**
	 * Only present on a location's single-object fetch.
	 */
	ancestors?: LocationSummary[];
	/**
	 * Only present on a location's single-object fetch.
	 */
	children?: LocationSummary[];
	/**
	 * Only present on a location's single-object fetch.
	 */
	display?: LocationDisplay;
	/**
	 * Only present on a single-object fetch of a copy.
	 */
	based_on?: { id: number; name: string } | null;
	/**
	 * Items only - derived from `uses_max`/`uses_left`.
	 */
	used_up?: boolean;
	/**
	 * Items only - derived from `expires_on`.
	 */
	expired?: boolean;
}

/**
 * One entry in an item's own history.
 */
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

/**
 * How an item changed hands.
 */
export type ItemTransferHow = 'given' | 'traded' | 'stolen' | 'lost';

/**
 * Request body for transferring an item to a new character, or losing it.
 */
export interface TransferItemRequest {
	to_character_id?: number | null;
	how: ItemTransferHow;
	note?: string;
}

/**
 * Request body for spending one use of an item.
 */
export interface UseItemRequest {
	character_id: number;
	note?: string;
}

/**
 * Request body for copying an item for a specific character.
 */
export interface CopyForCharacterRequest {
	character_id: number;
	name?: string;
}

/**
 * Which copies a world-object listing should include.
 */
export type CopiesFilter = 'exclude' | 'only' | 'include';

/**
 * Request body for creating a new world object. object_type and name are required.
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
	/**
	 * Locations only - the location this one is "inside of."
	 */
	parent_id?: number | null;
}

/**
 * Request body for updating an existing world object.
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
	/**
	 * Locations only - the location this one is "inside of." null clears it.
	 */
	parent_id?: number | null;
}

/**
 * Query parameters accepted by the world objects collection endpoint.
 */
export interface WorldObjectCollectionParams {
	object_type?: ObjectType;
	rarity?: string;
	search?: string;
	/**
	 * Which copies to include.
	 */
	copies?: CopiesFilter;
	page?: number;
	per_page?: number;
	[ propertyFilter: string ]: unknown;
}

/**
 * One side of a boon: the character who owes it or the character to whom it is owed, identified by id and display
 * name.
 */
export interface BoonParty {
	id: number;
	name: string;
}

/**
 * A single boon owed between two characters.
 */
export interface Boon extends WorldObject {
	object_type: 'boon';
	owed_by: BoonParty;
	owed_to: BoonParty;
}

/**
 * Request body for recording a new boon.
 */
export interface CreateBoonRequest {
	owed_by_character_id: number;
	owed_to_character_id: number;
	boon_level: string;
	terms?: string;
	boon_date?: string;
}

/**
 * Query parameters for the harpy ledger view of boons.
 */
export interface BoonLedgerParams {
	character_id?: number;
	level?: string;
	status?: string;
}

/**
 * The editable property schema for each object type, used to render the world object editor form.
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
		// used_up and expired derive from these.
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
