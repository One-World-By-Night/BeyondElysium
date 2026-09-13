<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * A per-issuance verification token (GX-7). Exporting a character or signing
 * a PDF mints a fresh, random token here rather than ever publishing the
 * character's own UUID as the public verification key - a UUIDv7 is partly
 * a timestamp and is published as the permanent, cross-plugin character
 * identifier (`INTEROP-UUID.md`), so it can never be rotated or revoked
 * without breaking that separate contract. Revoking one issuance never
 * touches another.
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-7, §6.2
 */
class Attestation {

	/** Human-typeable alphabet for `short_code`: no 0/O, 1/I/L - nothing a person could misread aloud or by hand. */
	private const SHORT_CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

	/**
	 * Issues a new attestation for a character: a fresh random token and
	 * short code, a stored copy of exactly what is being attested to (never
	 * re-derived live later - that is what makes `still_matches` meaningful),
	 * and the sha256 of the canonicalized document this issuance covers.
	 *
	 * @param object $character A row from `Character::find()`.
	 * @param string $kind      'gex' | 'pdf' | 'transfer'.
	 * @param string $sheet_hash sha256 of the canonicalized export payload this issuance covers.
	 * @param string|null $expires_at 'Y-m-d H:i:s', or null for no expiry.
	 * @return object The newly created row, decoded (see `find()`).
	 */
	public static function issue( object $character, string $kind, string $sheet_hash, ?string $expires_at = null ): object {
		$token      = self::generate_token();
		$short_code = self::generate_unique_short_code();

		$id = Manager::insert( 'character_attestations', [
			'character_uuid' => $character->uuid,
			'character_id'   => $character->id,
			'game_slug'      => $character->owner_slug,
			'token'          => $token,
			'short_code'     => $short_code,
			'kind'           => $kind,
			'sheet_hash'     => $sheet_hash,
			'attested'       => wp_json_encode( [
				'name'        => $character->name,
				'stack'       => $character->stack_slug,
				'status'      => $character->status,
				'xp_earned'   => (int) $character->xp_earned,
				'xp_unspent'  => (int) $character->xp_unspent,
				'sheet_hash'  => $sheet_hash,
			] ),
			'issued_at'      => current_time( 'mysql', true ),
			'issued_by'      => get_current_user_id(),
			'expires_at'     => $expires_at,
		] );

		$row = self::find( (int) $id );
		if ( $row === null ) {
			throw new \RuntimeException( 'Attestation::issue() failed to insert a row.' );
		}
		return $row;
	}

	/**
	 * Revokes an attestation by its numeric id. Idempotent - revoking an
	 * already-revoked row simply leaves its existing `revoked_at` alone.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function revoke( int $id ): bool {
		global $wpdb;
		$table = Manager::table( 'character_attestations' );
		return (bool) $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL",
			current_time( 'mysql', true ),
			$id
		) );
	}

	/**
	 * Resolves a human-typed short code to its attestation row, recording
	 * the lookup (`check_count`/`last_checked_at`) regardless of outcome -
	 * an issuing Storyteller seeing a code being probed is itself a useful
	 * signal (§6.3). Returns null for a code that simply does not exist;
	 * callers distinguish revoked/expired from "never existed" themselves,
	 * since only the caller knows which of those must 404 versus 200.
	 *
	 * @param string $short_code
	 * @return object|null
	 */
	public static function resolve( string $short_code ): ?object {
		global $wpdb;
		$table = Manager::table( 'character_attestations' );
		$row   = Manager::get_row( "SELECT * FROM {$table} WHERE short_code = %s", strtoupper( trim( $short_code ) ) );
		if ( $row === null ) {
			return null;
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET check_count = check_count + 1, last_checked_at = %s WHERE id = %d",
			current_time( 'mysql', true ),
			$row->id
		) );

		return self::decode( $row );
	}

	/**
	 * Looks up an attestation by its numeric id, decoded. Used by callers
	 * that already have the id (e.g. right after `issue()`), never by the
	 * public verification endpoint, which only ever receives a short code.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		$table = Manager::table( 'character_attestations' );
		$row   = Manager::get_row( "SELECT * FROM {$table} WHERE id = %d", $id );
		return $row === null ? null : self::decode( $row );
	}

	/**
	 * Marks every attestation past its own `expires_at` as revoked, so a
	 * later `resolve()` treats it the same way as an explicit revocation.
	 * Not yet wired to a cron - no caller needs scheduled sweeping until
	 * GX-8/9's transfer state machine depends on `pending` attestations
	 * actually expiring.
	 *
	 * @return int Number of rows swept.
	 */
	public static function sweep_expired(): int {
		global $wpdb;
		$table = Manager::table( 'character_attestations' );
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET revoked_at = %s WHERE revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at < %s",
			current_time( 'mysql', true ),
			current_time( 'mysql', true )
		) );
	}

	/**
	 * @param object $row Raw row from the database.
	 * @return object The same row with `attested` JSON-decoded to an array.
	 */
	private static function decode( object $row ): object {
		$row->attested = json_decode( (string) $row->attested, true ) ?: [];
		return $row;
	}

	/**
	 * @return string 256-bit random token, base64url-encoded (43 characters, no padding).
	 */
	private static function generate_token(): string {
		$bytes = random_bytes( 32 );
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * @return string A human-typeable code, e.g. "K3F7-QM2P", drawn from an
	 *                alphabet with every visually-ambiguous character removed.
	 */
	private static function generate_short_code(): string {
		$alphabet = self::SHORT_CODE_ALPHABET;
		$max      = strlen( $alphabet ) - 1;
		$chars    = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$chars .= $alphabet[ random_int( 0, $max ) ];
		}
		return substr( $chars, 0, 4 ) . '-' . substr( $chars, 4, 4 );
	}

	/**
	 * Regenerates on the vanishingly unlikely event of a collision against
	 * the table's own UNIQUE KEY, rather than trusting randomness alone.
	 *
	 * @return string
	 */
	private static function generate_unique_short_code(): string {
		$table = Manager::table( 'character_attestations' );
		do {
			$code = self::generate_short_code();
			$exists = Manager::get_row( "SELECT id FROM {$table} WHERE short_code = %s", $code );
		} while ( $exists !== null );
		return $code;
	}
}
