import { canUseCustomEntry, filterOptions, resolveBlurCommit } from './searchableSelect';

describe( 'filterOptions', () => {
	const options = [ 'Celerity', 'Auspex', 'Potence', 'celerity variant' ];

	it( 'returns everything for an empty query', () => {
		expect( filterOptions( options, '' ) ).toEqual( options );
	} );

	it( 'returns everything for a whitespace-only query', () => {
		expect( filterOptions( options, '   ' ) ).toEqual( options );
	} );

	it( 'matches a substring case-insensitively', () => {
		expect( filterOptions( options, 'cel' ) ).toEqual( [ 'Celerity', 'celerity variant' ] );
	} );

	it( 'matches mid-string, not just prefix, since it is a substring filter', () => {
		expect( filterOptions( options, 'ari' ) ).toEqual( [ 'celerity variant' ] );
	} );

	it( 'returns an empty array when nothing matches', () => {
		expect( filterOptions( options, 'xyz' ) ).toEqual( [] );
	} );
} );

describe( 'canUseCustomEntry', () => {
	const options = [ 'Celerity', 'Auspex' ];

	it( 'is false when the block does not allow custom entries', () => {
		expect( canUseCustomEntry( 'Something New', options, false ) ).toBe( false );
	} );

	it( 'is false for an empty query even when allowed', () => {
		expect( canUseCustomEntry( '   ', options, true ) ).toBe( false );
	} );

	it( 'is true for a genuinely new value when allowed', () => {
		expect( canUseCustomEntry( 'Something New', options, true ) ).toBe( true );
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
		expect( resolveBlurCommit( 'Zzz New Thing', options, false ) ).toBeNull();
	} );

	it( 'commits an exact case-insensitive match as a real selection, not custom - typing the full name by hand must work exactly like clicking it', () => {
		expect( resolveBlurCommit( 'celerity', options, true ) ).toEqual( { value: 'Celerity', isCustom: false } );
	} );

	it( 'commits an exact match even when the block does not allow custom entries at all - this is not a custom entry', () => {
		expect( resolveBlurCommit( 'AUSPEX', options, false ) ).toEqual( { value: 'Auspex', isCustom: false } );
	} );

	it( 'commits nothing for a blank query', () => {
		expect( resolveBlurCommit( '   ', options, true ) ).toBeNull();
	} );

	it( 'commits nothing for an unresolved partial match when custom entries are not allowed - must not clobber the existing value with a fragment', () => {
		expect( resolveBlurCommit( 'Celer', options, false ) ).toBeNull();
	} );

	it( 'trims the committed custom value', () => {
		expect( resolveBlurCommit( '  Zzz New Thing  ', options, true ) ).toEqual( {
			value: 'Zzz New Thing',
			isCustom: true,
		} );
	} );
} );
