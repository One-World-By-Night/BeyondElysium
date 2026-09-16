import { DOT_STATES, splitDots } from './dotRuns';

describe( 'splitDots', () => {
	it( "separates a trait's rating dots from its name and note", () => {
		expect( splitDots( 'Celerity x3 ●●● (Fast)' ) ).toEqual( [
			{ text: 'Celerity x3 ', dots: false },
			{ text: '●●●', dots: true },
			{ text: ' (Fast)', dots: false },
		] );
	} );

	it( "keeps a pool's groups of five, and every dot state, in one run", () => {
		expect( splitDots( '●●●●● ●○○○○ ◉◉' ) ).toEqual( [
			{ text: '●●●●● ●○○○○ ◉◉', dots: true },
		] );
	} );

	it( 'marks each separating dot in a dot-separated list', () => {
		expect( splitDots( 'X●X' ) ).toEqual( [
			{ text: 'X', dots: false },
			{ text: '●', dots: true },
			{ text: 'X', dots: false },
		] );
	} );

	it( 'leaves text without dots whole, and empty text empty', () => {
		expect( splitDots( 'Occult (Fast)' ) ).toEqual( [
			{ text: 'Occult (Fast)', dots: false },
		] );
		expect( splitDots( '' ) ).toEqual( [] );
	} );
} );

describe( 'DOT_STATES', () => {
	it( "names what each of a pool's glyphs means, and a rating's dot is a filled one", () => {
		expect( DOT_STATES[ '●' ] ).toBe( 'filled' );
		expect( DOT_STATES[ '○' ] ).toBe( 'spent' );
		expect( DOT_STATES[ '◉' ] ).toBe( 'overflow' );
		expect( Object.keys( DOT_STATES ) ).toHaveLength( 3 );
	} );
} );
