import { describe, expect, it } from 'vitest';
import { highlightStMarkers } from './highlightStMarkers';

describe( 'highlightStMarkers', () => {
	it( 'wraps a single marked span in a highlight tag', () => {
		expect(
			highlightStMarkers(
				'A test fixture. [ST]This bracketed part is ST-only.[/ST] Public again.'
			)
		).toBe(
			'A test fixture. <mark class="be-st-marker">This bracketed part is ST-only.</mark> Public again.'
		);
	} );

	it( 'wraps a marked span that straddles an HTML tag boundary', () => {
		expect(
			highlightStMarkers(
				'<p>The locket keeps its wearer calm. <b>[ST]It is also binding,</b> owes a favor,[/ST] though they forget.</p>'
			)
		).toBe(
			'<p>The locket keeps its wearer calm. <b><mark class="be-st-marker">It is also binding,</b> owes a favor,</mark> though they forget.</p>'
		);
	} );

	it( 'highlights every pair when several exist', () => {
		expect( highlightStMarkers( '[ST]one[/ST] middle [ST]two[/ST]' ) ).toBe(
			'<mark class="be-st-marker">one</mark> middle <mark class="be-st-marker">two</mark>'
		);
	} );

	it( 'highlights to the end of the string on an unterminated opener', () => {
		expect( highlightStMarkers( 'before [ST]unterminated' ) ).toBe(
			'before <mark class="be-st-marker">unterminated</mark>'
		);
	} );

	it( 'returns ordinary text unchanged', () => {
		expect( highlightStMarkers( 'Nothing marked here.' ) ).toBe(
			'Nothing marked here.'
		);
	} );

	it( 'returns an empty string unchanged', () => {
		expect( highlightStMarkers( '' ) ).toBe( '' );
	} );
} );
