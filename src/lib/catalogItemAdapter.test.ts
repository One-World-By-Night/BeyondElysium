import { catalogEntryToDuplicateSource } from './catalogItemAdapter';
import type { CatalogItemEntry } from '../types/world';

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

describe( 'catalogEntryToDuplicateSource', () => {
	it( 'carries the name and description across', () => {
		const source = catalogEntryToDuplicateSource( entry() );
		expect( source.name ).toBe( 'Broken Bottle' );
		expect( source.description ).toBe( 'A jagged, improvised weapon.' );
	} );

	it( 'stamps the book_ref onto properties alongside the book data', () => {
		const source = catalogEntryToDuplicateSource( entry() );
		expect( source.properties.bonus ).toBe( 1 );
		expect( source.properties.item_type ).toBe( 'Melee' );
		expect( source.properties.book_ref ).toBe( 'dark-epics:broken-bottle' );
	} );

	it( 'carries no real database id, audience, or ownership', () => {
		const source = catalogEntryToDuplicateSource( entry() );
		expect( source.id ).toBe( 0 );
		expect( source.game_id ).toBe( 0 );
		expect( source.audience ).toBe( 'everyone' );
		expect( source.based_on_id ).toBeNull();
	} );

	it( 'falls back to a null description when the entry has none', () => {
		const source = catalogEntryToDuplicateSource(
			entry( { description: undefined } )
		);
		expect( source.description ).toBeNull();
	} );
} );
