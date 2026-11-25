import {
	chosenNote,
	composedName,
	defaultPickCost,
	entryBadges,
	entryShows,
	familyChanges,
	familyDraft,
	flagParts,
	groupItems,
	inClanBoxes,
	itemChanges,
	itemDraft,
	priceLine,
} from './catalogEntries';
import type {
	CatalogFamilyEntry,
	CatalogItemEntry,
	CatalogSection,
} from '../types/catalog';

const held = { characters: 0, changes: 0 };

function item( over: Partial< CatalogItemEntry > = {} ): CatalogItemEntry {
	return {
		name: 'Ability Aptitude',
		name_pt: null,
		cost: '1',
		group: '',
		tier: '',
		base_name: 'Ability Aptitude',
		description: null,
		origin: 'book',
		flagged: false,
		corrections: [],
		etag: 'abc',
		in_use: held,
		in_use_note: null,
		...over,
	};
}

function family(
	over: Partial< CatalogFamilyEntry > = {}
): CatalogFamilyEntry {
	return {
		name: 'Hollow Vein',
		name_pt: null,
		description: null,
		levels: [
			{
				level: 1,
				tier: 'basic',
				cost: '3',
				power_name: '',
				description: null,
			},
			{
				level: 2,
				tier: 'basic',
				cost: '3',
				power_name: '',
				description: null,
			},
		],
		picks: [],
		category_values: {},
		traditions: {},
		in_clan: {},
		origin: 'added',
		flagged: false,
		corrections: [],
		etag: 'def',
		in_use: held,
		in_use_note: null,
		...over,
	};
}

const ladderSection: CatalogSection = {
	block_slug: 'vampire-disciplines',
	label: 'Disciplines',
	kind: 'family',
	priced_by: 'rank',
	blood_magic: false,
	traditions: [],
	in_type: {
		maps: [
			{
				map: 'vampire-identity.clan_disciplines',
				by: [ 'Clan' ],
				keys: [ 'Ventrue', 'Tremere' ],
			},
		],
		facets: [],
		chosen: [
			{ field: 'vampire-identity.Clan', is: [ 'Caitiff', 'Pander' ] },
		],
	},
	ranks: [ 'basic', 'intermediate', 'advanced', 'elder' ],
	costs: { basic: 3, intermediate: 6, advanced: 9, elder: 12 },
	out_of_type: {
		basic: '+1',
		intermediate: '+1',
		advanced: '+1',
		elder: '+1',
	},
	ladder: [
		{ level: 1, tier: 'basic', cost: 3 },
		{ level: 2, tier: 'basic', cost: 3 },
		{ level: 3, tier: 'intermediate', cost: 6 },
	],
	pick_ranks: [ 'elder' ],
};

const listSection: CatalogSection = {
	block_slug: 'vampire-rituals',
	label: 'Rituals',
	kind: 'item',
	priced_by: 'pick',
	blood_magic: false,
	traditions: [],
	in_type: { maps: [], facets: [], chosen: [] },
	name_form: true,
	name_ranks: [ 'basic', 'intermediate' ],
};

describe( 'how a section prices', () => {
	it( 'says per dot and per pick for a list', () => {
		expect( priceLine( { ...listSection, priced_by: 'dot' } ) ).toBe(
			'Priced per dot.'
		);
		expect( priceLine( listSection ) ).toBe( 'Priced per pick.' );
	} );

	it( 'lists the ranks with their prices and the out-of-clan step', () => {
		expect( priceLine( ladderSection ) ).toBe(
			'Priced by rank: basic 3, intermediate 6, advanced 9, elder 12. Out-of-clan +1.'
		);
	} );

	it( 'leaves the step out when ranks differ in it or none has one', () => {
		expect(
			priceLine( {
				...ladderSection,
				out_of_type: { basic: '+1', elder: '+2' },
			} )
		).toBe(
			'Priced by rank: basic 3, intermediate 6, advanced 9, elder 12.'
		);
		expect(
			priceLine( { ...ladderSection, out_of_type: {} } )
		).not.toContain( 'Out-of-clan' );
	} );
} );

describe( "an item's name", () => {
	it( "follows the list's name form", () => {
		expect(
			composedName( listSection, {
				name: 'Ward of Ash',
				group: 'Thaumaturgy',
				tier: 'basic',
			} )
		).toBe( 'Thaumaturgy: Ward of Ash (basic)' );
		expect(
			composedName( listSection, {
				name: 'Ward of Ash',
				group: '',
				tier: 'basic',
			} )
		).toBe( 'Ward of Ash (basic)' );
	} );

	it( 'is the name as typed on a list with no form', () => {
		expect(
			composedName(
				{ name_form: false },
				{ name: ' Hollow Blood ', group: 'X', tier: 'basic' }
			)
		).toBe( 'Hollow Blood' );
	} );
} );

describe( 'badges, filters and groups', () => {
	it( 'badges where an entry came from and whether the book changed under it', () => {
		expect( entryBadges( { origin: 'book', flagged: false } ) ).toEqual(
			[]
		);
		expect( entryBadges( { origin: 'added', flagged: false } ) ).toEqual( [
			'Added here',
		] );
		expect( entryBadges( { origin: 'changed', flagged: true } ) ).toEqual( [
			'Changed here',
			'The book changed this',
		] );
	} );

	it( 'filters by origin and flag and searches by name in any case', () => {
		const added = item( { name: 'Hollow Blood', origin: 'added' } );
		const flagged = item( {
			name: 'Ability Aptitude',
			origin: 'changed',
			flagged: true,
		} );
		expect( entryShows( added, 'added', '' ) ).toBe( true );
		expect( entryShows( flagged, 'added', '' ) ).toBe( false );
		expect( entryShows( flagged, 'changed', '' ) ).toBe( true );
		expect( entryShows( flagged, 'flagged', '' ) ).toBe( true );
		expect( entryShows( added, 'all', 'HOLLOW' ) ).toBe( true );
		expect( entryShows( added, 'all', 'aptitude' ) ).toBe( false );
	} );

	it( 'groups items in catalog order and folds the groups of a long list until a search is typed', () => {
		const many = Array.from( { length: 51 }, ( _, i ) =>
			item( { name: `Item ${ i }`, group: i % 2 ? 'Odd' : 'Even' } )
		);
		const groups = groupItems( many, 'all', '' );
		expect( groups.map( ( g ) => g.name ) ).toEqual( [ 'Even', 'Odd' ] );
		expect( groups.every( ( g ) => g.folded ) ).toBe( true );
		expect(
			groupItems( many, 'all', 'item 1' ).every( ( g ) => ! g.folded )
		).toBe( true );
		expect(
			groupItems( many.slice( 0, 50 ), 'all', '' ).some(
				( g ) => g.folded
			)
		).toBe( false );
	} );
} );

describe( 'saving an item', () => {
	it( 'sends everything a new item has', () => {
		expect(
			itemChanges( null, {
				name: 'Ward of Ash',
				group: 'Thaumaturgy',
				tier: 'basic',
				cost: '2',
				description: {},
			} )
		).toEqual( {
			name: 'Ward of Ash',
			group: 'Thaumaturgy',
			tier: 'basic',
			cost: '2',
		} );
	} );

	it( 'sends only the fields that changed on an item the chronicle added', () => {
		const entry = item( {
			origin: 'added',
			name: 'Hollow Blood',
			base_name: 'Hollow Blood',
			cost: '4',
		} );
		const draft = { ...itemDraft( entry ), cost: '5' };
		expect( itemChanges( entry, draft ) ).toEqual( { cost: '5' } );
		expect( itemChanges( entry, itemDraft( entry ) ) ).toEqual( {} );
	} );

	it( 'sends only the price and description of a book item, even when other fields were touched', () => {
		const entry = item();
		const draft = {
			...itemDraft( entry ),
			name: 'Renamed',
			group: 'Somewhere',
			cost: '3',
			description: { description: '<p>x</p>' },
		};
		expect( itemChanges( entry, draft ) ).toEqual( {
			cost: '3',
			description: { description: '<p>x</p>' },
		} );
	} );
} );

describe( 'saving a family', () => {
	it( 'starts a new family from every rung of the ladder, unnamed', () => {
		const draft = familyDraft( null, ladderSection );
		expect(
			draft.levels.map( ( l ) => [
				l.level,
				l.tier,
				l.cost,
				l.power_name,
			] )
		).toEqual( [
			[ 1, 'basic', '3', '' ],
			[ 2, 'basic', '3', '' ],
			[ 3, 'intermediate', '6', '' ],
		] );
	} );

	it( 'sends only the levels that were named', () => {
		const entry = family();
		const draft = familyDraft( entry, ladderSection );
		draft.levels[ 0 ].power_name = 'First Cut';
		expect( familyChanges( entry, draft, ladderSection ) ).toEqual( {
			levels: [ { level: 1, power_name: 'First Cut' } ],
		} );
	} );

	it( "sends the whole list of picks when one is added, with a default price of the rank's cost", () => {
		const entry = family();
		const draft = familyDraft( entry, ladderSection );
		draft.picks.push( {
			rank: 'elder',
			power_name: 'Hollow Throne',
			cost: defaultPickCost( ladderSection, 'elder' ),
			description: {},
			origin: 'added',
			held: false,
		} );
		expect( familyChanges( entry, draft, ladderSection ) ).toEqual( {
			picks: [
				{ rank: 'elder', power_name: 'Hollow Throne', cost: '12' },
			],
		} );
	} );

	it( 'sends a book family only its descriptions and picks', () => {
		const entry = family( { origin: 'book' } );
		const draft = familyDraft( entry, ladderSection );
		draft.name = 'Renamed';
		draft.levels[ 0 ].power_name = 'Renamed Level';
		draft.description = { description: '<p>Beasts.</p>' };
		expect( familyChanges( entry, draft, ladderSection ) ).toEqual( {
			description: { description: '<p>Beasts.</p>' },
		} );
	} );

	it( 'sends the ticked traditions with the name each gives the path', () => {
		const entry = family();
		const draft = familyDraft( entry, ladderSection );
		draft.traditions = { Akhu: 'Rasayana', Bacaban: '' };
		expect( familyChanges( entry, draft, ladderSection ) ).toEqual( {
			traditions: { Akhu: 'Rasayana', Bacaban: null },
		} );
	} );
} );

describe( 'in-clan', () => {
	it( 'offers the keys of each map a section reads, ticked for the family that holds them', () => {
		const entry = family( {
			in_clan: { 'vampire-identity.clan_disciplines': [ 'Tremere' ] },
		} );
		expect( inClanBoxes( ladderSection, entry )[ 0 ] ).toMatchObject( {
			keys: [ 'Ventrue', 'Tremere' ],
			ticked: [ 'Tremere' ],
		} );
		expect( inClanBoxes( ladderSection, null )[ 0 ].ticked ).toEqual( [] );
	} );

	it( 'says which clans choose their own, from the test itself', () => {
		expect( chosenNote( ladderSection ) ).toBe(
			'Caitiff and Pander choose their own.'
		);
		expect( chosenNote( listSection ) ).toBeNull();
	} );
} );

describe( 'a flagged change', () => {
	const base = {
		path: [ 'items', [ 'Ability Aptitude' ], 'cost' ],
		removed: false,
		was: '1',
		now: '2',
		yours: '7',
		was_set: true,
		now_set: true,
		yours_set: true,
	};

	it( 'reads a price as yours, the book now and the book then', () => {
		expect( flagParts( base ) ).toEqual( {
			field: 'Price',
			yours: '7',
			now: '2',
			was: '1',
			removed: false,
		} );
	} );

	it( 'says nothing for a value that is not there and a description in words', () => {
		const parts = flagParts( {
			...base,
			path: [ 'items', [ 'Ability Aptitude' ], 'description' ],
			now: { description: '<p>x</p>' },
			yours_set: false,
		} );
		expect( parts.field ).toBe( 'Description' );
		expect( parts.now ).toBe( 'a different description' );
		expect( parts.yours ).toBe( 'nothing' );
	} );
} );
