/**
 * `emptyFieldsOf()` finds which fields are still blank. `mergeDrafted()` keeps only the drafted values for
 * those fields, dropping anything blank or missing.
 */
import { emptyFieldsOf, mergeDrafted } from './NpcRoleplayingDraftButton';

describe( 'emptyFieldsOf', () => {
	it( 'returns every field that is blank or whitespace-only', () => {
		expect(
			emptyFieldsOf( {
				Voice: '',
				Mannerisms: '  ',
				Motivations: 'Already written.',
			} )
		).toEqual( [ 'Voice', 'Mannerisms' ] );
	} );

	it( 'returns an empty list once every field has text', () => {
		expect( emptyFieldsOf( { Voice: 'Gravelly.' } ) ).toEqual( [] );
	} );

	it( 'counts a declared field with no value yet as empty', () => {
		expect(
			emptyFieldsOf( { Voice: 'Gravelly.' }, [
				'Voice',
				'Wants',
				'Knows',
			] )
		).toEqual( [ 'Wants', 'Knows' ] );
	} );

	it( 'finds every declared field empty on a character whose notes were never written', () => {
		expect( emptyFieldsOf( {}, [ 'Voice', 'Wants' ] ) ).toEqual( [
			'Voice',
			'Wants',
		] );
	} );
} );

describe( 'mergeDrafted', () => {
	it( 'keeps only the named empty fields from the draft', () => {
		const merged = mergeDrafted( [ 'Voice', 'Mannerisms' ], {
			Voice: 'Gravelly.',
			Mannerisms: 'Taps fingers when lying.',
			Motivations: 'Never sent, since it was not empty.',
		} );

		expect( merged ).toEqual( {
			Voice: 'Gravelly.',
			Mannerisms: 'Taps fingers when lying.',
		} );
	} );

	it( 'drops a field the draft left blank', () => {
		expect( mergeDrafted( [ 'Voice' ], { Voice: '   ' } ) ).toEqual( {} );
	} );

	it( 'drops a field the draft never answered at all', () => {
		expect(
			mergeDrafted( [ 'Voice', 'Mannerisms' ], { Voice: 'Gravelly.' } )
		).toEqual( {
			Voice: 'Gravelly.',
		} );
	} );
} );
