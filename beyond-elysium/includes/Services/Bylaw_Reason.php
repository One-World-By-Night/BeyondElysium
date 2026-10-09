<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Formats one bylaw rule's reason text for the axis (PC or NPC) a change needs it on. Pure - no database, no
 * network.
 */
class Bylaw_Reason {

	/**
	 * `{TIER}{ — AUTHORITY}{ — SCOPE} [OWBN Character Bylaws {path}, clause {clause_id}]`, or null when this axis's
	 * own tier is unregulated (nothing to require) or absent.
	 *
	 * @param array<string,mixed> $rule A decoded bylaw rule: `clause_id`, `path`, `pc`, `npc` always present; `subject`
	 *                                  and `coordinators` may be absent.
	 * @param string      $axis         'pc' or 'npc'.
	 * @param string|null $matched_name The catalog entry name the rule attached to, for the SCOPE segment.
	 * @return string|null
	 */
	public static function format( array $rule, string $axis, ?string $matched_name = null ): ?string {
		$tier = $rule[ $axis ] ?? null;
		if ( ! is_string( $tier ) || $tier === '' || self::is_unregulated( $tier ) ) {
			return null;
		}

		$segments = [ $tier ];

		$coordinators = is_array( $rule['coordinators'] ?? null ) ? $rule['coordinators'] : [];
		$authority    = self::authority( $tier, $coordinators );
		if ( $authority !== null ) {
			$segments[] = $authority;
		}

		$subject = is_string( $rule['subject'] ?? null ) ? $rule['subject'] : '';
		if ( $subject !== '' && ( $matched_name === null || self::normalize( $subject ) !== self::normalize( $matched_name ) ) ) {
			$segments[] = 'applies to: "' . self::truncate( $subject, 160 ) . '"';
		}

		$text = implode( ' — ', $segments );
		return $text . ' [OWBN Character Bylaws ' . $rule['path'] . ', clause ' . $rule['clause_id'] . ']';
	}

	/**
	 * Every rule attaching to the same entry, one reason per line, each clause contributing on the given axis - the
	 * ones with nothing to say on this axis contribute no line at all. Sorted by clause path. Null when none do.
	 *
	 * @param array<int,array<string,mixed>> $rules
	 */
	public static function format_all( array $rules, string $axis, ?string $matched_name = null ): ?string {
		$lines = [];
		foreach ( $rules as $rule ) {
			$line = self::format( $rule, $axis, $matched_name );
			if ( $line !== null ) {
				$lines[ (string) $rule['path'] ] = $line;
			}
		}
		if ( $lines === [] ) {
			return null;
		}
		ksort( $lines, SORT_STRING );
		return implode( "\n", $lines );
	}

	/**
	 * Whether a tier string means no reason is needed on this axis.
	 */
	private static function is_unregulated( string $tier ): bool {
		return in_array( strtolower( trim( $tier ) ), [ 'unregulated', 'not applicable', 'n/a' ], true );
	}

	/**
	 * The AUTHORITY segment: every coordinator labeled and joined with " and ", or an OWBN Council prefix for a
	 * vote tier. Null when there is nothing to name.
	 *
	 * @param string[] $coordinators
	 */
	private static function authority( string $tier, array $coordinators ): ?string {
		$labels = array_map( [ self::class, 'label_coordinator' ], $coordinators );

		if ( stripos( $tier, 'vote' ) !== false ) {
			return $labels === [] ? 'OWBN Council' : 'OWBN Council, with ' . implode( ' and ', $labels );
		}

		return $labels === [] ? null : implode( ' and ', $labels );
	}

	/**
	 * One coordinator name as the AUTHORITY segment reads it: `Varies` reads as a plain note, a name already
	 * carrying the word "Coordinator" passes through, anything else gets it appended.
	 */
	private static function label_coordinator( string $name ): string {
		if ( strcasecmp( $name, 'Varies' ) === 0 ) {
			return 'coordinator varies, see the clause';
		}
		if ( preg_match( '/coordinator/i', $name ) ) {
			return $name;
		}
		return $name . ' Coordinator';
	}

	/**
	 * Case/whitespace-folded, for comparing a rule's subject against the catalog name it attached to.
	 */
	private static function normalize( string $text ): string {
		return strtolower( trim( preg_replace( '/\s+/', ' ', $text ) ?? $text ) );
	}

	/**
	 * Truncated at a word boundary with a trailing ellipsis, never mid-word.
	 */
	private static function truncate( string $text, int $max ): string {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = substr( $text, 0, $max );
		$cut = (string) preg_replace( '/\s+\S*$/', '', $cut );
		return $cut . '…';
	}
}
