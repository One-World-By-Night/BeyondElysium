import {
	resolveDisplay,
	resolveTraitListMode,
	groupByCategory,
	sortIfAlphabetized,
	groupsAndSorts,
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

	describe( 'resolveTraitListMode', () => {
		input.resolveMode.forEach( ( testCase, i ) => {
			it( `matches the shared fixture: ${ testCase.case }`, () => {
				const result = resolveTraitListMode(
					testCase.definition as unknown as TraitListDefinition,
					testCase.sectionDisplay as unknown as DisplayType | null,
					( testCase.showCost as boolean | null ) ?? undefined
				);
				expect( result ).toEqual( expected.resolveMode[ i ] );
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

/**
 * 1.1.0 D4: a player_order block (Rituals) never groups or alphabetizes -
 * `TraitListRenderer` itself calls this predicate at every point it would
 * otherwise group/sort, so this also proves the component's actual render
 * path skips them, not just a parallel copy of the same logic.
 */
describe( 'groupsAndSorts', () => {
	it( 'is true for an ordinary block', () => {
		expect( groupsAndSorts( { items: [] } ) ).toBe( true );
	} );

	it( 'is true for a block with player_order explicitly false', () => {
		expect( groupsAndSorts( { items: [], player_order: false } ) ).toBe(
			true
		);
	} );

	it( 'is false for a player_order block', () => {
		expect( groupsAndSorts( { items: [], player_order: true } ) ).toBe(
			false
		);
	} );
} );
