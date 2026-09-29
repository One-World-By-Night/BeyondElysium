/**
 * The names a searchable picker offers, each mapped back to its row's id: a name two rows share carries the id, so
 * every name picks exactly one row.
 */
export function pickerNames< T extends { id: number } >(
	rows: T[],
	name: ( row: T ) => string
): Map< string, number > {
	const counts = new Map< string, number >();
	for ( const row of rows ) {
		counts.set( name( row ), ( counts.get( name( row ) ) ?? 0 ) + 1 );
	}
	const names = new Map< string, number >();
	for ( const row of rows ) {
		const text = name( row );
		names.set(
			( counts.get( text ) ?? 0 ) > 1 ? `${ text } (#${ row.id })` : text,
			row.id
		);
	}
	return names;
}
