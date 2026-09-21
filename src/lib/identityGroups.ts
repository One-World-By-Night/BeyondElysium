/**
 * A character's own identity values, as candidate pick-list section names (1.2.9 U4c).
 *
 * A Werewolf player's own Breed, Auspice and Tribe are exactly the `group` values
 * `werewolf-gifts` uses, so the sections they buy from most can sort to the front of a
 * 510-item list. The same is true of a Fera's species, a Mage's Tradition, a Changeling's
 * Kith - which is why this reads **every** `*-identity` block's string values rather than
 * naming fields per creature type. Decision 011: schema blocks declare, engines interpret;
 * there is no `if ( stack === 'werewolf' )` here and there must never be one.
 *
 * A value that matches no section simply ranks last and costs nothing - `groupCatalogItems`
 * never removes a section, so a wrong or unmatched value degrades to the catalog's own
 * order rather than to a broken list.
 *
 * **Known to under-match, deliberately not papered over.** D71: four of `werewolf-identity`'s
 * 22 Tribe values do not equal their gift group - `Bone Gnawers` against `Bone Gnawer`,
 * `Fenrir` against `Get of Fenris`, plus Croatan and White Howlers which genuinely have no
 * gift data. Bone Gnawer is the single largest gift group at 44 items, and it currently
 * matches nothing. A fuzzy matcher here would hide that; the fix is the data, in 1.3.0.
 */

/** Every block whose values describe who the character is, rather than what they hold. */
const IDENTITY_BLOCK_SUFFIX = '-identity';

/**
 * Every string identity value on the sheet, in the order encountered.
 *
 * Numbers are skipped: `Rank` and `Generation` are identity fields too, and `3` is not a
 * section name in any catalog.
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
