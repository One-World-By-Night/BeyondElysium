<?php

namespace BeyondElysium\Services\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a resource pool's permanent/temporary values as a row of dots.
 */
class Temper_Display {

	/**
	 * A point held - also every trait rating's dot (`Trait_Display`).
	 */
	public const DOT = "\u{25CF}"; // ●

	private const SPENT    = "\u{25CB}"; // ○ - a permanent point currently spent.
	private const OVERFLOW = "\u{25C9}"; // ◉ - a temporary point above permanent.

	/**
	 * Dots are grouped, space-separated, in runs of this many.
	 */
	private const GROUP = 5;

	/**
	 * Renders a resource pool as a row of dots: filled up to the lesser of permanent and temporary.
	 *
	 * @param int $permanent
	 * @param int $temporary
	 * @return string
	 */
	public static function display( int $permanent, int $temporary ): string {
		if ( $temporary >= $permanent ) {
			$dots = str_repeat( self::DOT, max( 0, $permanent ) )
				. str_repeat( self::OVERFLOW, max( 0, $temporary - $permanent ) );
		} else {
			$dots = str_repeat( self::DOT, max( 0, $temporary ) )
				. str_repeat( self::SPENT, max( 0, $permanent - $temporary ) );
		}

		// Character-based, never byte-based: every dot is three bytes in UTF-8.
		$groups   = array_chunk( mb_str_split( $dots, 1, 'UTF-8' ), self::GROUP );
		$rendered = implode( ' ', array_map( static fn( array $group ): string => implode( '', $group ), $groups ) );

		$suffix = $temporary === $permanent ? (string) $permanent : "{$temporary}/{$permanent}";
		return $rendered === '' ? $suffix : "{$rendered} {$suffix}";
	}
}
