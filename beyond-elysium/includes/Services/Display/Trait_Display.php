<?php

namespace BeyondElysium\Services\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a single trait's name, total, and note into one of eleven display-mode
 * strings (dot ratings, x-multipliers, cost annotations, and so on) that a trait
 * list's `display` setting selects per trait. Ported from the TypeScript
 * `displayTrait()` used by the on-screen character sheet so the signed PDF export
 * renders every trait identically instead of re-deriving these formatting rules a
 * second time - see tests/unit/Display/TraitDisplayParityTest.php for the
 * shared-fixture proof that both languages agree.
 *
 * Pure functions with no WordPress calls and no database access, exactly like
 * Layout_Generator.
 *
 * @see src/lib/displayTrait.ts
 * @see BE_PROCESS/signed-pdf-design.md SP-3
 */
class Trait_Display {

	/**
	 * Renders a trait's name, total, and note as a display string, with the exact
	 * format determined by `$mode`: some modes show a multiplier count, some render
	 * dots, some fold the note into parentheses, and some drop the name entirely. An
	 * unrecognized mode falls back to the bare trait name.
	 *
	 * @param object $trait Decoded trait with a `name` string and optional `total`
	 *                      (int|float|string) and `note` (string) properties.
	 * @param string $mode  One of: simple, multiplier, multiplier_dot, dot, cost,
	 *                      note_only, cost_only, dot_separate, simple_dots,
	 *                      simple_number, simple_note.
	 * @param string $dot   Glyph used by dot-rendering modes, defaulting to a bullet.
	 * @return string
	 */
	public static function display_trait( object $trait, string $mode, string $dot = '•' ): string {
		$total = self::parse_total( $trait->total ?? null );
		$note  = $trait->note ?? '';

		switch ( $mode ) {
			case 'simple':
				// Note suppressed — one of only two modes that drop it.
				return $trait->name;

			case 'multiplier':
				// Hides x1. Every other numeric total, including 0, shows.
				$out = $trait->name;
				if ( $total !== 1 ) {
					$out .= " x{$total}";
				}
				return $note !== '' ? "{$out} ({$note})" : $out;

			case 'multiplier_dot':
				// Unlike 'multiplier', the x-count always shows, even at x1.
				$out = "{$trait->name} x{$total}";
				$d   = self::dots( $total, $dot );
				if ( $d !== '' ) {
					$out .= " {$d}";
				}
				return $note !== '' ? "{$out} ({$note})" : $out;

			case 'dot':
				$out = $trait->name;
				$d   = self::dots( $total, $dot );
				if ( $d !== '' ) {
					$out .= " {$d}";
				}
				return $note !== '' ? "{$out} ({$note})" : $out;

			case 'cost':
				// Note lives inside the parens, comma-joined with the total.
				$inner = $note !== '' ? "{$total}, {$note}" : (string) $total;
				return "{$trait->name} ({$inner})";

			case 'note_only':
				return $note !== '' ? "{$trait->name} ({$note})" : $trait->name;

			case 'cost_only':
				// Note excluded, unlike 'cost'.
				return "{$trait->name} ({$total})";

			case 'dot_separate':
				// The note is folded into the repeated label rather than appended
				// once; count is at least 1.
				$label = $note !== '' ? "{$trait->name} ({$note})" : $trait->name;
				$count = $total < 2 ? 1 : $total;
				return implode( $dot, array_fill( 0, $count, $label ) );

			case 'simple_dots':
				// The name is dropped entirely; only dots are shown.
				return self::dots( $total, $dot );

			case 'simple_number':
				return (string) $total;

			case 'simple_note':
				return $note;

			default:
				return $trait->name;
		}
	}

	/**
	 * Parses a leading integer from the start of a trait's total value, stopping at
	 * the first non-digit character. A numeric value passes through directly
	 * (truncated toward zero); a string that doesn't begin with a number (e.g.
	 * "borrowed" rather than "3 (borrowed)") parses to 0 - the same leading-integer
	 * behavior as Grapevine's VB6 `Val()`.
	 *
	 * Public, unlike its TypeScript twin's module-private `parseTotal` - the parity
	 * test asserts this exact rule directly, per signed-pdf-design.md SP-3's call-out
	 * of this specific case.
	 *
	 * @param int|float|string|null $total
	 * @return int
	 */
	public static function parse_total( int|float|string|null $total ): int {
		if ( is_int( $total ) || is_float( $total ) ) {
			return is_finite( (float) $total ) ? (int) $total : 0;
		}
		if ( is_string( $total ) ) {
			if ( preg_match( '/^-?\d+/', trim( $total ), $matches ) ) {
				return (int) $matches[0];
			}
			return 0;
		}
		return 0;
	}

	/**
	 * Repeats the dot glyph `$count` times, or returns '' for a zero or negative
	 * count - `str_repeat()` rejects a negative count outright, unlike JS `repeat()`.
	 */
	private static function dots( int $count, string $dot ): string {
		return str_repeat( $dot, max( 0, $count ) );
	}
}
