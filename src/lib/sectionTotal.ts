/**
 * Sums a trait_list section's held entries into one total, shown after the section
 * title (1.1.0 D1) - but only when every entry genuinely carries a numeric count; a
 * block mixing counted and note-only entries (Rituals, Merits, and similar atomic
 * lists a caller should exclude before calling this at all) has no honest total to
 * show, so a single non-numeric or missing total anywhere in the list makes the
 * whole section total null rather than a partial or fabricated sum.
 */
import { parseTotal, type Trait } from './displayTrait';

function isNumericTotal( total: Trait[ 'total' ] ): boolean {
	if ( typeof total === 'number' ) {
		return Number.isFinite( total );
	}
	if ( typeof total === 'string' ) {
		return /^-?\d+$/.test( total.trim() );
	}
	return false;
}

/**
 * Returns the sum of every entry's total, or null when the list is empty or any
 * entry's total is missing or not a plain integer.
 */
export function sectionTotal( traits: Trait[] ): number | null {
	if ( traits.length === 0 ) {
		return null;
	}

	let sum = 0;
	for ( const trait of traits ) {
		if ( ! isNumericTotal( trait.total ) ) {
			return null;
		}
		sum += parseTotal( trait.total );
	}
	return sum;
}

export default sectionTotal;
