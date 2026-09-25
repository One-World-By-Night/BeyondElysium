import { sectionCount, sectionTotal } from './sectionTotal';
import type { Trait } from './displayTrait';
import input from '../../tests/fixtures/trait-grouping-input.json';
import expected from '../../tests/fixtures/trait-grouping-expected.json';

describe( 'sectionTotal', () => {
	it( 'sums every entry when all carry a numeric total', () => {
		expect(
			sectionTotal( [
				{ name: 'Occult', total: 3 },
				{ name: 'Melee', total: 2 },
			] )
		).toBe( 5 );
	} );

	it( 'returns null for an empty list', () => {
		expect( sectionTotal( [] ) ).toBeNull();
	} );

	it( 'returns null when any entry has no total at all', () => {
		expect(
			sectionTotal( [ { name: 'Occult', total: 3 }, { name: 'Ritual' } ] )
		).toBeNull();
	} );

	it( 'returns null when any entry has a non-numeric total', () => {
		expect(
			sectionTotal( [
				{ name: 'Occult', total: 3 },
				{ name: 'X', total: '3 (borrowed)' },
			] )
		).toBeNull();
	} );

	it( 'sums numeric-string totals', () => {
		expect(
			sectionTotal( [
				{ name: 'Occult', total: '3' },
				{ name: 'Melee', total: '2' },
			] )
		).toBe( 5 );
	} );

	it( 'a total of 0 on every entry still sums to 0, not null', () => {
		expect(
			sectionTotal( [
				{ name: 'Flaw', total: 0 },
				{ name: 'Merit', total: 0 },
			] )
		).toBe( 0 );
	} );
} );

/**
 * Same fixture, same expected output as `TraitGroupingParityTest::test_section_total_matches_the_shared_fixture()`.
 */
describe( 'sectionTotal — parity with Trait_Grouping::section_total()', () => {
	input.sectionTotal.forEach( ( testCase, i ) => {
		it( `matches the shared fixture: ${ testCase.case }`, () => {
			expect(
				sectionTotal( testCase.traits as unknown as Trait[] )
			).toBe( expected.sectionTotal[ i ] );
		} );
	} );
} );

describe( 'sectionCount', () => {
	it( 'sums the totals of an ordinary list', () => {
		expect(
			sectionCount( [
				{ name: 'Occult', total: 3 },
				{ name: 'Melee', total: 2 },
			] )
		).toBe( 5 );
	} );

	it( 'counts the entries of a list whose counts are prices, never summing the prices', () => {
		expect(
			sectionCount(
				[
					{ name: 'Draw Fire', total: 12 },
					{ name: 'Stunning Awe', total: 7 },
				],
				true
			)
		).toBe( 2 );
	} );

	it( 'shows nothing for an empty list whose counts are prices', () => {
		expect( sectionCount( [], true ) ).toBeNull();
	} );
} );
