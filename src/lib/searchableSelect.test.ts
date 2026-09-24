import {
	buildOptionRows,
	canUseCustomEntry,
	filterOptions,
	flattenGroups,
	nextSelectableRow,
	resolveBlurCommit,
	type OptionGroup,
} from './searchableSelect';

describe( 'filterOptions', () => {
	const options = [ 'Celerity', 'Auspex', 'Potence', 'celerity variant' ];

	it( 'returns everything for an empty query', () => {
		expect( filterOptions( options, '' ) ).toEqual( options );
	} );

	it( 'returns everything for a whitespace-only query', () => {
		expect( filterOptions( options, '   ' ) ).toEqual( options );
	} );

	it( 'matches a substring case-insensitively', () => {
		expect( filterOptions( options, 'cel' ) ).toEqual( [
			'Celerity',
			'celerity variant',
		] );
	} );

	it( 'matches mid-string, not just prefix, since it is a substring filter', () => {
		expect( filterOptions( options, 'ari' ) ).toEqual( [
			'celerity variant',
		] );
	} );

	it( 'returns an empty array when nothing matches', () => {
		expect( filterOptions( options, 'xyz' ) ).toEqual( [] );
	} );
} );

describe( 'canUseCustomEntry', () => {
	const options = [ 'Celerity', 'Auspex' ];

	it( 'is false when the block does not allow custom entries', () => {
		expect( canUseCustomEntry( 'Something New', options, false ) ).toBe(
			false
		);
	} );

	it( 'is false for an empty query even when allowed', () => {
		expect( canUseCustomEntry( '   ', options, true ) ).toBe( false );
	} );

	it( 'is true for a genuinely new value when allowed', () => {
		expect( canUseCustomEntry( 'Something New', options, true ) ).toBe(
			true
		);
	} );

	it( 'is false when the query exactly matches an existing option, case-insensitively', () => {
		expect( canUseCustomEntry( 'celerity', options, true ) ).toBe( false );
	} );

	it( 'is true when the query only partially matches an existing option', () => {
		expect( canUseCustomEntry( 'Celer', options, true ) ).toBe( true );
	} );
} );

describe( 'resolveBlurCommit (Decision 076)', () => {
	const options = [ 'Celerity', 'Auspex' ];

	it( 'commits a genuinely new value as custom when the block allows it', () => {
		expect( resolveBlurCommit( 'Zzz New Thing', options, true ) ).toEqual( {
			value: 'Zzz New Thing',
			isCustom: true,
		} );
	} );

	it( 'commits nothing for a new value when the block does not allow custom entries', () => {
		expect(
			resolveBlurCommit( 'Zzz New Thing', options, false )
		).toBeNull();
	} );

	it( 'commits an exact case-insensitive match as a real selection, not custom - typing the full name by hand must work exactly like clicking it', () => {
		expect( resolveBlurCommit( 'celerity', options, true ) ).toEqual( {
			value: 'Celerity',
			isCustom: false,
		} );
	} );

	it( 'commits an exact match even when the block does not allow custom entries at all - this is not a custom entry', () => {
		expect( resolveBlurCommit( 'AUSPEX', options, false ) ).toEqual( {
			value: 'Auspex',
			isCustom: false,
		} );
	} );

	it( 'commits nothing for a blank query', () => {
		expect( resolveBlurCommit( '   ', options, true ) ).toBeNull();
	} );

	it( 'commits nothing for an unresolved partial match when custom entries are not allowed - must not clobber the existing value with a fragment', () => {
		expect( resolveBlurCommit( 'Celer', options, false ) ).toBeNull();
	} );

	it( 'trims the committed custom value', () => {
		expect(
			resolveBlurCommit( '  Zzz New Thing  ', options, true )
		).toEqual( {
			value: 'Zzz New Thing',
			isCustom: true,
		} );
	} );
} );

describe( 'buildOptionRows', () => {
	const gifts: OptionGroup[] = [
		{ label: 'Homid', options: [ 'Persuasion', 'Smell of Man' ] },
		{
			label: 'Get of Fenris',
			options: [ 'Razor Claws', 'Visage of Fenris' ],
		},
	];

	it( 'lays every group out in the order given, heading first', () => {
		expect( buildOptionRows( gifts, '' ) ).toEqual( [
			{ kind: 'heading', label: 'Homid' },
			{ kind: 'option', value: 'Persuasion' },
			{ kind: 'option', value: 'Smell of Man' },
			{ kind: 'heading', label: 'Get of Fenris' },
			{ kind: 'option', value: 'Razor Claws' },
			{ kind: 'option', value: 'Visage of Fenris' },
		] );
	} );

	it( 'searches across every section, not just the first', () => {
		// The out-of-tribe section is reachable by search exactly like the in-type one.
		expect( buildOptionRows( gifts, 'fenris' ) ).toEqual( [
			{ kind: 'heading', label: 'Get of Fenris' },
			{ kind: 'option', value: 'Visage of Fenris' },
		] );
	} );

	it( 'drops a section only when nothing in it survives the query', () => {
		const rows = buildOptionRows( gifts, 'persuasion' );
		expect( rows ).toEqual( [
			{ kind: 'heading', label: 'Homid' },
			{ kind: 'option', value: 'Persuasion' },
		] );
	} );

	it( 'never hides a section for being out of type - every group given is laid out', () => {
		// A Homid/Galliard/Fianna character may take a Get of Fenris gift.
		const rows = buildOptionRows( gifts, '' );
		expect( rows.filter( ( r ) => r.kind === 'heading' ) ).toHaveLength(
			2
		);
	} );

	it( 'renders an unlabeled group with no heading at all', () => {
		// How mage-rotes' 134 ungrouped entries reach the list without an invented name.
		expect(
			buildOptionRows( [ { label: '', options: [ 'Loose Rote' ] } ], '' )
		).toEqual( [ { kind: 'option', value: 'Loose Rote' } ] );
	} );

	it( 'returns nothing when no option in any section matches', () => {
		expect( buildOptionRows( gifts, 'zzz' ) ).toEqual( [] );
	} );
} );

describe( 'nextSelectableRow', () => {
	const rows = buildOptionRows(
		[
			{ label: 'Homid', options: [ 'Persuasion' ] },
			{ label: 'Get of Fenris', options: [ 'Razor Claws' ] },
		],
		''
	);
	// [heading, option, heading, option]
	const rowCount = rows.length;

	it( 'skips a leading heading so a freshly opened list can be committed with Enter', () => {
		expect( nextSelectableRow( rows, 0, 1, rowCount ) ).toBe( 1 );
	} );

	it( 'steps past the heading between two sections', () => {
		expect( nextSelectableRow( rows, 2, 1, rowCount ) ).toBe( 3 );
	} );

	it( 'steps backwards past a heading too', () => {
		expect( nextSelectableRow( rows, 2, -1, rowCount ) ).toBe( 1 );
	} );

	it( 'reports -1 at the end rather than wrapping onto a heading', () => {
		expect( nextSelectableRow( rows, 4, 1, rowCount ) ).toBe( -1 );
		expect( nextSelectableRow( rows, -1, -1, rowCount ) ).toBe( -1 );
	} );

	it( 'treats the trailing custom-entry index as selectable', () => {
		// rowCount is one past the rows when a custom row is appended.
		expect(
			nextSelectableRow( rows, rows.length, 1, rows.length + 1 )
		).toBe( rows.length );
	} );
} );

describe( 'flattenGroups', () => {
	it( 'returns every option across every section, in order', () => {
		expect(
			flattenGroups( [
				{ label: 'A', options: [ 'one', 'two' ] },
				{ label: 'B', options: [ 'three' ] },
			] )
		).toEqual( [ 'one', 'two', 'three' ] );
	} );

	it( 'is empty for no groups', () => {
		expect( flattenGroups( [] ) ).toEqual( [] );
	} );
} );
