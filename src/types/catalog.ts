/**
 * Type definitions for the Storyteller Toolkit's Catalog tab: the sections that take entries of a chronicle's own, and
 * each section's entries.
 */
import type { CatalogDescription } from './index';

/**
 * What a section's entries are: items on a list, families with levels and picks on a power list with a ladder, or
 * families of picks on a power list without one.
 */
export type CatalogKind = 'item' | 'family' | 'pick';

export interface CatalogInTypeMap {
	/**
	 * The map the section's test reads, as "block_slug.key".
	 */
	map: string;
	by: string[];
	keys: string[];
}

export interface CatalogInTypeFacet {
	facet: string;
	options: string[];
}

export interface CatalogInTypeChosen {
	field: string;
	is: string[];
}

export interface CatalogInType {
	maps: CatalogInTypeMap[];
	facets: CatalogInTypeFacet[];
	chosen: CatalogInTypeChosen[];
}

export interface CatalogLadderRung {
	level: number;
	tier: string;
	cost: number;
}

export interface CatalogSection {
	block_slug: string;
	label: string;
	kind: CatalogKind;
	priced_by: 'dot' | 'pick' | 'rank';
	blood_magic: boolean;
	traditions: string[];
	in_type: CatalogInType;
	groups?: string[];
	name_ranks?: string[];
	name_form?: boolean;
	ranks?: string[];
	costs?: Record< string, number >;
	out_of_type?: Record< string, string >;
	ladder?: CatalogLadderRung[];
	pick_ranks?: string[];
}

export interface CatalogCreatureType {
	stack: string;
	name: string;
	sections: CatalogSection[];
}

export interface CatalogSectionsResponse {
	creature_types: CatalogCreatureType[];
}

/**
 * Where an entry came from: the book, added by the chronicle, or the book's own entry as the chronicle changed it.
 */
export type CatalogOrigin = 'book' | 'added' | 'changed';

/**
 * A change the chronicle made that the book has since changed under.
 */
export interface CatalogEntryFlag {
	path: Array< string | number | Array< string | number > >;
	removed: boolean;
	was: unknown;
	now: unknown;
	yours: unknown;
	was_set: boolean | null;
	now_set: boolean | null;
	yours_set: boolean | null;
}

export interface CatalogHeld {
	characters: number;
	changes: number;
}

export interface CatalogItemEntry {
	name: string;
	name_pt: string | null;
	cost: string;
	group: string;
	tier: string;
	base_name: string;
	description: CatalogDescription | null;
	origin: CatalogOrigin;
	flagged: boolean;
	corrections: CatalogEntryFlag[];
	etag: string;
	in_use: CatalogHeld;
	in_use_note: string | null;
}

export interface CatalogLevel {
	level: number;
	tier: string;
	cost: string;
	power_name: string;
	description: CatalogDescription | null;
}

export interface CatalogPick {
	rank: string;
	power_name: string;
	name_pt: string | null;
	cost: string;
	description: CatalogDescription | null;
	origin: 'book' | 'added';
	in_use: CatalogHeld;
}

export interface CatalogFamilyEntry {
	name: string;
	name_pt: string | null;
	description: CatalogDescription | null;
	levels: CatalogLevel[];
	picks: CatalogPick[];
	category_values: Record< string, string >;
	traditions: Record< string, string | null >;
	in_clan: Record< string, string[] >;
	origin: CatalogOrigin;
	flagged: boolean;
	corrections: CatalogEntryFlag[];
	etag: string;
	in_use: CatalogHeld;
	in_use_note: string | null;
}

export type CatalogEntry = CatalogItemEntry | CatalogFamilyEntry;

export interface CatalogListing {
	block_slug: string;
	kind: CatalogKind;
	entries: CatalogEntry[];
}
