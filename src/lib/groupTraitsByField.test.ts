import { groupTraitsByField } from './groupTraitsByField';
import type { TraitListDefinition } from '../types';
import input from '../../tests/fixtures/trait-grouping-input.json';
import expected from '../../tests/fixtures/trait-grouping-expected.json';

/**
 * Same fixture, same expected output as `tests/unit/Display/TraitGroupingParityTest.php`'s
 * `test_group_traits_by_field_matches_the_shared_fixture()`.
 */
describe( 'groupTraitsByField — parity with Trait_Grouping::group_traits_by_field()', () => {
	input.groupTraitsByField.forEach( ( testCase, i ) => {
		it( `matches the shared fixture: ${ testCase.case }`, () => {
			const result = groupTraitsByField(
				testCase.data as unknown as Array< { name: string } >,
				testCase.definition as unknown as TraitListDefinition
			);
			expect( result ).toEqual( expected.groupTraitsByField[ i ] );
		} );
	} );
} );
