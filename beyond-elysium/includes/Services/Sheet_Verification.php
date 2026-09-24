<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a player-sent Grapevine file against the Beyond Elysium verification code it may carry in its `id` field.
 */
class Sheet_Verification {

	/**
	 * How long a resolved check is cached, keyed by code + file hash.
	 */
	private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Extracts a verification code from a parsed character's `id` field, when it is a real Beyond Elysium verification
	 * URL.
	 *
	 * @param array<string,mixed> $character A parsed GEX character record.
	 * @return array{base:string,code:string}|null
	 */
	public static function code_from( array $character ): ?array {
		$id = (string) ( $character['id'] ?? '' );
		if ( $id === '' ) {
			return null;
		}

		if ( ! preg_match( '~^(https?://[^\s?#]+?)/be-verify/\?code=([A-Za-z0-9]{4}-?[A-Za-z0-9]{4})$~', $id, $m ) ) {
			return null;
		}

		return [ 'base' => $m[1], 'code' => $m[2] ];
	}

	/**
	 * Resolves a file's verification code against its issuing site and compares the file's own bytes against what that
	 * site attested to.
	 *
	 * @param string               $xml       The raw uploaded document text.
	 * @param array<string,mixed>  $character The parsed character this document holds.
	 * @return array<string,mixed>
	 */
	public static function check( string $xml, array $character ): array {
		$found = self::code_from( $character );
		if ( $found === null ) {
			return [ 'status' => 'none' ];
		}

		$cache_key = 'be_sheet_verify_' . sha1( $found['code'] . hash( 'sha256', $xml ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = self::resolve( $found['base'], $found['code'], $xml, $character );
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * @param string               $base
	 * @param string               $code
	 * @param string               $xml
	 * @param array<string,mixed>  $character
	 * @return array<string,mixed>
	 */
	private static function resolve( string $base, string $code, string $xml, array $character ): array {
		// Fetches with wp_safe_remote_get().
		$response = wp_safe_remote_get(
			untrailingslashit( $base ) . '/wp-json/be/v1/verify/' . rawurlencode( $code ),
			[ 'timeout' => 10, 'redirection' => 2 ]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'status' => 'unreachable', 'base' => $base, 'code' => $code ];
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status === 404 ) {
			return [ 'status' => 'unknown', 'base' => $base, 'code' => $code ];
		}
		if ( ! is_array( $data ) || $status !== 200 ) {
			return [ 'status' => 'unreachable', 'base' => $base, 'code' => $code ];
		}
		if ( ! hash_equals( untrailingslashit( (string) ( $data['issuer']['site'] ?? '' ) ), untrailingslashit( $base ) ) ) {
			return [ 'status' => 'issuer_mismatch', 'base' => $base, 'code' => $code ];
		}
		if ( ! empty( $data['revoked'] ) ) {
			return [
				'status'    => 'revoked',
				'base'      => $base,
				'code'      => $code,
				'issuer'    => $data['issuer'],
				'issued_at' => $data['issued_at'] ?? null,
			];
		}

		$attested       = (array) ( $data['attested'] ?? [] );
		$has_document   = array_key_exists( 'document_hash', $attested );
		$expected_hash  = (string) ( $has_document ? $attested['document_hash'] : ( $attested['sheet_hash'] ?? '' ) );
		$actual_hash    = hash( 'sha256', Character_Exporter::canonicalize_transfer_payload( $xml ) );
		$hashes_match   = $expected_hash !== '' && hash_equals( $expected_hash, $actual_hash );

		// 'race' is the parsed character's own field name for its BE stack_slug (e.g. 'vampire').
		$file = [
			'name'       => (string) ( $character['name'] ?? '' ),
			'stack'      => (string) ( $character['race'] ?? '' ),
			'xp_earned'  => (int) round( (float) ( $character['experience']['earned'] ?? 0 ) ),
			'xp_unspent' => (int) round( (float) ( $character['experience']['unspent'] ?? 0 ) ),
		];

		$facts_match = [
			'name'       => $file['name'] === (string) ( $attested['name'] ?? '' ),
			'stack'      => $file['stack'] === (string) ( $attested['stack'] ?? '' ),
			'xp_earned'  => $file['xp_earned'] === (int) ( $attested['xp_earned'] ?? -1 ),
			'xp_unspent' => $file['xp_unspent'] === (int) ( $attested['xp_unspent'] ?? -1 ),
		];

		$still_matches = $data['still_matches'] ?? null;
		$home_changed  = null;
		if ( is_array( $still_matches ) ) {
			$relevant = $has_document
				? $still_matches
				: array_diff_key( $still_matches, [ 'sheet' => true ] );
			$home_changed = in_array( false, $relevant, true );
		}

		return [
			'status'        => $hashes_match ? 'unchanged' : 'changed',
			'base'          => $base,
			'code'          => $code,
			'issuer'        => $data['issuer'],
			'issued_at'     => $data['issued_at'] ?? null,
			'compared_with' => $has_document ? 'document' : 'sheet',
			'attested'      => $attested,
			'file'          => $file,
			'facts_match'   => $facts_match,
			'home_changed'  => $home_changed,
		];
	}
}
