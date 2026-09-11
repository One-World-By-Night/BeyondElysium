import { toTraits } from './BlockRenderer';

describe( 'toTraits (D25)', () => {
	it( 'maps a stored entry\'s "count" field onto Trait.total', () => {
		expect( toTraits( [ { name: 'Occult', count: 3 } ] ) ).toEqual( [
			{ name: 'Occult', total: 3, note: undefined },
		] );
	} );

	it( 'prefers an explicit "total" over "count" when both are present', () => {
		expect( toTraits( [ { name: 'Occult', total: 5, count: 3 } ] ) ).toEqual( [
			{ name: 'Occult', total: 5, note: undefined },
		] );
	} );

	it( 'preserves note', () => {
		expect( toTraits( [ { name: 'Occult', count: 2, note: 'Specializes in wards' } ] ) ).toEqual( [
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
			expect( toTraits( [ { name: 'Melee', count: 2, specialization: 'Sword' } ] ) ).toEqual( [
				{ name: 'Melee', total: 2, note: 'Sword' },
			] );
		} );

		it( 'combines specialization and a separate note, specialization first', () => {
			expect( toTraits( [
				{ name: 'Melee', count: 2, specialization: 'Sword', note: 'borrowed' },
			] ) ).toEqual( [ { name: 'Melee', total: 2, note: 'Sword, borrowed' } ] );
		} );

		it( 'is a no-op when specialization is absent', () => {
			expect( toTraits( [ { name: 'Occult', count: 2, note: 'wards' } ] ) ).toEqual( [
				{ name: 'Occult', total: 2, note: 'wards' },
			] );
		} );
	} );
} );
