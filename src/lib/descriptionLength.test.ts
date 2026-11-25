import {
	DESCRIPTION_SOFT_LIMIT,
	isLongDescription,
	plainTextLength,
} from './descriptionLength';

describe( 'how long a description is', () => {
	it( 'counts the characters shown, not the markup', () => {
		expect( plainTextLength( '<p>Short <strong>note</strong>.</p>' ) ).toBe(
			'Short note.'.length
		);
		expect( plainTextLength( '' ) ).toBe( 0 );
	} );

	it( 'treats runs of white space and line breaks as one space', () => {
		expect( plainTextLength( '<p>one</p>\n\n<p>two   three</p>' ) ).toBe(
			'one two three'.length
		);
	} );

	it( 'warns about a section past the soft limit and about no other', () => {
		const filler = 'x'.repeat( DESCRIPTION_SOFT_LIMIT );
		expect( isLongDescription( {} ) ).toBe( false );
		expect(
			isLongDescription( { description: `<p>${ filler }</p>` } )
		).toBe( false );
		expect(
			isLongDescription( { description: `<p>${ filler }y</p>` } )
		).toBe( true );
		expect( isLongDescription( { source: `${ filler }yy` } ) ).toBe( true );
	} );

	it( 'ignores markup when judging the length', () => {
		const tags = '<strong></strong>'.repeat( 200 );
		expect( isLongDescription( { description: `${ tags }short` } ) ).toBe(
			false
		);
	} );
} );
