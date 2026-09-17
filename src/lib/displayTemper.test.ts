import { displayTemper } from './displayTemper';
import { displayTrait } from './displayTrait';
import temperInput from '../../tests/fixtures/temper-display-input.json';
import temperExpected from '../../tests/fixtures/temper-display-expected.json';

/**
 * 1.0.0-review F-016, owner ruling 2026-09-14: every dot the same size. A pool used Grapevine's
 * letter glyphs and collapsed each run of five into a capital, so a sheet showed a small dot, a
 * ring, and a big capital side by side; now a pool's points are the trait rating's own dot.
 */
describe( 'displayTemper', () => {
	it( 'a full pool renders one filled dot per point', () => {
		expect( displayTemper( { permanent: 3, temporary: 3 } ) ).toBe(
			'●●● 3'
		);
	} );

	it( 'zero pool renders no dots, just the bare number', () => {
		expect( displayTemper( { permanent: 0, temporary: 0 } ) ).toBe( '0' );
	} );

	it( 'temporary below permanent renders an empty dot for each spent point, then temporary/permanent', () => {
		expect( displayTemper( { permanent: 5, temporary: 2 } ) ).toBe(
			'●●○○○ 2/5'
		);
	} );

	it( 'temporary above permanent renders a ringed dot for each extra point, not clamped, then temporary/permanent', () => {
		expect( displayTemper( { permanent: 3, temporary: 5 } ) ).toBe(
			'●●●◉◉ 5/3'
		);
	} );

	it( 'a large pool groups its dots in fives and never collapses them into a bigger glyph', () => {
		expect( displayTemper( { permanent: 15, temporary: 15 } ) ).toBe(
			'●●●●● ●●●●● ●●●●● 15'
		);
		expect( displayTemper( { permanent: 11, temporary: 11 } ) ).toBe(
			'●●●●● ●●●●● ● 11'
		);
	} );

	it( 'groups count every dot, whatever its state', () => {
		expect( displayTemper( { permanent: 12, temporary: 6 } ) ).toBe(
			'●●●●● ●○○○○ ○○ 6/12'
		);
	} );

	it( "a pool's point is the same dot as a trait's rating", () => {
		expect( displayTemper( { permanent: 1, temporary: 1 } ) ).toBe(
			displayTrait( { name: 'Occult', total: 1 }, 'simple_dots' )
		);
	} );
} );

/**
 * Same fixture, same expected output as `tests/unit/Display/TemperDisplayParityTest.php`
 * - this is the TypeScript half of proving the two renderers agree.
 */
describe( 'displayTemper — parity with Temper_Display.php', () => {
	temperInput.forEach( ( testCase, i ) => {
		it( `matches the shared fixture: ${ testCase.name }`, () => {
			expect(
				displayTemper( {
					permanent: testCase.permanent,
					temporary: testCase.temporary,
				} )
			).toBe( temperExpected[ i ].output );
		} );
	} );
} );
