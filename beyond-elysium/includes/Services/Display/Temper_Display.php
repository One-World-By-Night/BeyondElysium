<?php

namespace BeyondElysium\Services\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a resource pool's permanent/temporary values as a row of dots - the same
 * dot a trait's rating uses (1.0.0-review F-016, owner ruling 2026-09-14: every dot
 * the same size). A point held is a filled dot, a permanent point currently spent an
 * empty dot, and a temporary point above permanent a ringed dot; dots group in fives
 * so a large pool stays countable.
 *
 * Transcribed from `GameClass.DisplayTemper` (VB6) - GV-SOURCEMAP.md "DisplayTemper" -
 * which drew letters (`o`, `ø`, `õ`) that Grapevine's own Windows font turned into
 * dots, and collapsed each run of five into a capital. On the web and in the PDF the
 * letters stayed letters and the capitals put two dot sizes side by side, so the
 * glyphs and the grouping changed; the filled/spent/overflow arithmetic did not.
 *
 * An exact behavioral twin of `src/lib/displayTemper.ts`: the signed PDF *is* the
 * sheet and must match it character for character. Both sides read the same fixture;
 * see tests/unit/Display/TemperDisplayParityTest.php.
 *
 * @see BE_PROCESS/signed-pdf-design.md
 */
class Temper_Display {

	/** A point held - also every trait rating's dot (`Trait_Display`). */
	public const DOT = "\u{25CF}"; // ●

	private const SPENT    = "\u{25CB}"; // ○ - a permanent point currently spent.
	private const OVERFLOW = "\u{25C9}"; // ◉ - a temporary point above permanent.

	/** Dots are grouped, space-separated, in runs of this many. */
	private const GROUP = 5;

	/**
	 * Renders a resource pool as a row of dots: filled up to the lesser of permanent
	 * and temporary, then spent or overflow dots for the remainder. A temporary value
	 * above permanent renders as overflow past the permanent track rather than being
	 * clamped.
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
		$groups = array_chunk( mb_str_split( $dots, 1, 'UTF-8' ), self::GROUP );
		return implode( ' ', array_map( static fn( array $group ): string => implode( '', $group ), $groups ) );
	}
}
