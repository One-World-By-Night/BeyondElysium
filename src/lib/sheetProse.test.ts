import { showsProseSection } from './sheetProse';

const BIOGRAPHY = '<p>Embraced in 1888.</p>';

describe( 'showsProseSection', () => {
	it( 'shows text on the sheet page even when it is not chosen for printing', () => {
		expect( showsProseSection( BIOGRAPHY, false, false ) ).toBe( true );
	} );

	it( 'shows text on the sheet page when it is chosen for printing', () => {
		expect( showsProseSection( BIOGRAPHY, false, true ) ).toBe( true );
	} );

	it( 'leaves text off the print canvas unless it was chosen for printing', () => {
		expect( showsProseSection( BIOGRAPHY, true, false ) ).toBe( false );
		expect( showsProseSection( BIOGRAPHY, true, true ) ).toBe( true );
	} );

	it( 'never shows a section with no text', () => {
		expect( showsProseSection( '', false, true ) ).toBe( false );
		expect( showsProseSection( null, false, true ) ).toBe( false );
		expect( showsProseSection( undefined, true, true ) ).toBe( false );
	} );
} );
