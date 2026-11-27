import { readCreatedReview, rememberCreatedReview } from './createdReview';

describe( 'createdReview', () => {
	afterEach( () => window.sessionStorage.clear() );

	it( 'gives back the picks kept for a character', () => {
		const picks = [
			{ name: 'Occult', reason: 'The chronicle reviews Occult.' },
		];
		expect( rememberCreatedReview( 7, picks ) ).toBe( true );
		expect( readCreatedReview( 7 ) ).toEqual( picks );
	} );

	it( 'keeps each character’s picks apart', () => {
		rememberCreatedReview( 7, [ { name: 'A', reason: null } ] );
		expect( readCreatedReview( 8 ) ).toEqual( [] );
	} );

	it( 'reads nothing from an unreadable value', () => {
		window.sessionStorage.setItem( 'be-created-review-9', '{not json' );
		expect( readCreatedReview( 9 ) ).toEqual( [] );
		window.sessionStorage.setItem(
			'be-created-review-9',
			JSON.stringify( [ 3, null, { name: 'Ok', reason: null } ] )
		);
		expect( readCreatedReview( 9 ) ).toEqual( [
			{ name: 'Ok', reason: null },
		] );
	} );
} );
