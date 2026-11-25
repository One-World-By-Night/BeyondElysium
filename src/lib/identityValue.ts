/**
 * How an identity field's stored value reads on a sheet.
 */

/**
 * A field's value as text: a multiselect's choices joined with ", ", a plain value as it is, or null when there is
 * nothing to show.
 */
export function identityValueText( value: unknown ): string | null {
	const text = Array.isArray( value )
		? value.map( String ).join( ', ' )
		: value;
	return text === undefined || text === null || text === ''
		? null
		: String( text );
}

/**
 * The fields that hold something to show, in the order given.
 */
export function filledIdentityFields< T extends { name: string } >(
	fields: ReadonlyArray< T >,
	values: Record< string, unknown > | null | undefined
): T[] {
	return fields.filter(
		( field ) => identityValueText( values?.[ field.name ] ) !== null
	);
}
