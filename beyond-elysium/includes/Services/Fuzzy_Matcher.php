<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Fuzzy string matcher used to resolve import trait names against a target
 * block's item catalog. Normalizes strings for comparison, measures edit
 * distance between a name and each candidate, and ranks the closest matches
 * for an unmatched name.
 *
 * Pure functions with no database access.
 */
class Fuzzy_Matcher {

	/**
	 * Normalizes a string for comparison. Lowercases and trims the value,
	 * then collapses runs of punctuation or whitespace into a single space
	 * so that punctuation acts as a word separator rather than being
	 * deleted outright.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function normalize( string $value ): string {
		$value = strtolower( trim( $value ) );
		// Punctuation separates words rather than being deleted.
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value ) ?? $value;
		return trim( $value );
	}

	/**
	 * Determines whether two strings are within an edit-distance threshold
	 * of each other. Computes the Levenshtein distance between the two
	 * values and compares it against a threshold of 2 characters for
	 * strings under 10 characters long, or 3 characters otherwise.
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
	 * Finds up to three candidate names that best match an unmatched trait
	 * name. Normalizes the input and each candidate, skips candidates that
	 * cannot be within the edit-distance threshold, then scores the rest by
	 * Levenshtein distance with `similar_text` percentage as a tiebreaker.
	 *
	 * Results are sorted by distance ascending and then similarity
	 * percentage descending, and truncated to the top three.
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
