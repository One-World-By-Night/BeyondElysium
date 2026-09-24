/**
 * A character's own identity values, as candidate pick-list section names.
 */

/**
 * Every block whose values describe who the character.
 */
const IDENTITY_BLOCK_SUFFIX = '-identity';

/**
 * Every string identity value on the sheet, in the order encountered.
 */
export function identityGroupValues(
	sheetData?: Record< string, unknown >
): string[] {
	if ( ! sheetData ) {
		return [];
	}

	const values: string[] = [];
	for ( const [ blockSlug, block ] of Object.entries( sheetData ) ) {
		if (
			! blockSlug.endsWith( IDENTITY_BLOCK_SUFFIX ) ||
			! block ||
			typeof block !== 'object' ||
			Array.isArray( block )
		) {
			continue;
		}
		for ( const value of Object.values(
			block as Record< string, unknown >
		) ) {
			// A multiselect identity field stores an array; each of its values counts.
			for ( const one of Array.isArray( value ) ? value : [ value ] ) {
				if ( typeof one === 'string' && one.trim() !== '' ) {
					values.push( one.trim() );
				}
			}
		}
	}
	return values;
}
