import {
	asTrait,
	costWords,
	familyCost,
	groupEntries,
	heldInSection,
	heldWords,
	isFamily,
	withNotes,
} from './compendium';
import type { CompendiumFamily, CompendiumItem } from '../types/compendium';

function item( over: Partial< CompendiumItem > = {} ): CompendiumItem {
	return {
		name: 'Occult',
		name_pt: null,
		group: null,
		subgroup: null,
		tier: null,
		cost: '1',
		book: null,
		description: null,
		citation: '',
		prerequisites: [],
		...over,
	};
}

const family: CompendiumFamily = {
	name: 'Auspex',
	name_pt: null,
	group: null,
	book: null,
	description: null,
	citation: '',
	levels: [
		{
			level: 1,
			tier: 'basic',
			power_name: 'Heightened Senses',
			power_name_pt: null,
			cost: '3',
			description: null,
		},
	],
};

describe( 'groupEntries', () => {
	it( 'puts entries under their group and subgroup in the order first met', () => {
		const groups = groupEntries( [
			item( { name: 'A', group: 'Knowledges', subgroup: 'Lore' } ),
			item( { name: 'B', group: 'Skills', subgroup: null } ),
			item( { name: 'C', group: 'Knowledges', subgroup: 'Lore' } ),
			item( { name: 'D', group: 'Knowledges', subgroup: 'Science' } ),
		] );

		expect( groups.map( ( g ) => g.group ) ).toEqual( [
			'Knowledges',
			'Skills',
		] );
		expect(
			groups[ 0 ].subgroups.map( ( s ) => [
				s.subgroup,
				s.entries.map( ( e ) => e.name ),
			] )
		).toEqual( [
			[ 'Lore', [ 'A', 'C' ] ],
			[ 'Science', [ 'D' ] ],
		] );
	} );

	it( 'puts entries with no group first', () => {
		const groups = groupEntries( [
			item( { name: 'A', group: 'Skills' } ),
			item( { name: 'B', group: null } ),
		] );

		expect( groups.map( ( g ) => g.group ) ).toEqual( [ '', 'Skills' ] );
	} );
} );

describe( 'withNotes', () => {
	it( 'keeps only the entries the chronicle wrote a note on', () => {
		const kept = withNotes( [
			item( { name: 'Plain' } ),
			item( { name: 'Noted', description: '<p>House rule.</p>' } ),
			{ ...family, description: 'Family note.' },
		] );

		expect( kept.map( ( e ) => e.name ) ).toEqual( [ 'Noted', 'Auspex' ] );
	} );
} );

describe( 'costWords', () => {
	it( 'says a plain number as XP and any other expression as written', () => {
		expect( costWords( '3' ) ).toBe( '3 XP' );
		expect( costWords( '3 per level' ) ).toBe( '3 per level' );
		expect( costWords( '' ) ).toBe( '' );
		expect( costWords( null ) ).toBe( '' );
	} );

	it( 'prices a family at its first level', () => {
		expect( familyCost( family ) ).toBe( '3 XP' );
		expect( familyCost( { ...family, levels: [] } ) ).toBe( '' );
	} );
} );

describe( 'what a character holds', () => {
	const section = {
		blockSlug: 'vampire-merits',
		title: 'Merits',
		sectionType: 'trait_list',
	};

	it( 'finds held traits by name and says how much', () => {
		const held = heldInSection( section, {
			'vampire-merits': [
				{ name: 'Iron Will', count: 3 },
				{ name: 'Eidetic Memory', count: 1 },
			],
		} );

		expect( heldWords( held.get( 'Iron Will' ) ) ).toBe( 'Held x3' );
		expect( heldWords( held.get( 'Eidetic Memory' ) ) ).toBe( 'Held' );
		expect( heldWords( held.get( 'Never Bought' ) ) ).toBe( '' );
	} );

	it( 'says a power by its level', () => {
		const held = heldInSection(
			{
				blockSlug: 'vampire-disciplines',
				title: 'Disciplines',
				sectionType: 'tiered_power',
			},
			{ 'vampire-disciplines': [ { name: 'Auspex', level: 2 } ] }
		);

		expect( heldWords( held.get( 'Auspex' ) ) ).toBe( 'Held, level 2' );
	} );

	it( 'opens the trait panel on an entry the character does not hold', () => {
		expect( asTrait( section, item( { name: 'Iron Will' } ) ) ).toEqual( {
			blockSlug: 'vampire-merits',
			section: 'Merits',
			sectionType: 'trait_list',
			name: 'Iron Will',
			rating: null,
			shown: 'Iron Will',
		} );
		expect( isFamily( family ) ).toBe( true );
		expect( isFamily( item() ) ).toBe( false );
	} );
} );
