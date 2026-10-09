/**
 * Turns a declared catalog entry into the shape `WorldObjectEditor`'s own `duplicateFrom` prop expects, so "Add from
 * the book" reuses the exact same prefilled-create-form mechanism Duplicate already does.
 */
import type { CatalogItemEntry, WorldObject } from '../types/world';

export function catalogEntryToDuplicateSource(
	entry: CatalogItemEntry
): WorldObject {
	return {
		id: 0,
		game_id: 0,
		object_type: entry.object_type,
		name: entry.name,
		description: entry.description ?? null,
		rarity: null,
		cost: null,
		limitations: null,
		properties: {
			...entry.properties,
			book_ref: entry.book_ref,
		},
		audience: 'everyone',
		audience_rules: null,
		parent_id: null,
		based_on_id: null,
		created_by: 0,
		created_at: '',
		updated_at: '',
	};
}
