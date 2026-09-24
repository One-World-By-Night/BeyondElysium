<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a `tiered_power` family's levels out of its three containers: `levels` (the declared ladder), `elder` (picks,
 * keyed by rank) and `overflow` (ladder-rank levels beyond the ladder).
 */
class Power_Levels {

	/**
	 * Every level in a family, ladder then picks then overflow.
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
	 * The declared ladder alone.
	 *
	 * @param object|array $power
	 * @return array<int,object>
	 */
	public static function ladder( $power ): array {
		return array_map(
			static fn( $level ): object => (object) $level,
			array_values( self::container( $power, 'levels' ) )
		);
	}

	/**
	 * One container off a family, accepting either the object shape a decoded definition carries or the array shape the
	 * seeder builds.
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
