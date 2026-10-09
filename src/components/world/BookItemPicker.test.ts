import { availableItemTypes } from './BookItemPicker';
import type { CatalogItemEntry } from '../../types/world';

function entry(
	overrides: Partial< CatalogItemEntry > = {}
): CatalogItemEntry {
	return {
		key: 'broken-bottle',
		name: 'Broken Bottle',
		object_type: 'item',
		description: 'A jagged, improvised weapon.',
		properties: { bonus: 1, item_type: 'Melee' },
		source: { book: 'Dark Epics', code: 'WW05027', page: 83 },
		book: 'Dark Epics',
		book_slug: 'dark-epics',
		book_ref: 'dark-epics:broken-bottle',
		...overrides,
	};
}

describe( 'availableItemTypes', () => {
	it( 'lists each distinct item_type once, sorted', () => {
		const types = availableItemTypes( [
			entry( { properties: { item_type: 'Ranged' } } ),
			entry( { properties: { item_type: 'Melee' } } ),
			entry( { properties: { item_type: 'Melee' } } ),
		] );
		expect( types ).toEqual( [ 'Melee', 'Ranged' ] );
	} );

	it( 'drops an entry with no item_type instead of listing a blank option', () => {
		const types = availableItemTypes( [
			entry( { properties: { item_type: 'Shield' } } ),
			entry( { properties: {} } ),
		] );
		expect( types ).toEqual( [ 'Shield' ] );
	} );

	it( 'returns an empty list for no entries', () => {
		expect( availableItemTypes( [] ) ).toEqual( [] );
	} );
} );
