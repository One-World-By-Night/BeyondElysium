import {
	resolveDisplay,
	groupByCategory,
	sortIfAlphabetized,
} from './TraitListRenderer';
import type { Trait, DisplayType } from '../../lib/displayTrait';
import type { TraitListDefinition } from '../../types';
import input from '../../../tests/fixtures/trait-grouping-input.json';
import expected from '../../../tests/fixtures/trait-grouping-expected.json';

/**
 * Same fixture, same expected output as `tests/unit/Display/TraitGroupingParityTest.php`'s
 * `resolve_display()`/`group_by_category()`/`sort_if_alphabetized()` cases - the TypeScript
 * half of proving these three pure helpers agree with their `Trait_Grouping` PHP twin.
 */
describe( 'TraitListRenderer helpers — parity with Trait_Grouping', () => {
	describe( 'resolveDisplay', () => {
		input.resolveDisplay.forEach( ( testCase, i ) => {
			it( `matches the shared fixture: ${ testCase.case }`, () => {
				const result = resolveDisplay(
					testCase.sectionDisplay as unknown as DisplayType | null,
					testCase.blockDisplay as unknown as DisplayType | undefined
				);
				expect( result ).toEqual( expected.resolveDisplay[ i ] );
			} );
		} );
	} );

	describe( 'groupByCategory', () => {
		input.groupByCategory.forEach( ( testCase, i ) => {
			it( `matches the shared fixture: ${ testCase.case }`, () => {
				const result = groupByCategory(
					testCase.data as unknown as Trait[],
					testCase.definition as unknown as TraitListDefinition
				);
				expect( result ).toEqual( expected.groupByCategory[ i ] );
			} );
		} );
	} );

	describe( 'sortIfAlphabetized', () => {
		input.sortIfAlphabetized.forEach( ( testCase, i ) => {
			it( `matches the shared fixture: ${ testCase.case }`, () => {
				const alphabetize = ( testCase as { alphabetize?: boolean } )
					.alphabetize;
				const result = sortIfAlphabetized(
					testCase.traits as unknown as Trait[],
					alphabetize
				);
				expect( result ).toEqual( expected.sortIfAlphabetized[ i ] );
			} );
		} );
	} );
} );
