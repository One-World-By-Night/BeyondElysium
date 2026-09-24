<?php

namespace BeyondElysium\Services\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a single trait's name, total, and note into one of twelve display-mode strings (dot ratings, x-multipliers,
 * cost annotations, and so on) that a trait list's `display` setting selects per trait.
 *
 * @see src/lib/displayTrait.ts
 */
class Trait_Display {

	/**
	 * Renders a trait's name, total, and note as a display string, with the exact format determined by `$mode`: some
	 * modes show a multiplier count, some render dots, some fold the note into parentheses, and some drop the name
	 * entirely.
	 *
	 * @param object $trait Decoded trait with a `name` string and optional `total`
	 *                      (int|float|string) and `note` (string) properties.
	 * @param string $mode  One of: simple, multiplier, multiplier_dot, dot, cost,
	 *                      note_only, cost_only, dot_separate, simple_dots,
	 *                      simple_number, simple_note, cost_number, cost_xp.
	 * @param string $dot   Glyph used by dot-rendering modes, defaulting to the one dot a
	 *                      resource pool's points use too.
	 * @return string
	 */
	public static function display_trait( object $trait, string $mode, string $dot = Temper_Display::DOT ): string {
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
				$out .= $d !== '' ? " {$d} {$total}" : " {$total}";
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
				// The note is folded into the repeated label.
				$label    = $note !== '' ? "{$trait->name} ({$note})" : $trait->name;
				$count    = $total < 2 ? 1 : $total;
				$repeated = implode( $dot, array_fill( 0, $count, $label ) );
				return "{$repeated} {$total}";

			case 'simple_dots':
				// The name is dropped entirely.
				$d = self::dots( $total, $dot );
				return $d !== '' ? "{$d} {$total}" : (string) $total;

			case 'simple_number':
				return (string) $total;

			case 'simple_note':
				return $note;

			case 'cost_number':
				// A count_is_cost block's stored total is a flat XP cost, drawn as a number instead of dots when the cost-numbers preference is on.
				$out = "{$trait->name} {$total} XP";
				return $note !== '' ? "{$out} ({$note})" : $out;

			case 'cost_xp':
				// A count_is_cost block's default: the price with its unit, e.g. "Draw Fire (12 XP)".
				$priced = "{$total} XP";
				$inner  = $note !== '' ? "{$priced}, {$note}" : $priced;
				return "{$trait->name} ({$inner})";

			default:
				return $trait->name;
		}
	}

	/**
	 * Parses a leading integer from the start of a trait's total value, stopping at the first non-digit character.
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
	 * Repeats the dot glyph `$count` times, or returns '' for a zero or negative count.
	 */
	private static function dots( int $count, string $dot ): string {
		return str_repeat( $dot, max( 0, $count ) );
	}
}
