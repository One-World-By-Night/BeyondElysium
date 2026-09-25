import {
	resolveDisplay,
	resolveTraitListMode,
	groupByCategory,
	sortIfAlphabetized,
	groupsAndSorts,
	groupHeading,
} from './TraitListRenderer';
import type { Trait, DisplayType } from '../../lib/displayTrait';
import type { TraitListDefinition } from '../../types';
import input from '../../../tests/fixtures/trait-grouping-input.json';
import expected from '../../../tests/fixtures/trait-grouping-expected.json';

/**
 * Same fixture, same expected output as `tests/unit/Display/TraitGroupingParityTest.php`'s
 * `resolve_display()`/`group_by_category()`/`sort_if_alphabetized()` cases.
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
 * A player_order block (Rituals) never groups or alphabetizes.
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

describe( 'groupHeading', () => {
	it( 'names a group when the section has more than one', () => {
		expect( groupHeading( 'Tremere', 2 ) ).toBe( 'Tremere' );
	} );

	it( 'leaves the only group unnamed', () => {
		expect( groupHeading( 'Other', 1 ) ).toBeNull();
	} );

	it( 'keeps an unlabelled group unlabelled', () => {
		expect( groupHeading( null, 3 ) ).toBeNull();
	} );
} );
