/**
 * Removes ST-only (storyteller-only) text between configurable start and end markers from a string.
 */

/**
 * Removes every substring beginning with `startMarker` and ending with `endMarker` (inclusive of both markers) from
 * `text`.
 */
export function stripStSections(
	text: string,
	startMarker: string,
	endMarker: string
): string {
	if ( startMarker === '' || endMarker === '' ) {
		return text;
	}

	let result = '';
	let pos = 0;

	while ( pos < text.length ) {
		const start = text.indexOf( startMarker, pos );
		if ( start === -1 ) {
			result += text.slice( pos );
			break;
		}

		result += text.slice( pos, start );

		const end = text.indexOf( endMarker, start + startMarker.length );
		if ( end === -1 ) {
			// An unterminated opener removes everything from that point to the end of the string.
			break;
		}

		pos = end + endMarker.length;
	}

	return result.trim();
}
