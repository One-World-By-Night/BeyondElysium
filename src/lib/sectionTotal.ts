/**
 * Sums a trait_list section's held entries into one total, shown after the section title.
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
 * Returns the sum of every entry's total, or null when the list is empty or any entry's total is missing or not a
 * plain integer.
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
