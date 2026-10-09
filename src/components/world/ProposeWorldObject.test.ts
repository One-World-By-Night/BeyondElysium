import { catalogEntryToProposalSeed } from './ProposeWorldObject';
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

describe( 'catalogEntryToProposalSeed', () => {
	it( 'carries the name and description across', () => {
		const seed = catalogEntryToProposalSeed( entry() );
		expect( seed.name ).toBe( 'Broken Bottle' );
		expect( seed.description ).toBe( 'A jagged, improvised weapon.' );
	} );

	it( 'stamps the book_ref onto properties alongside the book data', () => {
		const seed = catalogEntryToProposalSeed( entry() );
		expect( seed.properties.bonus ).toBe( 1 );
		expect( seed.properties.item_type ).toBe( 'Melee' );
		expect( seed.properties.book_ref ).toBe( 'dark-epics:broken-bottle' );
	} );

	it( 'falls back to an empty description when the entry has none', () => {
		const seed = catalogEntryToProposalSeed(
			entry( { description: undefined } )
		);
		expect( seed.description ).toBe( '' );
	} );
} );
