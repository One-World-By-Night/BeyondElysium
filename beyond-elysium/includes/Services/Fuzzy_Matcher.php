<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Fuzzy string matcher used to resolve import trait names against a target block's item catalog: normalizes strings,
 * measures edit distance and ranks the closest matches for an unmatched name.
 */
class Fuzzy_Matcher {

	/**
	 * Normalizes a string for comparison.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function normalize( string $value ): string {
		$value = strtolower( trim( $value ) );
		// Punctuation separates words.
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value ) ?? $value;
		return trim( $value );
	}

	/**
	 * Determines whether two strings are within an edit-distance threshold of each other.
	 *
	 * @param string $a
	 * @param string $b
	 * @return bool
	 */
	public static function within_threshold( string $a, string $b ): bool {
		$threshold = strlen( $a ) < 10 ? 2 : 3;
		return levenshtein( $a, $b ) <= $threshold;
	}

	/**
	 * Finds up to three candidate names that best match an unmatched trait name.
	 *
	 * @param string   $raw        The unmatched trait name, as imported.
	 * @param string[] $candidates Real item names from the target block's catalog.
	 * @return string[] Up to three candidate names, best match first.
	 */
	public static function suggest( string $raw, array $candidates ): array {
		$needle = self::normalize( $raw );
		if ( $needle === '' || empty( $candidates ) ) {
			return [];
		}

		$bucket = [];
		foreach ( $candidates as $candidate ) {
			$normalized = self::normalize( $candidate );
			// Skip candidates outside the first-letter/length window.
			if ( $normalized === '' ) {
				continue;
			}
			if ( $normalized[0] !== $needle[0] && abs( strlen( $normalized ) - strlen( $needle ) ) > 3 ) {
				continue;
			}
			if ( ! self::within_threshold( $needle, $normalized ) ) {
				continue;
			}
			$bucket[ $candidate ] = [
				'distance' => levenshtein( $needle, $normalized ),
				'percent'  => 0,
			];
			similar_text( $needle, $normalized, $bucket[ $candidate ]['percent'] );
		}

		uasort( $bucket, static function ( $a, $b ) {
			return $a['distance'] <=> $b['distance'] ?: $b['percent'] <=> $a['percent'];
		} );

		return array_slice( array_keys( $bucket ), 0, 3 );
	}
}
