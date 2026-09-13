<?php

namespace BeyondElysium\Services\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a resource pool's permanent/temporary values as the sheet's fixed-glyph
 * string, transcribed from `GameClass.DisplayTemper` (VB6). Distinct from the output
 * engine's `MakeTally`, which renders the same kind of pool for template output with
 * configurable glyphs and a divider instead - GV-SOURCEMAP.md "DisplayTemper" /
 * "Permanent/temporary tally (MakeTally)".
 *
 * Ported from `src/lib/displayTemper.ts` as an exact behavioral twin: the signed-PDF
 * export replaces printing, so the PDF *is* the sheet and must reproduce this byte-for-
 * byte, including the temporary-above-permanent overflow case. Both sides read the same
 * fixture; see tests/unit/Display/TemperDisplayParityTest.php.
 *
 * The glyph set is fixed for now, but kept in named constants rather than inlined so a
 * later pass can give `MakeTally`'s own configurable-glyph rendering a parameter here
 * without restructuring the class.
 *
 * @see BE_PROCESS/signed-pdf-design.md
 */
class Temper_Display {

	private const FILLED   = 'o';
	private const OVERFLOW = "\u{00F5}"; // õ - temporary above permanent.
	private const SPENT    = "\u{00F8}"; // ø - permanent currently spent.

	/** Capital form each glyph collapses into once a run of five appears. */
	private const CAPITALS = [
		self::FILLED   => 'O',
		self::OVERFLOW => "\u{00D5}", // Õ
		self::SPENT    => "\u{00D8}", // Ø
	];

	/** A run of this many identical glyphs collapses into one capital glyph. */
	private const RUN_LENGTH = 5;

	/** The string is compressed until it is at or under this many characters. */
	private const COMPRESS_THRESHOLD = 10;

	/**
	 * Renders a resource pool as a glyph string: filled glyphs up to the lesser of
	 * permanent and temporary, then spent or overflow glyphs for the remainder. A
	 * temporary value above permanent renders as overflow past the permanent track
	 * rather than being clamped, and the result is compressed to stay at or near
	 * `COMPRESS_THRESHOLD` characters.
	 *
	 * @param int $permanent
	 * @param int $temporary
	 * @return string
	 */
	public static function display( int $permanent, int $temporary ): string {
		if ( $temporary >= $permanent ) {
			$str = str_repeat( self::FILLED, max( 0, $permanent ) )
				. str_repeat( self::OVERFLOW, max( 0, $temporary - $permanent ) );
		} else {
			$str = str_repeat( self::FILLED, max( 0, $temporary ) )
				. str_repeat( self::SPENT, max( 0, $permanent - $temporary ) );
		}

		// Collapses spent runs first, then filled, then overflow, until no run of five remains.
		while ( mb_strlen( $str, 'UTF-8' ) > self::COMPRESS_THRESHOLD ) {
			$next = self::collapse_once( $str, self::SPENT, true )
				?? self::collapse_once( $str, self::FILLED, false )
				?? self::collapse_once( $str, self::OVERFLOW, true );

			if ( $next === null ) {
				break;
			}
			$str = $next;
		}

		return $str;
	}

	/**
	 * Collapses one run of five identical glyphs into its capital form, or returns
	 * null when no such run exists. Searches from the right (the rightmost run) when
	 * `$from_right` is true, otherwise from the left (the leftmost run).
	 *
	 * Offsets are character-based (`mb_*`, not byte-based) throughout because the
	 * overflow/spent glyphs are multi-byte in UTF-8 - a byte-based `substr()` would
	 * slice mid-character the moment one of those glyphs is involved.
	 *
	 * @param string $str
	 * @param string $glyph
	 * @param bool   $from_right
	 * @return string|null
	 */
	private static function collapse_once( string $str, string $glyph, bool $from_right ): ?string {
		$run = str_repeat( $glyph, self::RUN_LENGTH );
		$idx = $from_right
			? mb_strrpos( $str, $run, 0, 'UTF-8' )
			: mb_strpos( $str, $run, 0, 'UTF-8' );

		if ( $idx === false ) {
			return null;
		}

		return mb_substr( $str, 0, $idx, 'UTF-8' )
			. self::CAPITALS[ $glyph ]
			. mb_substr( $str, $idx + self::RUN_LENGTH, null, 'UTF-8' );
	}
}
