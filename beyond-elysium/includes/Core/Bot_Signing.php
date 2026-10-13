<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The canonical string a bot's signed request is built from, and the HMAC over it.
 */
class Bot_Signing {

	public const VERSION = 'v1';

	/**
	 * The query parameters a signed request carries, as one canonical string: every key but `rest_route`, sorted by
	 * key in byte order, each `rawurlencode`d and joined with `&`.
	 *
	 * @param array<string,mixed> $query
	 * @return string|null Null when a value is an array.
	 */
	public static function canonical_query( array $query ): ?string {
		unset( $query['rest_route'] );
		ksort( $query, SORT_STRING );

		$parts = [];
		foreach ( $query as $key => $value ) {
			if ( is_array( $value ) ) {
				return null;
			}
			$parts[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return implode( '&', $parts );
	}

	/**
	 * The string a signature is computed over.
	 *
	 * @param string $method
	 * @param string $route
	 * @param string $canonical_query
	 * @param string $timestamp
	 * @param string $nonce
	 * @param string $connection_id
	 * @param string $discord_user_id Empty string on a service call.
	 * @param string $body            Empty string when the request carries none.
	 * @return string
	 */
	public static function canonical_string(
		string $method,
		string $route,
		string $canonical_query,
		string $timestamp,
		string $nonce,
		string $connection_id,
		string $discord_user_id,
		string $body
	): string {
		return implode( "\n", [
			self::VERSION,
			strtoupper( $method ),
			$route,
			$canonical_query,
			$timestamp,
			$nonce,
			$connection_id,
			$discord_user_id,
			hash( 'sha256', $body ),
		] );
	}

	/**
	 * The lower-case hex HMAC-SHA256 of a canonical string.
	 *
	 * @param string $canonical_string
	 * @param string $secret_bytes The connection's raw secret.
	 * @return string
	 */
	public static function sign( string $canonical_string, string $secret_bytes ): string {
		return hash_hmac( 'sha256', $canonical_string, $secret_bytes );
	}

	/**
	 * Whether a signature matches, compared in constant time.
	 *
	 * @param string $canonical_string
	 * @param string $secret_bytes
	 * @param string $signature
	 * @return bool
	 */
	public static function verify( string $canonical_string, string $secret_bytes, string $signature ): bool {
		return hash_equals( self::sign( $canonical_string, $secret_bytes ), strtolower( $signature ) );
	}

	/**
	 * Encodes bytes as base64url with no padding.
	 *
	 * @param string $bytes
	 * @return string
	 */
	public static function base64url_encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodes a base64url string, padded or not, back to bytes.
	 *
	 * @param string $encoded
	 * @return string|false
	 */
	public static function base64url_decode( string $encoded ) {
		$padded    = strtr( $encoded, '-_', '+/' );
		$remainder = strlen( $padded ) % 4;
		if ( $remainder > 0 ) {
			$padded .= str_repeat( '=', 4 - $remainder );
		}
		return base64_decode( $padded, true );
	}
}
