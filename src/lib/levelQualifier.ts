/**
 * The discriminator hiding inside a power level's free-text `note`.
 */
import type { PowerLevel, TieredPower } from '../types';
import { allLevels } from './powerLevels';

/**
 * The tier vocabulary, longest-matching-intent first, mirroring `Seeder::normalize_tier()`'s own map.
 */
const TIER_NEEDLES = [
	'innate',
	'basic',
	'intermediate',
	'int',
	'advanced',
	'adv',
	'elder',
	'master',
	'ascended',
	'asc',
	'methuselah',
	'meth',
];

/**
 * Whatever a note says beyond its tier word.
 */
export function levelQualifier( note?: string | null ): string | undefined {
	const raw = ( note ?? '' ).trim();
	if ( raw === '' ) {
		return undefined;
	}

	const lower = raw.toLowerCase();
	let at = -1;
	let needleLength = 0;
	for ( const needle of TIER_NEEDLES ) {
		const found = lower.indexOf( needle );
		if ( found !== -1 ) {
			at = found;
			needleLength = needle.length;
			break;
		}
	}
	if ( at === -1 ) {
		return undefined;
	}

	const rest = ( raw.slice( 0, at ) + raw.slice( at + needleLength ) )
		// The abbreviation's own full stop, then whatever separates it from the qualifier.
		.replace( /^[\s.,;:-]+/, '' )
		.replace( /[\s.,;:-]+$/, '' )
		.trim();

	// `Basic (Sabbat)` - the parentheses are the note's punctuation, not part of the name.
	const unwrapped = rest.replace( /^\((.*)\)$/, '$1' ).trim();
	return unwrapped === '' ? undefined : unwrapped;
}

/**
 * True when a family's levels do not all agree on their qualifier.
 */
export function familyHasSeam( power?: TieredPower ): boolean {
	if ( ! power ) {
		return false;
	}
	// Every container, not the ladder alone (pre-deploy).
	const seen = new Set< string >();
	for ( const level of allLevels( power ) ) {
		seen.add( levelQualifier( level.note ) ?? '' );
		if ( seen.size > 1 ) {
			return true;
		}
	}
	return false;
}

/**
 * The qualifier to display beside one level, or undefined when there is nothing useful to say.
 */
export function seamQualifier(
	power: TieredPower | undefined,
	level: PowerLevel | undefined
): string | undefined {
	if ( ! level || ! familyHasSeam( power ) ) {
		return undefined;
	}
	return levelQualifier( level.note );
}
