import { sheetRow } from './sheetDocument';

/**
 * A casting brief row is plain text, or a printed line's text with its indent and the rating a printed sheet draws as
 * empty circles; the screen shows the text either way.
 */
describe( 'sheetRow', () => {
	it( 'reads a plain row as its text', () => {
		expect( sheetRow( 'Animalism 2' ) ).toEqual( {
			text: 'Animalism 2',
			indent: 0,
		} );
	} );

	it( 'reads a ringed row as its text, never the object', () => {
		expect(
			sheetRow( { text: 'Occult x3 (Rituals)', indent: 0, circles: 3 } )
		).toEqual( { text: 'Occult x3 (Rituals)', indent: 0 } );
	} );

	it( 'keeps an indented row indented', () => {
		expect( sheetRow( { text: 'Feral Whispers', indent: 1 } ) ).toEqual( {
			text: 'Feral Whispers',
			indent: 1,
		} );
	} );

	it( 'treats a row with no indent as unindented', () => {
		expect( sheetRow( { text: 'Willpower x6', circles: 6 } ) ).toEqual( {
			text: 'Willpower x6',
			indent: 0,
		} );
	} );
} );
