/**
 * Pure array-reordering helpers for a player_order block's Reorder UI (1.1.0 D4):
 * move one position up or down, or drop it at an arbitrary new position (drag and
 * drop). All three return a new array; none mutate the input. Shared by
 * `TraitListEditor.tsx` (Rituals) and `TieredPowerEditor.tsx` (Blood Magic), the
 * two seeded `player_order` blocks - one of each section_type.
 */

/** Swaps `index` with its predecessor. A no-op at the front of the list. */
export function moveUp< T >( items: T[], index: number ): T[] {
	if ( index <= 0 || index >= items.length ) {
		return items;
	}
	return swap( items, index, index - 1 );
}

/** Swaps `index` with its successor. A no-op at the end of the list. */
export function moveDown< T >( items: T[], index: number ): T[] {
	if ( index < 0 || index >= items.length - 1 ) {
		return items;
	}
	return swap( items, index, index + 1 );
}

/** Removes the item at `from` and reinserts it at `to` (drag and drop). */
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
 * Reads a REST error's own `message`, falling back to a generic one - the same
 * "show the server's reason when it gave one" convention `SheetStyleEditor.tsx`
 * already uses for its own apiFetch calls.
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
