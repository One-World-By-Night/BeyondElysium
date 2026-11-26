import { computeChanges } from './computeChanges';
import { removeLine } from './cartRemove';
import type { SchemaBlock } from '../types';
import type { SheetData } from '../types/character';

function block(
	slug: string,
	sectionType: SchemaBlock[ 'section_type' ]
): SchemaBlock {
	return {
		id: 1,
		slug,
		name: slug,
		section_type: sectionType,
		definition:
			sectionType === 'trait_list'
				? { items: [] }
				: ( {} as SchemaBlock[ 'definition' ] ),
		is_system: true,
		version: 1,
		created_by: 1,
		created_at: '',
		updated_at: '',
	};
}

const blocks = {
	abilities: block( 'abilities', 'trait_list' ),
	disciplines: block( 'disciplines', 'tiered_power' ),
	resources: block( 'resources', 'resource_pool' ),
	identity: block( 'identity', 'identity_field' ),
};

describe( 'removeLine - a trait list', () => {
	const original: SheetData = {
		abilities: [
			{ name: 'Occult', count: 3 },
			{ name: 'Brawl', count: 2 },
		],
	};
	const edited: SheetData = {
		abilities: [
			{ name: 'Occult', count: 4 },
			{ name: 'Athletics', count: 1 },
		],
	};

	it( 'lists the changes in the order the cart shows them', () => {
		expect(
			computeChanges( original, edited, blocks ).map(
				( c ) => c.change_type
			)
		).toEqual( [ 'modify_trait', 'add_trait', 'remove_trait' ] );
	} );

	it( 'puts a raised trait back and leaves the other lines', () => {
		const result = removeLine( original, edited, blocks, 0 );

		expect( result.exact ).toBe( true );
		expect( result.sheet.abilities ).toEqual( [
			{ name: 'Occult', count: 3 },
			{ name: 'Athletics', count: 1 },
		] );
		expect(
			computeChanges( original, result.sheet, blocks ).map(
				( c ) => c.change_type
			)
		).toEqual( [ 'add_trait', 'remove_trait' ] );
	} );

	it( 'takes an added trait out', () => {
		const result = removeLine( original, edited, blocks, 1 );

		expect( result.exact ).toBe( true );
		expect( result.sheet.abilities ).toEqual( [
			{ name: 'Occult', count: 4 },
		] );
	} );

	it( 'brings a removed trait back where it was', () => {
		const result = removeLine( original, edited, blocks, 2 );

		expect( result.exact ).toBe( true );
		expect( result.sheet.abilities ).toEqual( [
			{ name: 'Occult', count: 4 },
			{ name: 'Brawl', count: 2 },
			{ name: 'Athletics', count: 1 },
		] );
	} );

	it( 'brings back a trait the editor marked removed', () => {
		const marked: SheetData = {
			abilities: [
				{ name: 'Occult', count: 3 },
				{ name: 'Brawl', count: 2, _removed: true },
			],
		};
		const result = removeLine( original, marked, blocks, 0 );

		expect( result.exact ).toBe( true );
		expect( computeChanges( original, result.sheet, blocks ) ).toEqual(
			[]
		);
	} );

	it( 'does not touch the sheets it was given', () => {
		const before = JSON.stringify( [ original, edited ] );
		removeLine( original, edited, blocks, 0 );
		expect( JSON.stringify( [ original, edited ] ) ).toBe( before );
	} );

	it( 'leaves the sheet alone for a line that is not in the cart', () => {
		const result = removeLine( original, edited, blocks, 9 );
		expect( result.sheet ).toBe( edited );
	} );
} );

describe( 'removeLine - a tiered power', () => {
	it( 'takes one added pick out and keeps the other', () => {
		const original: SheetData = { disciplines: [] };
		const edited: SheetData = {
			disciplines: [
				{ name: 'Auspex', level: 2 },
				{ name: 'Celerity', level: 1 },
			],
		};

		const result = removeLine( original, edited, blocks, 0 );

		expect( result.exact ).toBe( true );
		expect( result.sheet.disciplines ).toEqual( [
			{ name: 'Celerity', level: 1 },
		] );
	} );

	it( 'puts a level back', () => {
		const original: SheetData = {
			disciplines: [ { name: 'Auspex', level: 2 } ],
		};
		const edited: SheetData = {
			disciplines: [ { name: 'Auspex', level: 3 } ],
		};

		const result = removeLine( original, edited, blocks, 0 );

		expect( result.sheet.disciplines ).toEqual( [
			{ name: 'Auspex', level: 2 },
		] );
	} );
} );

describe( 'removeLine - pools and identity fields', () => {
	it( 'puts one pool back and keeps the other', () => {
		const original: SheetData = {
			resources: {
				Willpower: { permanent: 3, temporary: 3 },
				Blood: { permanent: 10, temporary: 10 },
			},
		};
		const edited: SheetData = {
			resources: {
				Willpower: { permanent: 4, temporary: 3 },
				Blood: { permanent: 11, temporary: 10 },
			},
		};

		const result = removeLine( original, edited, blocks, 0 );

		expect( result.exact ).toBe( true );
		expect( result.sheet.resources ).toEqual( {
			Willpower: { permanent: 3, temporary: 3 },
			Blood: { permanent: 11, temporary: 10 },
		} );
	} );

	it( 'puts one identity field back and keeps the other', () => {
		const original: SheetData = {
			identity: { Clan: 'Tremere', Sect: 'Camarilla' },
		};
		const edited: SheetData = {
			identity: { Clan: 'Ventrue', Sect: 'Sabbat' },
		};

		const result = removeLine( original, edited, blocks, 1 );

		expect( result.exact ).toBe( true );
		expect( result.sheet.identity ).toEqual( {
			Clan: 'Ventrue',
			Sect: 'Camarilla',
		} );
	} );
} );
