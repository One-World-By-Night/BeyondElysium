/**
 * 1.0.0-review F-076 (Pass H intake). "Save Background & Notes" sent both fields every time,
 * from what the editor loaded. With the sheet open in two places, saving a biography put back
 * the notes as they were when that editor opened - erasing notes saved from the other one.
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
