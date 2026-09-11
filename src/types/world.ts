/**
 * Type definitions for world objects - items, locations, rotes,
 * and boons - shared across a chronicle. Covers the base
 * WorldObject shape, boon-specific extensions and the harpy
 * ledger, and the per-object-type property schema used by the
 * editor form.
 */

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
	properties: Record<string, unknown>;
	created_by: number;
	created_at: string;
	updated_at: string;
	/** Only present on the single-object fetch. */
	connected_characters?: ConnectedCharacter[];
}

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
	properties?: Record<string, unknown>;
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
	properties?: Record<string, unknown>;
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
export const WORLD_OBJECT_SCHEMAS: Record<ObjectType, Record<string, 'string' | 'text' | 'int' | 'date' | 'trait_list'>> = {
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
