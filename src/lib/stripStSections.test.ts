import { stripStSections } from './stripStSections';

/**
 * Same behavior, same edge cases as tests/unit/StFilterTest.php (the authoritative
 * server-side twin) - including the double-space-in-the-middle quirk from Trim() only
 * stripping the outer edges. That is faithful VB behavior, not a bug.
 */
describe( 'stripStSections', () => {
	it( 'strips a single marked section', () => {
		expect( stripStSections( 'Before [ST]hidden[/ST] After', '[ST]', '[/ST]' ) ).toBe( 'Before  After' );
	} );

	it( 'strips repeatedly for multiple sections', () => {
		expect( stripStSections( 'A [ST]x[/ST] B [ST]y[/ST] C', '[ST]', '[/ST]' ) ).toBe( 'A  B  C' );
	} );

	it( 'an unterminated opener runs to the end of the string', () => {
		expect( stripStSections( 'Before [ST]hidden forever', '[ST]', '[/ST]' ) ).toBe( 'Before' );
	} );

	it( 'trims only the outer edges, not whitespace left in the middle', () => {
		expect( stripStSections( 'Before   [ST]hidden[/ST]   After  ', '[ST]', '[/ST]' ) ).toBe(
			'Before' + ' '.repeat( 6 ) + 'After'
		);
	} );

	it( 'an empty start marker disables filtering', () => {
		const text = 'Before [ST]hidden[/ST] After';
		expect( stripStSections( text, '', '[/ST]' ) ).toBe( text );
	} );

	it( 'an empty end marker disables filtering', () => {
		const text = 'Before [ST]hidden[/ST] After';
		expect( stripStSections( text, '[ST]', '' ) ).toBe( text );
	} );

	it( 'markers are configurable, not literal', () => {
		expect(
			stripStSections( 'Before {{secret}}hidden{{/secret}} After', '{{secret}}', '{{/secret}}' )
		).toBe( 'Before  After' );
	} );

	it( 'no markers present returns the text unchanged after trim', () => {
		expect( stripStSections( '  Plain text  ', '[ST]', '[/ST]' ) ).toBe( 'Plain text' );
	} );
} );
