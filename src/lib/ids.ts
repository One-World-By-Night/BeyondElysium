/**
 * Row ids compared as numbers: the plugin's REST routes send ids as text, WordPress's own user routes as numbers.
 */

/**
 * An id as a number, or null for none.
 */
export function idOf( value: unknown ): number | null {
	return value === null || value === undefined || value === ''
		? null
		: Number( value );
}

/**
 * Whether two ids name the same row; a missing id matches nothing.
 */
export function sameId( a: unknown, b: unknown ): boolean {
	const first = idOf( a );
	return first !== null && first === idOf( b );
}
