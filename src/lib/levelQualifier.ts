/**
 * The discriminator hiding inside a power level's free-text `note` (1.2.9 U5, D67).
 *
 * 68 `tiered_power` families are two complete ladders concatenated into one.
 * `vampire-blood-magic :: Path of Blood's Curse` is the clearest: five Sabbat powers and
 * five Tremere ones, sharing a family, sharing tier labels - two entries at `basic`, two
 * more at `basic`, and nothing on screen to tell a player which tradition they are
 * looking at. The catalog has known all along; the seam simply never reached the UI.
 *
 * The `note` field carries it, but never on its own: measured across the real catalog,
 * every note is a tier word optionally followed by a qualifier - `basic`,
 * `Basic (Sabbat)`, `int. ritual`, `adv., wyld west`. The tier half is already shown
 * beside it, so printing the whole note would repeat the tier 216 times to surface
 * `(Setite)` once. **Only the remainder is worth showing**, and only where it actually
 * distinguishes something.
 *
 * This resolves D67 for nobody. It stops the merge being invisible while 68 families wait
 * for their rulings in `1.3.1` - a player can at least see that two things named the same
 * tier are not the same ladder.
 */
import type { PowerLevel, TieredPower } from '../types';
import { allLevels } from './powerLevels';

/**
 * The tier vocabulary, longest-matching-intent first, mirroring
 * `Seeder::normalize_tier()`'s own map - the function that derived `tier` from this same
 * note in the first place. `int`/`adv`/`asc`/`meth` are the real abbreviations in the
 * data, not conveniences invented here.
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
 * Whatever a note says beyond its tier word - `Sabbat`, `ritual`, `dark ages` - or
 * undefined when the note is nothing but a tier, which is the common case.
 *
 * A note carrying no tier word at all *is* the tier (`Seeder::normalize_tier()` falls
 * back to the raw note), so it yields no qualifier. That also keeps D72's corrupted
 * `mortal-numina` tier values - combo-Discipline names leaked into the field - from being
 * surfaced as though they meant something.
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
 * True when a family's levels do not all agree on their qualifier - the signature of a
 * concatenated ladder, and the only case where showing one is worth the noise. A family
 * where every level reads `(Setite)` has no seam to point at; one mixing `(Sabbat)` with
 * `(Tremere)`, or a base ladder with a `dark ages` variant, does.
 *
 * "No qualifier" counts as a value of its own here, deliberately: a family that is half
 * unqualified and half `dark ages` is exactly as merged as one that is half Sabbat.
 */
export function familyHasSeam( power?: TieredPower ): boolean {
	if ( ! power ) {
		return false;
	}
	// Every container, not the ladder alone (1.2.10 pre-deploy, 2026-09-22). D67's seam is
	// *between* two concatenated ladders, and after the split the second ladder is filed in
	// `overflow` - so reading `levels` alone saw one tradition and quietly dropped the
	// "(Sabbat)"/"(Tremere)" qualifier on exactly the families U5 exists to flag.
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
 * The qualifier to display beside one level, or undefined when there is nothing useful to
 * say - either the level carries none, or its family is internally consistent and naming
 * the qualifier would only repeat itself down the list.
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
