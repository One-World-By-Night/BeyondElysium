import { displayTrait, type Trait, type DisplayType } from './displayTrait';
import input from '../../tests/fixtures/trait-display-input.json';
import expected from '../../tests/fixtures/trait-display-expected.json';

const CELERITY: Trait = { name: 'Celerity', total: 3, note: 'Fast' };

describe( 'displayTrait — all 11 modes against the fixture trait', () => {
	it( 'simple: name only, note suppressed', () => {
		expect( displayTrait( CELERITY, 'simple' ) ).toBe( 'Celerity' );
	} );

	it( 'multiplier: name, x{total}, then note', () => {
		expect( displayTrait( CELERITY, 'multiplier' ) ).toBe(
			'Celerity x3 (Fast)'
		);
	} );

	it( 'multiplier_dot: name, x{total}, dots, note', () => {
		expect( displayTrait( CELERITY, 'multiplier_dot' ) ).toBe(
			'Celerity x3 ●●● (Fast)'
		);
	} );

	it( 'dot: name, dots, count, note', () => {
		expect( displayTrait( CELERITY, 'dot' ) ).toBe(
			'Celerity ●●● 3 (Fast)'
		);
	} );

	it( 'cost: name ({total}, {note})', () => {
		expect( displayTrait( CELERITY, 'cost' ) ).toBe( 'Celerity (3, Fast)' );
	} );

	it( 'note_only: name ({note})', () => {
		expect( displayTrait( CELERITY, 'note_only' ) ).toBe(
			'Celerity (Fast)'
		);
	} );

	it( 'cost_only: name ({total}), note excluded', () => {
		expect( displayTrait( CELERITY, 'cost_only' ) ).toBe( 'Celerity (3)' );
	} );

	it( 'dot_separate: name+note repeated total times, dot-joined, then the real count', () => {
		expect( displayTrait( CELERITY, 'dot_separate' ) ).toBe(
			'Celerity (Fast)●Celerity (Fast)●Celerity (Fast) 3'
		);
	} );

	it( 'simple_dots: dots and count, name dropped', () => {
		expect( displayTrait( CELERITY, 'simple_dots' ) ).toBe( '●●● 3' );
	} );

	it( 'simple_number: total only', () => {
		expect( displayTrait( CELERITY, 'simple_number' ) ).toBe( '3' );
	} );

	it( 'simple_note: note only', () => {
		expect( displayTrait( CELERITY, 'simple_note' ) ).toBe( 'Fast' );
	} );
} );

describe( 'displayTrait — edge cases', () => {
	it( 'multiplier hides x1', () => {
		expect(
			displayTrait( { name: 'Fortitude', total: 1 }, 'multiplier' )
		).toBe( 'Fortitude' );
	} );

	it( 'multiplier_dot does NOT hide x1', () => {
		expect(
			displayTrait( { name: 'Fortitude', total: 1 }, 'multiplier_dot' )
		).toBe( 'Fortitude x1 ●' );
	} );

	it( 'total 0 renders no dots but the mode still runs, and the count (0) still shows', () => {
		expect( displayTrait( { name: 'Flaw', total: 0 }, 'dot' ) ).toBe(
			'Flaw 0'
		);
		expect(
			displayTrait( { name: 'Flaw', total: 0 }, 'simple_dots' )
		).toBe( '0' );
		expect(
			displayTrait( { name: 'Flaw', total: 0 }, 'simple_number' )
		).toBe( '0' );
	} );

	it( 'dot_separate emits the name once for total 0 or 1, then the real total', () => {
		expect( displayTrait( { name: 'X', total: 0 }, 'dot_separate' ) ).toBe(
			'X 0'
		);
		expect( displayTrait( { name: 'X', total: 1 }, 'dot_separate' ) ).toBe(
			'X 1'
		);
		expect( displayTrait( { name: 'X', total: 2 }, 'dot_separate' ) ).toBe(
			'X●X 2'
		);
	} );

	it( 'non-numeric total parses via leading-integer Val() semantics, defaulting to 0', () => {
		expect(
			displayTrait(
				{ name: 'X', total: '3 (borrowed)' },
				'simple_number'
			)
		).toBe( '3' );
		expect(
			displayTrait( { name: 'X', total: 'borrowed' }, 'simple_number' )
		).toBe( '0' );
	} );

	it( 'empty note behaves like a missing note', () => {
		expect( displayTrait( { name: 'X', total: 3, note: '' }, 'dot' ) ).toBe(
			'X ●●● 3'
		);
		expect(
			displayTrait( { name: 'X', total: 3, note: '' }, 'note_only' )
		).toBe( 'X' );
	} );

	it( 'missing note behaves the same as empty note', () => {
		expect( displayTrait( { name: 'X', total: 3 }, 'cost' ) ).toBe(
			'X (3)'
		);
		expect( displayTrait( { name: 'X', total: 3 }, 'dot_separate' ) ).toBe(
			'X●X●X 3'
		);
	} );

	it( 'a custom dot glyph is honored', () => {
		expect( displayTrait( CELERITY, 'dot', 'o' ) ).toBe(
			'Celerity ooo 3 (Fast)'
		);
	} );

	it( 'cost_number: name, total, "XP", then note - never dots', () => {
		expect(
			displayTrait(
				{ name: 'Bestial Charm', total: 6, note: 'Malkavian' },
				'cost_number'
			)
		).toBe( 'Bestial Charm 6 XP (Malkavian)' );
	} );

	it( 'cost_number omits the parens with no note', () => {
		expect(
			displayTrait( { name: 'Bestial Charm', total: 6 }, 'cost_number' )
		).toBe( 'Bestial Charm 6 XP' );
	} );

	it( 'cost_number shows 0 XP rather than nothing', () => {
		expect(
			displayTrait( { name: 'Free Combo', total: 0 }, 'cost_number' )
		).toBe( 'Free Combo 0 XP' );
	} );

	// 1.2.11 D94: the owner's chosen shape for a count_is_cost block's default.
	it( 'cost_xp: the price sits in parentheses after the name', () => {
		expect(
			displayTrait( { name: 'Draw Fire', total: 12 }, 'cost_xp' )
		).toBe( 'Draw Fire (12 XP)' );
	} );

	it( 'cost_xp: a note joins the price inside the same parens', () => {
		expect(
			displayTrait(
				{ name: 'Emerge Unscathed', total: 8, note: 'Tremere' },
				'cost_xp'
			)
		).toBe( 'Emerge Unscathed (8 XP, Tremere)' );
	} );

	it( 'cost_xp: a zero price is still labelled, never blank', () => {
		expect(
			displayTrait( { name: 'Free Combo', total: 0 }, 'cost_xp' )
		).toBe( 'Free Combo (0 XP)' );
	} );
} );

interface TraitDisplayFixtureCase {
	name: string;
	trait: Trait;
	mode: DisplayType;
	dot?: string;
}

interface TraitDisplayFixtureExpected {
	name: string;
	output: string;
}

/**
 * Same fixture, same expected output as `tests/unit/Display/TraitDisplayParityTest.php`
 * - this is the TypeScript half of proving the two renderers agree.
 */
describe( 'displayTrait — parity with Trait_Display.php', () => {
	it( 'matches every shared fixture case', () => {
		const cases = input as TraitDisplayFixtureCase[];
		const expectedCases = expected as TraitDisplayFixtureExpected[];

		expect( cases.length ).toBe( expectedCases.length );

		cases.forEach( ( testCase, i ) => {
			const expectedCase = expectedCases[ i ];
			expect( testCase.name ).toBe( expectedCase.name );
			expect(
				testCase.dot === undefined
					? displayTrait( testCase.trait, testCase.mode )
					: displayTrait(
							testCase.trait,
							testCase.mode,
							testCase.dot
					  )
			).toBe( expectedCase.output );
		} );
	} );
} );
