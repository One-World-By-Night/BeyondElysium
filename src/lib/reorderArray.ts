/**
 * Pure array-reordering helpers for a player_order block's Reorder UI: move one position up or down, or drop it at an
 * arbitrary new position (drag and drop).
 */

/**
 * Swaps `index` with its predecessor.
 */
export function moveUp< T >( items: T[], index: number ): T[] {
	if ( index <= 0 || index >= items.length ) {
		return items;
	}
	return swap( items, index, index - 1 );
}

/**
 * Swaps `index` with its successor.
 */
export function moveDown< T >( items: T[], index: number ): T[] {
	if ( index < 0 || index >= items.length - 1 ) {
		return items;
	}
	return swap( items, index, index + 1 );
}

/**
 * Removes the item at `from` and reinserts it at `to` (drag and drop).
 */
export function moveTo< T >( items: T[], from: number, to: number ): T[] {
	if (
		from === to ||
		from < 0 ||
		to < 0 ||
		from >= items.length ||
		to >= items.length
	) {
		return items;
	}
	const next = [ ...items ];
	const [ moved ] = next.splice( from, 1 );
	next.splice( to, 0, moved );
	return next;
}

function swap< T >( items: T[], a: number, b: number ): T[] {
	const next = [ ...items ];
	[ next[ a ], next[ b ] ] = [ next[ b ], next[ a ] ];
	return next;
}

/**
 * Reads a REST error's own `message`, falling back to a generic one.
 */
export function reorderErrorMessage( err: unknown, fallback: string ): string {
	if (
		err &&
		typeof err === 'object' &&
		'message' in err &&
		typeof ( err as { message?: unknown } ).message === 'string'
	) {
		return ( err as { message: string } ).message;
	}
	return fallback;
}
