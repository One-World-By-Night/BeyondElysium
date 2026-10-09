import { describe, expect, it } from 'vitest';
import { sheetStyleVars } from './sheetStyleVars';
import {
	contrastRatio,
	parseHex,
	readableTextFor,
	readsWell,
} from './colorContrast';

describe( 'sheetStyleVars', () => {
	it( 'sets nothing for a sheet with no style', () => {
		expect( sheetStyleVars( {} ) ).toEqual( {} );
	} );

	it( 'carries the font and accent through untouched', () => {
		expect(
			sheetStyleVars( {
				font_family: 'Georgia, serif',
				accent_color: '#ff0000',
			} )
		).toEqual( {
			'--be-sheet-font': 'Georgia, serif',
			'--be-sheet-accent': '#ff0000',
		} );
	} );

	it( 'puts every self-painting surface on a chosen background', () => {
		const vars = sheetStyleVars( {
			background_color: '#1d2033',
			text_color: '#f3f5f7',
		} ) as Record< string, string >;

		expect( vars[ '--be-sheet-bg' ] ).toBe( '#1d2033' );
		expect( vars[ '--be-sheet-surface' ] ).toBe( '#1d2033' );
		expect( vars[ '--be-sheet-header-bg' ] ).toBe( 'transparent' );
		expect( vars[ '--be-sheet-notice-bg' ] ).toBe( 'transparent' );
		expect( vars[ '--be-sheet-text' ] ).toBe( '#f3f5f7' );
		expect( vars[ '--be-sheet-label' ] ).toBe( '#f3f5f7' );
		expect( vars[ '--be-sheet-dot-filled' ] ).toBe( '#f3f5f7' );
	} );

	it( 'supplies light text on a dark background that has no text color', () => {
		const vars = sheetStyleVars( {
			background_color: '#101010',
		} ) as Record< string, string >;
		expect( vars[ '--be-sheet-text' ] ).toBe( '#ffffff' );
	} );

	it( 'supplies dark text on a light background that has no text color', () => {
		const vars = sheetStyleVars( {
			background_color: '#f5f5dc',
		} ) as Record< string, string >;
		expect( vars[ '--be-sheet-text' ] ).toBe( '#000000' );
	} );

	it( 'keeps a chosen text color over a derived one', () => {
		const vars = sheetStyleVars( {
			background_color: '#101010',
			text_color: '#ffcc00',
		} ) as Record< string, string >;
		expect( vars[ '--be-sheet-text' ] ).toBe( '#ffcc00' );
	} );

	it( 'does not paint a surface when only the text color is chosen', () => {
		const vars = sheetStyleVars( { text_color: '#222222' } ) as Record<
			string,
			string
		>;
		expect( vars[ '--be-sheet-surface' ] ).toBeUndefined();
		expect( vars[ '--be-sheet-text' ] ).toBe( '#222222' );
	} );

	it( 'quotes a background image url', () => {
		const vars = sheetStyleVars( {
			background_image_url: 'https://example.test/a b.png',
		} ) as Record< string, string >;
		expect( vars[ '--be-sheet-bg-image' ] ).toBe(
			'url("https://example.test/a b.png")'
		);
	} );
} );

describe( 'colorContrast', () => {
	it( 'parses three and six digit hex and refuses the rest', () => {
		expect( parseHex( '#fff' ) ).toEqual( { r: 255, g: 255, b: 255 } );
		expect( parseHex( '#1D2033' ) ).toEqual( { r: 29, g: 32, b: 51 } );
		expect( parseHex( 'red' ) ).toBeNull();
		expect( parseHex( null ) ).toBeNull();
	} );

	it( 'measures black on white as 21 to 1', () => {
		expect( contrastRatio( '#000000', '#ffffff' ) ).toBeCloseTo( 21, 1 );
	} );

	it( 'measures identical colors as 1 to 1', () => {
		expect( contrastRatio( '#336699', '#336699' ) ).toBeCloseTo( 1, 5 );
	} );

	it( 'picks black or white by background', () => {
		expect( readableTextFor( '#000000' ) ).toBe( '#ffffff' );
		expect( readableTextFor( '#ffffff' ) ).toBe( '#000000' );
		expect( readableTextFor( 'not-a-color' ) ).toBe( '#000000' );
	} );

	it( 'says whether a text and background pair reads well', () => {
		expect( readsWell( '#f3f5f7', '#1d2033' ) ).toBe( true );
		expect( readsWell( '#1d2033', '#222222' ) ).toBe( false );
		expect( readsWell( 'nope', '#222222' ) ).toBe( true );
	} );
} );
