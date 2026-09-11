<?php

namespace BeyondElysium\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * UUIDv7 generation and validation (RFC 9562).
 *
 * Layout, 128 bits total:
 *   48 bits  unix_ts_ms   millisecond timestamp, big-endian
 *    4 bits  version      always 0111 (7)
 *   12 bits  rand_a       random
 *    2 bits  variant      always 10
 *   62 bits  rand_b       random
 *
 * Time-ordered, so a UUIDv7 sorts by creation time as a string. Used as the
 * permanent cross-installation identity for characters.
 */
class Uuid {

	/**
	 * Generates a UUIDv7 string.
	 * Combines a millisecond timestamp with random bits per RFC 9562 and
	 * formats the result as a canonical 36-character UUID.
	 *
	 * @return string 36-character canonical UUID.
	 */
	public static function v7(): string {
		$ms = self::timestamp_ms();

		// 48-bit timestamp as 12 hex characters.
		$ts_hex = str_pad( dechex( $ms ), 12, '0', STR_PAD_LEFT );

		// 74 random bits, taken from 10 random bytes; the extra 6 are discarded below.
		$rand = bin2hex( random_bytes( 10 ) );

		// Octets 6-7: version 7 in the high nibble, then 12 bits of rand_a.
		$time_hi_and_version = '7' . substr( $rand, 0, 3 );

		// Octets 8-9: variant 10xx in the two high bits, then 14 bits of rand_b.
		$variant_nibble    = dechex( ( hexdec( $rand[3] ) & 0x3 ) | 0x8 );
		$clock_and_variant = $variant_nibble . substr( $rand, 4, 3 );

		return substr( $ts_hex, 0, 8 ) . '-'
			. substr( $ts_hex, 8, 4 ) . '-'
			. $time_hi_and_version . '-'
			. $clock_and_variant . '-'
			. substr( $rand, 7, 12 );
	}

	/**
	 * Computes the current Unix time in milliseconds.
	 * Splits microtime() into seconds and microseconds and combines them
	 * with integer/string arithmetic rather than floating-point math.
	 *
	 * @return int
	 */
	private static function timestamp_ms(): int {
		list( $micro, $seconds ) = explode( ' ', microtime() );
		return (int) $seconds * 1000 + (int) ( ( (float) $micro ) * 1000 );
	}

	/**
	 * Checks whether a string is a canonical UUID of any version.
	 * Matches the standard 8-4-4-4-12 hex layout with a valid version
	 * nibble (1-8) and a valid variant nibble (8, 9, a, or b).
	 *
	 * @param string $uuid Candidate string.
	 * @return bool
	 */
	public static function is_valid( string $uuid ): bool {
		return (bool) preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$uuid
		);
	}

	/**
	 * Checks whether a string is specifically a UUIDv7.
	 * Matches the same layout as is_valid() but requires the version
	 * nibble to be exactly 7.
	 *
	 * @param string $uuid Candidate string.
	 * @return bool
	 */
	public static function is_v7( string $uuid ): bool {
		return (bool) preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$uuid
		);
	}

	/**
	 * Extracts the creation timestamp encoded in a UUIDv7.
	 * Reads the first 48 bits of the UUID (the timestamp field) and
	 * returns them as milliseconds since the Unix epoch.
	 *
	 * @param string $uuid A UUIDv7 string.
	 * @return int|null Milliseconds since the Unix epoch, or null if not a valid v7.
	 */
	public static function timestamp_of( string $uuid ) {
		if ( ! self::is_v7( $uuid ) ) {
			return null;
		}
		$hex = substr( $uuid, 0, 8 ) . substr( $uuid, 9, 4 );
		return (int) hexdec( $hex );
	}
}
