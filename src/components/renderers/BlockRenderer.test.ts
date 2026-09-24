import { toTraits } from './BlockRenderer';
import fixtureInput from '../../../tests/fixtures/trait-grouping-input.json';
import fixtureExpected from '../../../tests/fixtures/trait-grouping-expected.json';

describe( 'toTraits (D25)', () => {
	it( 'maps a stored entry\'s "count" field onto Trait.total', () => {
		expect( toTraits( [ { name: 'Occult', count: 3 } ] ) ).toEqual( [
			{ name: 'Occult', total: 3, note: undefined },
		] );
	} );

	it( 'prefers an explicit "total" over "count" when both are present', () => {
		expect(
			toTraits( [ { name: 'Occult', total: 5, count: 3 } ] )
		).toEqual( [ { name: 'Occult', total: 5, note: undefined } ] );
	} );

	it( 'preserves note', () => {
		expect(
			toTraits( [
				{ name: 'Occult', count: 2, note: 'Specializes in wards' },
			] )
		).toEqual( [
			{ name: 'Occult', total: 2, note: 'Specializes in wards' },
		] );
	} );

	it( 'returns an empty array for non-array input', () => {
		expect( toTraits( undefined ) ).toEqual( [] );
		expect( toTraits( null ) ).toEqual( [] );
		expect( toTraits( {} ) ).toEqual( [] );
	} );

	it( 'defaults total to undefined (renders as 0 via parseTotal) when neither key is present', () => {
		expect( toTraits( [ { name: 'Iron Will' } ] ) ).toEqual( [
			{ name: 'Iron Will', total: undefined, note: undefined },
		] );
	} );

	describe( 'specialization (same shape as D25 - a real field silently dropped)', () => {
		it( 'folds a bare specialization into note, same slot displayTrait() renders parenthetically', () => {
			expect(
				toTraits( [
					{ name: 'Melee', count: 2, specialization: 'Sword' },
				] )
			).toEqual( [ { name: 'Melee', total: 2, note: 'Sword' } ] );
		} );

		it( 'combines specialization and a separate note, specialization first', () => {
			expect(
				toTraits( [
					{
						name: 'Melee',
						count: 2,
						specialization: 'Sword',
						note: 'borrowed',
					},
				] )
			).toEqual( [
				{ name: 'Melee', total: 2, note: 'Sword, borrowed' },
			] );
		} );

		it( 'is a no-op when specialization is absent', () => {
			expect(
				toTraits( [ { name: 'Occult', count: 2, note: 'wards' } ] )
			).toEqual( [ { name: 'Occult', total: 2, note: 'wards' } ] );
		} );
	} );
} );

/**
 * Same fixture, same expected output as `tests/unit/Display/TraitGroupingParityTest.php`'s
 * `test_to_traits_reads_count_falling_back_to_total()`.
 */
describe( 'toTraits — parity with Trait_Grouping::to_traits() (D25)', () => {
	fixtureInput.toTraits.forEach( ( testCase, i ) => {
		it( `matches the shared fixture: ${ testCase.case }`, () => {
			const result = toTraits( testCase.data );

			// `toTraits()` genuinely returns `undefined` for a missing total/note (asserted above).
			const normalized = result.map( ( trait ) => ( {
				name: trait.name,
				total: trait.total ?? null,
				note: trait.note ?? null,
			} ) );

			expect( normalized ).toEqual( fixtureExpected.toTraits[ i ] );
		} );
	} );
} );
