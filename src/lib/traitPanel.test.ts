import { highlightStMarkers } from './highlightStMarkers';
import { stripStSections } from './stripStSections';
import {
	cartButtonLabel,
	cartStep,
	catalogEntry,
	changesNaming,
	nextChange,
	nextRows,
	priceText,
	visibleDescription,
} from './traitPanel';
import { computeChanges } from './computeChanges';
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

describe( 'the step Add to cart takes', () => {
	const held = {
		blockSlug: 'vampire-abilities',
		name: 'Occult',
		sectionType: 'trait_list' as const,
	};

	it( 'raises the draft by one, not the saved sheet', () => {
		const saved = [ { name: 'Occult', count: 3 } ];
		const draft = [ { name: 'Occult', count: 4 } ];

		const step = cartStep( held, abilities, saved, draft );

		expect( step?.rows ).toEqual( [ { name: 'Occult', count: 5 } ] );
		expect( step?.rating ).toBe( 5 );
		expect( step?.next.change_data ).toMatchObject( {
			trait: { name: 'Occult', count: 5 },
			previous: { name: 'Occult', count: 3 },
		} );
		expect( step?.current?.change_data ).toMatchObject( {
			trait: { name: 'Occult', count: 4 },
		} );
		expect( step?.held ).toBe( true );
	} );

	it( 'has no current line when the draft is the saved sheet', () => {
		const saved = [ { name: 'Occult', count: 3 } ];

		const step = cartStep( held, abilities, saved, saved );

		expect( step?.current ).toBeNull();
		expect( step?.rating ).toBe( 4 );
	} );

	it( 'adds a trait that is not held with its first dot', () => {
		const saved = [ { name: 'Brawl', count: 2 } ];

		const step = cartStep( held, abilities, saved, saved );

		expect( step?.held ).toBe( false );
		expect( step?.rating ).toBe( 1 );
		expect( step?.next.change_type ).toBe( 'add_trait' );
	} );

	it( 'leaves another line in the same block out of this trait', () => {
		const saved = [
			{ name: 'Occult', count: 3 },
			{ name: 'Brawl', count: 2 },
		];
		const draft = [
			{ name: 'Occult', count: 3 },
			{ name: 'Brawl', count: 3 },
		];

		const step = cartStep( held, abilities, saved, draft );

		expect( step?.current ).toBeNull();
		expect( step?.next.change_data ).toMatchObject( {
			trait: { name: 'Occult', count: 4 },
		} );
	} );

	it( 'is null for a trait that cannot be raised that way', () => {
		expect(
			cartStep(
				{ ...held, name: 'Iron Will', blockSlug: 'vampire-merits' },
				merits,
				[],
				[]
			)
		).toBeNull();
	} );

	it( 'reads the level of a ladder power', () => {
		const step = cartStep(
			{
				blockSlug: 'vampire-disciplines',
				name: 'Animalism',
				sectionType: 'tiered_power',
			},
			disciplines,
			[ { name: 'Animalism', level: 1 } ],
			[ { name: 'Animalism', level: 1 } ]
		);

		expect( step?.rating ).toBe( 2 );
	} );

	it( "is the line Edit's own diff makes of the same move", () => {
		const saved = [ { name: 'Occult', count: 3 } ];
		const draft = [ { name: 'Occult', count: 4 } ];

		const step = cartStep( held, abilities, saved, draft );
		const edit = computeChanges(
			{ [ held.blockSlug ]: saved },
			{ [ held.blockSlug ]: step?.rows },
			{ [ held.blockSlug ]: abilities }
		);

		expect( edit ).toEqual( [ step?.next ] );
	} );

	it( 'gives the same rows as nextRows on the draft', () => {
		const draft = [ { name: 'Occult', count: 4 } ];
		expect( cartStep( held, abilities, draft, draft )?.rows ).toEqual(
			nextRows( held, abilities, draft )
		);
	} );
} );

describe( 'the words on the Add to cart button', () => {
	it( 'names a raise with its price', () => {
		expect(
			cartButtonLabel( { held: true, rating: 4 }, 'Occult', false, 1 )
		).toBe( 'Raise to x4 · 1 XP' );
		expect(
			cartButtonLabel( { held: true, rating: 3 }, 'Auspex', true, 6 )
		).toBe( 'Raise to level 3 · 6 XP' );
	} );

	it( 'names an addition with its first dot or level', () => {
		expect(
			cartButtonLabel( { held: false, rating: 1 }, 'Occult', false, 1 )
		).toBe( 'Add Occult x1 · 1 XP' );
		expect(
			cartButtonLabel( { held: false, rating: 1 }, 'Auspex', true, 3 )
		).toBe( 'Add Auspex level 1 · 3 XP' );
	} );

	it( 'leaves the price off until there is one', () => {
		expect(
			cartButtonLabel( { held: true, rating: 4 }, 'Occult', false, null )
		).toBe( 'Raise to x4' );
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

	it( 'names the book a shipped source names, and the ladder of a power family', () => {
		const book = {
			slug: 'vampire-merits',
			section_type: 'trait_list',
			definition: {
				items: [
					{
						name: 'Iron Will',
						source: 'GVM: Merits; met-mechanics.csv: Laws of the Night Revised',
					},
					{ name: 'Brawl', source: 'GVM: Abilities' },
				],
			},
		} as unknown as SchemaBlock;

		expect( catalogEntry( book, { name: 'Iron Will' } )?.book ).toBe(
			'Laws of the Night Revised'
		);
		expect( catalogEntry( book, { name: 'Brawl' } )?.book ).toBeNull();
		expect(
			catalogEntry( disciplines, { name: 'Animalism' } )?.ladder?.map(
				( level ) => level.power_name
			)
		).toEqual( [ 'Feral Whispers', 'Beckoning' ] );
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
