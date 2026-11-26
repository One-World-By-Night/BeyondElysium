import { highlightStMarkers } from './highlightStMarkers';
import { stripStSections } from './stripStSections';
import {
	catalogEntry,
	changesNaming,
	nextChange,
	priceText,
	visibleDescription,
} from './traitPanel';
import type { SchemaBlock } from '../types';
import type { CharacterChange } from '../types/character';

const abilities = {
	slug: 'vampire-abilities',
	section_type: 'trait_list',
	definition: {
		items: [
			{
				name: 'Occult',
				description: {
					description: '<p>Hidden lore.</p>',
					source: 'Laws of the Night Revised',
					reference: 'p. 97',
				},
				prerequisites: [
					{
						block_slug: 'vampire-disciplines',
						power: 'Auspex',
						min_level: 1,
					},
				],
			},
			{ name: 'Brawl' },
		],
	},
} as unknown as SchemaBlock;

const merits = {
	slug: 'vampire-merits',
	section_type: 'trait_list',
	definition: { atomic: true, items: [ { name: 'Iron Will' } ] },
} as unknown as SchemaBlock;

const disciplines = {
	slug: 'vampire-disciplines',
	section_type: 'tiered_power',
	definition: {
		powers: [
			{
				name: 'Animalism',
				source: 'GVM: Disciplines',
				description: { description: 'Beasts.' },
				levels: [
					{ level: 1, tier: 'basic', power_name: 'Feral Whispers' },
					{ level: 2, tier: 'basic', power_name: 'Beckoning' },
				],
			},
		],
	},
} as unknown as SchemaBlock;

describe( 'the change a raise by one would be', () => {
	it( 'raises a held counted trait by one', () => {
		const change = nextChange(
			{
				blockSlug: 'vampire-abilities',
				name: 'Occult',
				sectionType: 'trait_list',
			},
			abilities,
			[ { name: 'Occult', count: 4 } ]
		);
		expect( change?.change_type ).toBe( 'modify_trait' );
		expect( change?.change_data ).toMatchObject( {
			block_slug: 'vampire-abilities',
			trait: { name: 'Occult', count: 5 },
		} );
	} );

	it( 'adds a trait that is not held with its first dot', () => {
		const change = nextChange(
			{
				blockSlug: 'vampire-abilities',
				name: 'Brawl',
				sectionType: 'trait_list',
			},
			abilities,
			[ { name: 'Occult', count: 4 } ]
		);
		expect( change?.change_type ).toBe( 'add_trait' );
		expect( change?.change_data ).toMatchObject( {
			trait: { name: 'Brawl', count: 1 },
		} );
	} );

	it( 'raises a held ladder power by one level', () => {
		const change = nextChange(
			{
				blockSlug: 'vampire-disciplines',
				name: 'Animalism',
				sectionType: 'tiered_power',
			},
			disciplines,
			[ { name: 'Animalism', level: 1 } ]
		);
		expect( change?.change_type ).toBe( 'modify_trait' );
		expect( change?.change_data ).toMatchObject( {
			trait: { name: 'Animalism', level: 2 },
		} );
	} );

	it( 'has no raise for an atomic item, a row with no count, or a power held as a pick', () => {
		expect(
			nextChange(
				{
					blockSlug: 'vampire-merits',
					name: 'Iron Will',
					sectionType: 'trait_list',
				},
				merits,
				[ { name: 'Iron Will', count: 1 } ]
			)
		).toBeNull();
		expect(
			nextChange(
				{
					blockSlug: 'vampire-abilities',
					name: 'Occult',
					sectionType: 'trait_list',
				},
				abilities,
				[ { name: 'Occult' } ]
			)
		).toBeNull();
		expect(
			nextChange(
				{
					blockSlug: 'vampire-disciplines',
					name: 'Animalism',
					powerName: 'Feral Whispers',
					sectionType: 'tiered_power',
				},
				disciplines,
				[ { name: 'Animalism', power_name: 'Feral Whispers' } ]
			)
		).toBeNull();
	} );
} );

describe( "a trait's catalog entry", () => {
	it( 'reads a list item and a power family', () => {
		expect( catalogEntry( abilities, { name: 'Occult' } ) ).toMatchObject( {
			citation: 'Laws of the Night Revised, p. 97',
			description: { description: '<p>Hidden lore.</p>' },
			prerequisites: [ { power: 'Auspex' } ],
		} );
		expect(
			catalogEntry( disciplines, { name: 'Animalism' } )
		).toMatchObject( { description: { description: 'Beasts.' } } );
	} );

	it( 'cites only what the chronicle entered, never the catalog list it came from', () => {
		expect(
			catalogEntry( disciplines, { name: 'Animalism' } )?.citation
		).toBe( '' );
	} );

	it( 'says the price of a raise without its approval', () => {
		expect( priceText( { xp_cost: 3, priced: true } ) ).toBe( '3 XP' );
		expect( priceText( { xp_cost: 0 } ) ).toBe( '0 XP' );
		expect( priceText( { xp_cost: 0, priced: false } ) ).toBe(
			'Storyteller prices it'
		);
	} );

	it( 'has nothing for a name the catalog lacks or a missing block', () => {
		expect( catalogEntry( abilities, { name: 'Homebrew' } ) ).toBeNull();
		expect( catalogEntry( undefined, { name: 'Occult' } ) ).toBeNull();
	} );

	it( 'shows a Storyteller its [ST] passages marked and anyone else without them', () => {
		const text = 'Public. [ST]Secret.[/ST] More.';
		expect(
			visibleDescription(
				text,
				true,
				stripStSections,
				highlightStMarkers
			)
		).toContain( '<mark' );
		const player = visibleDescription(
			text,
			false,
			stripStSections,
			highlightStMarkers
		);
		expect( player ).not.toContain( 'Secret' );
		expect( player ).toContain( 'Public.' );
	} );
} );

describe( "a character's history with a trait", () => {
	const change = (
		id: number,
		type: string,
		data: Record< string, unknown >,
		status = 'approved'
	) =>
		( {
			id,
			change_type: type,
			change_data: data,
			status,
			submitted_at: '2026-10-02 12:00:00',
		} ) as unknown as CharacterChange;

	it( 'lists the changes naming that trait in that section, newest first, with a cap', () => {
		const changes = [
			change( 9, 'modify_trait', {
				block_slug: 'vampire-abilities',
				trait: { name: 'Occult', count: 4 },
				previous: { name: 'Occult', count: 3 },
			} ),
			change( 8, 'modify_trait', {
				block_slug: 'vampire-abilities',
				trait: { name: 'Brawl', count: 2 },
			} ),
			change( 7, 'modify_trait', {
				block_slug: 'vampire-merits',
				trait: { name: 'Occult', count: 1 },
			} ),
			change(
				6,
				'add_trait',
				{
					block_slug: 'vampire-abilities',
					trait: { name: 'Occult', count: 3 },
				},
				'pending'
			),
		];
		const lines = changesNaming( changes, {
			blockSlug: 'vampire-abilities',
			name: 'Occult',
		} );
		expect( lines.map( ( line ) => line.id ) ).toEqual( [ 9, 6 ] );
		expect( lines[ 0 ].description ).toBe( 'Occult x3 → x4' );
		expect( lines[ 1 ].status ).toBe( 'pending' );
		expect(
			changesNaming(
				changes,
				{ blockSlug: 'vampire-abilities', name: 'Occult' },
				1
			)
		).toHaveLength( 1 );
	} );
} );
