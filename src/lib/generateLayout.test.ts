import { generateLayout, type StackLike, type SchemaBlockLike } from './generateLayout';
import input from '../../tests/fixtures/layout-generator-input.json';
import expected from '../../tests/fixtures/layout-generator-expected.json';

/**
 * Same fixture, same expected output as `tests/unit/LayoutGeneratorParityTest.php` - this
 * is the TypeScript half of proving the two generators agree.
 */
describe( 'generateLayout — parity with Layout_Generator.php', () => {
	it( 'matches the shared fixture exactly', () => {
		const layout = generateLayout(
			input.stack as StackLike,
			input.blocks as unknown as Record<string, SchemaBlockLike>
		);

		expect( layout ).toEqual( expected );
	} );
} );
