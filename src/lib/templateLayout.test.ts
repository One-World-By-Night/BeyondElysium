import {
	spanFor,
	sortedForFlow,
	isSectionCollapsed,
	LAYOUT_GRID_UNITS,
} from './templateLayout';
import type { TemplateLayoutSection } from '../types';
import input from '../../tests/fixtures/layout-flow-input.json';
import expected from '../../tests/fixtures/layout-flow-expected.json';

function section(
	overrides: Partial< TemplateLayoutSection >
): TemplateLayoutSection {
	return {
		block_slug: 'x',
		column: 1,
		order: 1,
		title: 'X',
		display: null,
		collapsed: false,
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

		expect(
			sortedForFlow( sections ).map( ( s ) => s.block_slug )
		).toEqual( [ 'a', 'c', 'b' ] );
	} );

	it( 'does not mutate the input array', () => {
		const sections = [
			section( { block_slug: 'b', order: 2 } ),
			section( { block_slug: 'a', order: 1 } ),
		];
		const original = [ ...sections ];

		sortedForFlow( sections );

		expect( sections ).toEqual( original );
	} );
} );

describe( 'isSectionCollapsed', () => {
	it( 'starts open when the template never set collapsed', () => {
		expect(
			isSectionCollapsed( section( { collapsed: false } ), {} )
		).toBe( false );
	} );

	it( 'starts closed when the template marks it collapsed', () => {
		expect( isSectionCollapsed( section( { collapsed: true } ), {} ) ).toBe(
			true
		);
	} );

	it( "the viewer's own click overrides the template default either way", () => {
		const closedByTemplate = section( {
			block_slug: 'a',
			collapsed: true,
		} );
		const openByTemplate = section( { block_slug: 'b', collapsed: false } );

		expect( isSectionCollapsed( closedByTemplate, { a: false } ) ).toBe(
			false
		);
		expect( isSectionCollapsed( openByTemplate, { b: true } ) ).toBe(
			true
		);
	} );

	it( "only this section's own override applies, never another's", () => {
		const sectionA = section( { block_slug: 'a', collapsed: true } );
		expect( isSectionCollapsed( sectionA, { b: false } ) ).toBe( true );
	} );
} );

/**
 * Same fixture, same expected output as `tests/unit/Display/Layout_FlowTest.php`.
 */
describe( 'templateLayout — parity with Layout_Flow.php', () => {
	it( 'spanFor matches the shared fixture for every case', () => {
		expect( input.spanFor.length ).toBe( expected.spanFor.length );

		input.spanFor.forEach( ( testCase, i ) => {
			const width =
				testCase.width as unknown as TemplateLayoutSection[ 'width' ];
			expect( spanFor( width ) ).toBe( expected.spanFor[ i ].span );
		} );
	} );

	it( 'sortedForFlow matches the shared fixture for every case, including stability', () => {
		expect( input.sortedForFlow.length ).toBe(
			expected.sortedForFlow.length
		);

		input.sortedForFlow.forEach( ( testCase, i ) => {
			const flowed = sortedForFlow(
				testCase.sections as TemplateLayoutSection[]
			);
			expect( flowed.map( ( s ) => s.block_slug ) ).toEqual(
				expected.sortedForFlow[ i ].block_slugs
			);
		} );
	} );
} );
