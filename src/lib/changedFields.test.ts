/**
 * `changedFields` sends only the fields that were edited, including one cleared
 * back to empty, and nothing when nothing was edited.
 */
import { changedFields } from './changedFields';

describe( 'changedFields', () => {
	const loaded = { biography: '<p>Came to the city in 1990.</p>', notes: '' };

	it( 'sends only the field that was edited', () => {
		expect(
			changedFields(
				{ ...loaded, biography: '<p>Came to the city in 1989.</p>' },
				loaded
			)
		).toEqual( { biography: '<p>Came to the city in 1989.</p>' } );
	} );

	it( 'sends nothing when nothing was edited', () => {
		expect( changedFields( { ...loaded }, loaded ) ).toEqual( {} );
	} );

	it( 'sends a field cleared back to empty', () => {
		expect( changedFields( { ...loaded, biography: '' }, loaded ) ).toEqual(
			{ biography: '' }
		);
	} );
} );
