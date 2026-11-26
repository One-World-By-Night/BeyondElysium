import { heldTraits, searchTraits, type HeldSection } from './heldTraits';

const sections: HeldSection[] = [
	{
		blockSlug: 'vampire-abilities',
		title: 'Abilities',
		sectionType: 'trait_list',
	},
	{
		blockSlug: 'vampire-disciplines',
		title: 'Disciplines',
		sectionType: 'tiered_power',
	},
	{
		blockSlug: 'vampire-resources',
		title: 'Resources',
		sectionType: 'resource_pool',
	},
	{ blockSlug: 'vampire-health', title: 'Health', sectionType: 'trait_list' },
];

const sheet = {
	'vampire-abilities': [
		{ name: 'Occult', count: 4 },
		{ name: 'Iron Will', count: 1 },
		{ name: 'Brawl' },
		{ name: '' },
	],
	'vampire-disciplines': [
		{ name: 'Animalism', level: 3 },
		{ name: 'Auspex', level: 1, power_name: 'Heightened Senses' },
	],
	'vampire-resources': { Blood: { permanent: 10, temporary: 10 } },
	'vampire-health': [ { name: 'Healthy', count: 2 } ],
};

describe( 'held traits', () => {
	it( 'lists traits and powers in section order and skips pools and the excluded health track', () => {
		const held = heldTraits( sections, sheet, [ 'vampire-health' ] );
		expect( held.map( ( t ) => t.shown ) ).toEqual( [
			'Occult x4',
			'Iron Will',
			'Brawl',
			'Animalism 3',
			'Heightened Senses (Auspex)',
		] );
		expect( held[ 0 ] ).toMatchObject( {
			blockSlug: 'vampire-abilities',
			section: 'Abilities',
			sectionType: 'trait_list',
			name: 'Occult',
			rating: 4,
		} );
		expect( held[ 2 ].rating ).toBeNull();
		expect( held[ 4 ] ).toMatchObject( {
			sectionType: 'tiered_power',
			name: 'Auspex',
			powerName: 'Heightened Senses',
			rating: 1,
		} );
	} );

	it( 'shows a translated name where the sheet does', () => {
		const held = heldTraits( sections, sheet, [], ( _slug, name ) =>
			name === 'Occult' ? 'Ocultismo' : name
		);
		expect( held[ 0 ].shown ).toBe( 'Ocultismo x4' );
		expect( held[ 0 ].name ).toBe( 'Occult' );
	} );

	it( 'holds nothing for a section the sheet has no rows for', () => {
		expect( heldTraits( sections, {} ) ).toEqual( [] );
	} );
} );

describe( 'searching held traits', () => {
	const held = heldTraits( sections, sheet, [ 'vampire-health' ] );

	it( 'matches every word typed against the name, the pick and the section', () => {
		expect( searchTraits( held, 'occ' ).map( ( t ) => t.name ) ).toEqual( [
			'Occult',
		] );
		expect(
			searchTraits( held, 'senses auspex' ).map( ( t ) => t.name )
		).toEqual( [ 'Auspex' ] );
		expect( searchTraits( held, 'discipl' ) ).toHaveLength( 2 );
		expect( searchTraits( held, 'occ nothing' ) ).toEqual( [] );
	} );

	it( 'finds nothing for an empty search and caps the results', () => {
		expect( searchTraits( held, '   ' ) ).toEqual( [] );
		expect( searchTraits( held, 'a', 2 ) ).toHaveLength( 2 );
	} );
} );
