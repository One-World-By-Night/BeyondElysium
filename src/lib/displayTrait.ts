/**
 * Defines the eleven trait display modes and renders a single trait as a formatted
 * string according to the selected mode. Exports the `DisplayType` union, the `Trait`
 * shape it reads, and `displayTrait()`, the pure rendering function - the same input
 * always produces the same output, with no DOM or network access.
 */

export type DisplayType =
	| 'simple'
	| 'multiplier'
	| 'multiplier_dot'
	| 'dot'
	| 'cost'
	| 'note_only'
	| 'cost_only'
	| 'dot_separate'
	| 'simple_dots'
	| 'simple_number'
	| 'simple_note';

export interface Trait {
	name: string;
	total?: number | string;
	note?: string;
}

/**
 * Parses a leading integer from the start of a trait's total value, stopping at the
 * first non-digit character. A numeric value passes through directly; a string that
 * doesn't begin with a number (e.g. "borrowed" rather than "3 (borrowed)") parses to 0.
 */
function parseTotal( total: Trait[ 'total' ] ): number {
	if ( typeof total === 'number' ) {
		return Number.isFinite( total ) ? Math.trunc( total ) : 0;
	}
	if ( typeof total === 'string' ) {
		const match = total.trim().match( /^-?\d+/ );
		return match ? parseInt( match[ 0 ], 10 ) : 0;
	}
	return 0;
}

/**
 * Renders a trait's name, total, and note as a display string, with the exact format
 * determined by `mode`: some modes show a multiplier count, some render dots, some fold
 * the note into parentheses, and some drop the name entirely. `dot` is the character
 * used for dot-rendering modes, defaulting to a bullet.
 */
export function displayTrait( trait: Trait, mode: DisplayType, dot = '•' ): string {
	const total = parseTotal( trait.total );
	const note = trait.note ?? '';
	const dots = ( count: number ): string => dot.repeat( Math.max( 0, count ) );

	switch ( mode ) {
		case 'simple':
			// Note suppressed — one of only two modes that drop it.
			return trait.name;

		case 'multiplier': {
			// Hides x1. Every other numeric total, including 0, shows.
			let out = trait.name;
			if ( total !== 1 ) {
				out += ` x${ total }`;
			}
			return note ? `${ out } (${ note })` : out;
		}

		case 'multiplier_dot': {
			// Unlike 'multiplier', the x-count always shows, even at x1.
			let out = `${ trait.name } x${ total }`;
			const d = dots( total );
			if ( d ) {
				out += ` ${ d }`;
			}
			return note ? `${ out } (${ note })` : out;
		}

		case 'dot': {
			let out = trait.name;
			const d = dots( total );
			if ( d ) {
				out += ` ${ d }`;
			}
			return note ? `${ out } (${ note })` : out;
		}

		case 'cost':
			// Note lives inside the parens, comma-joined with the total.
			return `${ trait.name } (${ note ? `${ total }, ${ note }` : total })`;

		case 'note_only':
			return note ? `${ trait.name } (${ note })` : trait.name;

		case 'cost_only':
			// Note excluded, unlike 'cost'.
			return `${ trait.name } (${ total })`;

		case 'dot_separate': {
			// The note is folded into the repeated label rather than appended once; count is at least 1.
			const label = note ? `${ trait.name } (${ note })` : trait.name;
			const count = total < 2 ? 1 : total;
			return new Array( count ).fill( label ).join( dot );
		}

		case 'simple_dots':
			// The name is dropped entirely; only dots are shown.
			return dots( total );

		case 'simple_number':
			return String( total );

		case 'simple_note':
			return note;

		default:
			return trait.name;
	}
}
