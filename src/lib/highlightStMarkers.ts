/**
 * Wraps `[ST]...[/ST]`-marked text in a highlight span, for a viewer who holds the matching manage
 * capability and therefore receives the raw, unstripped markers from the server.
 */

const START = '[ST]';
const END = '[/ST]';
const OPEN_TAG = '<mark class="be-st-marker">';
const CLOSE_TAG = '</mark>';

/**
 * Replaces every `[ST]`/`[/ST]` pair in `html` with a highlight span, scanning left to right and leaving
 * any text outside a marked section untouched. An unterminated opener highlights to the end of the string,
 * matching `St_Filter::strip()`'s own handling of the same case.
 */
export function highlightStMarkers( html: string ): string {
	if ( ! html || ! html.includes( START ) ) {
		return html;
	}

	let result = '';
	let pos = 0;

	while ( pos < html.length ) {
		const start = html.indexOf( START, pos );
		if ( start === -1 ) {
			result += html.slice( pos );
			break;
		}

		result += html.slice( pos, start );

		const end = html.indexOf( END, start + START.length );
		if ( end === -1 ) {
			result += OPEN_TAG + html.slice( start + START.length ) + CLOSE_TAG;
			break;
		}

		result +=
			OPEN_TAG + html.slice( start + START.length, end ) + CLOSE_TAG;
		pos = end + END.length;
	}

	return result;
}
