import {
	chooseReplacing,
	orderedChoice,
	sameChoice,
	toggleAdding,
} from './variantChoice';
import type { CatalogVariant } from '../types';

const VARIANTS: CatalogVariant[] = [
	{ id: '2nd-ed', label: '2nd edition', mode: 'add' },
	{ id: 'owbn', label: 'OWBN packet', mode: 'replace' },
	{ id: 'dark-ages', label: 'Dark Ages', mode: 'add' },
];

describe( 'variantChoice', () => {
	it( 'orders the replacing variant first, then the adding ones as the book lists them', () => {
		expect(
			orderedChoice( VARIANTS, [ 'dark-ages', 'owbn', '2nd-ed' ] )
		).toEqual( [ 'owbn', '2nd-ed', 'dark-ages' ] );
	} );

	it( 'ticks and unticks an adding variant', () => {
		expect( toggleAdding( VARIANTS, [ 'dark-ages' ], '2nd-ed' ) ).toEqual( [
			'2nd-ed',
			'dark-ages',
		] );
		expect(
			toggleAdding( VARIANTS, [ '2nd-ed', 'dark-ages' ], '2nd-ed' )
		).toEqual( [ 'dark-ages' ] );
	} );

	it( 'sets the replacing variant, or clears it, keeping the adding ones', () => {
		expect( chooseReplacing( VARIANTS, [ 'dark-ages' ], 'owbn' ) ).toEqual(
			[ 'owbn', 'dark-ages' ]
		);
		expect(
			chooseReplacing( VARIANTS, [ 'owbn', 'dark-ages' ], '' )
		).toEqual( [ 'dark-ages' ] );
	} );

	it( 'compares two choices whatever their order', () => {
		expect( sameChoice( [ 'a', 'b' ], [ 'b', 'a' ] ) ).toBe( true );
		expect( sameChoice( [ 'a' ], [ 'a', 'b' ] ) ).toBe( false );
	} );
} );
