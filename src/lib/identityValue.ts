/**
 * How an identity field's stored value reads on a sheet - the same rule
 * `Sheet_Document::identity_field_rows()` applies on the signed sheet, so
 * the two never disagree (1.0.0-review F-078).
 */

/**
 * A field's value as text: a multiselect's choices joined with ", ", a
 * plain value as it is, or null when there is nothing to show.
 */
export function identityValueText( value: unknown ): string | null {
	const text = Array.isArray( value )
		? value.map( String ).join( ', ' )
		: value;
	return text === undefined || text === null || text === ''
		? null
		: String( text );
}
