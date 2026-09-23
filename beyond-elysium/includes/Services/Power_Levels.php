<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a `tiered_power` family's levels out of the three containers the 1.2.10 schema
 * splits them into - `levels` (the declared ladder), `elder` (picks, keyed by rank) and
 * `overflow` (ladder-rank levels beyond the ladder).
 *
 * **Why this exists.** The split is the D68 fix and it must stay a split: the stepper reads
 * the ladder, the pick list reads the picks, and they cannot be confused because they are
 * not the same array. But several consumers legitimately want *every named power* regardless
 * of where it sits - the translator has to offer every name for translation, and the import
 * matcher has to resolve a raw trait against every name the catalog knows. Before this
 * helper existed they read `$power->levels` directly, and the moment that array narrowed to
 * the ladder they silently lost **503 picks and 1,976 overflow levels** across the real
 * catalog: translation coverage fell from 233 to 212 on one block and the importer started
 * returning 409 on traits it used to resolve.
 *
 * So: `all()` for "every name this family has", `ladder()` for "the rating". A consumer
 * reading `$power->levels` directly is a consumer that has picked one and meant the other.
 */
class Power_Levels {

	/**
	 * Every level in a family, ladder then picks then overflow.
	 *
	 * Rank keys inside `elder` are deliberately **flattened here and only here** - a caller
	 * that needs to know which rank a pick belongs to must read `elder` itself, because
	 * merging those two depths is what caused D68 in the first place. This function is for
	 * callers that genuinely do not care.
	 *
	 * @param object|array $power
	 * @return array<int,object>
	 */
	public static function all( $power ): array {
		$levels = self::ladder( $power );

		foreach ( self::container( $power, 'elder' ) as $picks ) {
			foreach ( (array) $picks as $pick ) {
				$levels[] = (object) $pick;
			}
		}
		foreach ( self::container( $power, 'overflow' ) as $level ) {
			$levels[] = (object) $level;
		}

		return $levels;
	}

	/**
	 * The declared ladder alone - the rungs a rating counts. Never picks, never overflow.
	 *
	 * @param object|array $power
	 * @return array<int,object>
	 */
	public static function ladder( $power ): array {
		return array_map(
			// Objects out, always: every consumer reads a level with `->`, and the seeder's
			// own build-time arrays are the only place the array shape exists.
			static fn( $level ): object => (object) $level,
			array_values( self::container( $power, 'levels' ) )
		);
	}

	/**
	 * One container off a family, accepting either the object shape a decoded definition
	 * carries or the array shape the seeder builds.
	 *
	 * @param object|array $power
	 * @param string       $key
	 * @return array<int|string,mixed>
	 */
	private static function container( $power, string $key ): array {
		if ( is_object( $power ) ) {
			return (array) ( $power->{$key} ?? [] );
		}
		return (array) ( $power[ $key ] ?? [] );
	}
}
