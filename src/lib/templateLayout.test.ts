import { spanFor, sortedForFlow, LAYOUT_GRID_UNITS } from './templateLayout';
import type { TemplateLayoutSection } from '../types';

function section( overrides: Partial<TemplateLayoutSection> ): TemplateLayoutSection {
	return {
		block_slug: 'x', column: 1, order: 1, title: 'X', display: null, collapsed: false,
		...overrides,
	};
}

describe( 'spanFor', () => {
	it( 'defaults to third (2 of 6) when width is unset - every template authored before this field existed', () => {
		expect( spanFor( undefined ) ).toBe( 2 );
	} );

	it( 'three thirds fill exactly one row', () => {
		expect( spanFor( 'third' ) * 3 ).toBe( LAYOUT_GRID_UNITS );
	} );

	it( 'two halves fill exactly one row', () => {
		expect( spanFor( 'half' ) * 2 ).toBe( LAYOUT_GRID_UNITS );
	} );

	it( 'full takes the whole row', () => {
		expect( spanFor( 'full' ) ).toBe( LAYOUT_GRID_UNITS );
	} );
} );

describe( 'sortedForFlow', () => {
	it( 'sorts by column then order, matching real row-reading order', () => {
		const sections = [
			section( { block_slug: 'c', column: 1, order: 2 } ),
			section( { block_slug: 'a', column: 1, order: 1 } ),
			section( { block_slug: 'b', column: 2, order: 1 } ),
		];

		expect( sortedForFlow( sections ).map( ( s ) => s.block_slug ) ).toEqual( [ 'a', 'c', 'b' ] );
	} );

	it( 'does not mutate the input array', () => {
		const sections = [ section( { block_slug: 'b', order: 2 } ), section( { block_slug: 'a', order: 1 } ) ];
		const original = [ ...sections ];

		sortedForFlow( sections );

		expect( sections ).toEqual( original );
	} );
} );
