/**
 * Defines the twelve trait display modes and renders a single trait as a formatted
 * string according to the selected mode. Exports the `DisplayType` union, the `Trait`
 * shape it reads, and `displayTrait()`, the pure rendering function - the same input
 * always produces the same output, with no DOM or network access.
 */

import { DOT } from './displayTemper';

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
	| 'simple_note'
	| 'cost_number';

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
export function parseTotal( total: Trait[ 'total' ] ): number {
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
 * used for dot-rendering modes, defaulting to the one dot a resource pool's points use too
 * (1.0.0-review F-016).
 */
export function displayTrait(
	trait: Trait,
	mode: DisplayType,
	dot = DOT
): string {
	if ( mode === 'simple' ) {
		// Note suppressed — one of only two modes that drop it.
		return trait.name;
	}

	const total = parseTotal( trait.total );
	const note = trait.note ?? '';
	const dots = ( count: number ): string =>
		dot.repeat( Math.max( 0, count ) );

	switch ( mode ) {
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
			// 1.1.0 D1: the count always follows the dots, even at 0 (no dots to follow).
			out += d ? ` ${ d } ${ total }` : ` ${ total }`;
			return note ? `${ out } (${ note })` : out;
		}

		case 'cost':
			// Note lives inside the parens, comma-joined with the total.
			return `${ trait.name } (${
				note ? `${ total }, ${ note }` : total
			})`;

		case 'note_only':
			return note ? `${ trait.name } (${ note })` : trait.name;

		case 'cost_only':
			// Note excluded, unlike 'cost'.
			return `${ trait.name } (${ total })`;

		case 'dot_separate': {
			// The note is folded into the repeated label rather than appended once; count is at least 1.
			const label = note ? `${ trait.name } (${ note })` : trait.name;
			const count = total < 2 ? 1 : total;
			const repeated = new Array( count ).fill( label ).join( dot );
			// 1.1.0 D1: the real total follows, even though the repeat count above is
			// clamped to a minimum of 1 and so can't itself be read as the count.
			return `${ repeated } ${ total }`;
		}

		case 'simple_dots': {
			// The name is dropped entirely; only dots (and, per D1, the count) are shown.
			const d = dots( total );
			return d ? `${ d } ${ total }` : `${ total }`;
		}

		case 'simple_number':
			return String( total );

		case 'simple_note':
			return note;

		case 'cost_number': {
			// 1.1.0 D3: for a count_is_cost block, the stored total is a flat XP
			// cost, not a rating - drawn as a number instead of dots when the
			// viewer's cost-numbers preference is on.
			const out = `${ trait.name } ${ total } XP`;
			return note ? `${ out } (${ note })` : out;
		}

		default:
			return trait.name;
	}
}
