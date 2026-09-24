import { groupCatalogItems, type GroupableItem } from './catalogGroups';
import { identityGroupValues } from './identityGroups';

const gifts: GroupableItem[] = [
	{ name: 'Persuasion', group: 'Homid' },
	{ name: 'Razor Claws', group: 'Get of Fenris' },
	{ name: 'Visage of Fenris', group: 'Get of Fenris' },
	{ name: "Mother's Touch", group: 'Theurge' },
];

describe( 'groupCatalogItems', () => {
	it( 'sections a catalog by its own group field, in catalog order', () => {
		expect( groupCatalogItems( gifts ) ).toEqual( [
			{ label: 'Homid', options: [ 'Persuasion' ] },
			{
				label: 'Get of Fenris',
				options: [ 'Razor Claws', 'Visage of Fenris' ],
			},
			{ label: 'Theurge', options: [ "Mother's Touch" ] },
		] );
	} );

	it( 'returns nothing at all when no item carries a group', () => {
		// Merits, Flaws, Rituals and Combos.
		expect(
			groupCatalogItems( [
				{ name: 'Iron Will' },
				{ name: 'Acute Sense' },
			] )
		).toEqual( [] );
	} );

	it( 'sorts a preferred group to the front without removing any other', () => {
		const sections = groupCatalogItems( gifts, [ 'Theurge' ] );
		expect( sections[ 0 ].label ).toBe( 'Theurge' );
		expect( sections.map( ( s ) => s.label ) ).toHaveLength( 3 );
		expect( sections.map( ( s ) => s.label ) ).toContain( 'Get of Fenris' );
	} );

	it( 'honours the order of several preferred groups', () => {
		const sections = groupCatalogItems( gifts, [
			'Theurge',
			'Get of Fenris',
		] );
		expect( sections.map( ( s ) => s.label ) ).toEqual( [
			'Theurge',
			'Get of Fenris',
			'Homid',
		] );
	} );

	it( 'matches a preferred group case-insensitively', () => {
		expect( groupCatalogItems( gifts, [ 'theurge' ] )[ 0 ].label ).toBe(
			'Theurge'
		);
	} );

	it( 'ignores a preferred value that matches no section', () => {
		// Four real werewolf-identity Tribe values do not equal their gift group (`Bone Gnawers` against `Bone Gnawer`).
		expect(
			groupCatalogItems( gifts, [ 'Bone Gnawers' ] ).map(
				( s ) => s.label
			)
		).toEqual( [ 'Homid', 'Get of Fenris', 'Theurge' ] );
	} );

	it( 'nests a subgroup under its group and keeps the un-subgrouped remainder first', () => {
		// The real fera-gifts shape: Ananasi has 35 general Gifts plus seven factions.
		const fera: GroupableItem[] = [
			{ name: 'General One', group: 'Ananasi' },
			{ name: 'Viskr One', group: 'Ananasi', subgroup: 'Viskr' },
			{ name: 'Hatar One', group: 'Ananasi', subgroup: 'Hatar' },
		];
		expect( groupCatalogItems( fera ) ).toEqual( [
			{ label: 'Ananasi', options: [ 'General One' ] },
			{ label: 'Ananasi \u{203A} Viskr', options: [ 'Viskr One' ] },
			{ label: 'Ananasi \u{203A} Hatar', options: [ 'Hatar One' ] },
		] );
	} );

	it( 'puts ungrouped items in a named section, last', () => {
		const sections = groupCatalogItems( [
			...gifts,
			{ name: 'Loose Rote' },
		] );
		const last = sections[ sections.length - 1 ];
		expect( last.options ).toEqual( [ 'Loose Rote' ] );
		expect( last.label ).not.toBe( '' );
	} );

	it( 'treats a blank group as ungrouped rather than as a section named ""', () => {
		const sections = groupCatalogItems( [
			{ name: 'Persuasion', group: 'Homid' },
			{ name: 'Blank', group: '   ' },
		] );
		expect( sections.map( ( s ) => s.label ) ).not.toContain( '' );
		expect( sections ).toHaveLength( 2 );
	} );
} );

describe( 'identityGroupValues', () => {
	it( "reads every identity block's string values, whatever the creature type", () => {
		// No creature-specific branch.
		expect(
			identityGroupValues( {
				'werewolf-identity': {
					Rank: 3,
					Breed: 'Homid',
					Tribe: 'Wendigo',
					Auspice: 'Ahroun',
				},
			} )
		).toEqual( [ 'Homid', 'Wendigo', 'Ahroun' ] );

		expect(
			identityGroupValues( {
				'changeling-identity': { Kith: 'Pooka', Court: 'Seelie' },
			} )
		).toEqual( [ 'Pooka', 'Seelie' ] );
	} );

	it( 'skips numeric identity fields', () => {
		// Rank 3 and Generation 9 are identity fields; neither is a section name.
		expect(
			identityGroupValues( {
				'vampire-identity': { Generation: 9, Clan: 'Brujah' },
			} )
		).toEqual( [ 'Brujah' ] );
	} );

	it( 'reads each value of a multiselect identity field', () => {
		expect(
			identityGroupValues( {
				'demon-identity': { House: [ 'Devourer', 'Fiend' ] },
			} )
		).toEqual( [ 'Devourer', 'Fiend' ] );
	} );

	it( 'ignores blocks that are not identity blocks', () => {
		expect(
			identityGroupValues( {
				'werewolf-gifts': [ { name: 'Persuasion', count: 1 } ],
				'werewolf-identity': { Tribe: 'Wendigo' },
			} )
		).toEqual( [ 'Wendigo' ] );
	} );

	it( 'is empty for a sheet with nothing on it', () => {
		expect( identityGroupValues( undefined ) ).toEqual( [] );
		expect( identityGroupValues( {} ) ).toEqual( [] );
	} );

	it( 'survives a null or non-object identity block', () => {
		expect(
			identityGroupValues( {
				'mummy-identity': null,
				'mage-identity': 'not an object',
				'vampire-identity': { Clan: 'Brujah' },
			} as unknown as Record< string, unknown > )
		).toEqual( [ 'Brujah' ] );
	} );
} );
