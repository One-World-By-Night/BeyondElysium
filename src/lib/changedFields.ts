/**
 * The fields of a form that differ from what was last loaded or saved, so a
 * save writes only what was edited and never puts back a stale copy of a
 * field someone else changed meanwhile (1.0.0-review F-076).
 */

/**
 * Returns each field of `current` whose value differs from `saved`.
 */
export function changedFields< T extends Record< string, string > >(
	current: T,
	saved: T
): Partial< T > {
	const changed: Partial< T > = {};
	for ( const key of Object.keys( current ) as Array< keyof T > ) {
		if ( current[ key ] !== saved[ key ] ) {
			changed[ key ] = current[ key ];
		}
	}
	return changed;
}
