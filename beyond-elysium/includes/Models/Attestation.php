<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Services\Short_Code;

defined( 'ABSPATH' ) || exit;

/**
 * A per-issuance verification token.
 */
class Attestation {

	/**
	 * Issues a new attestation for a character: a fresh random token and short code, a stored copy of what is being
	 * attested to, and the sha256 of the canonicalized document this issuance covers.
	 *
	 * @param object $character A row from `Character::find()`.
	 * @param string $kind      'gex' | 'pdf' | 'transfer' | 'visit_item' | 'visit_pairing'.
	 * @param string $sheet_hash sha256 of the canonicalized, unredacted export payload this
	 *                           issuance covers - what "has the character changed" compares
	 *                           against (`still_matches()`).
	 * @param string|null $expires_at 'Y-m-d H:i:s', or null for no expiry.
	 * @param string|null $document_hash sha256 of the canonicalized document actually handed
	 *                     over (redacted when the export itself was, unredacted otherwise) -
	 *                     what a receiving chronicle's own copy of the file is compared
	 *                     against (`Sheet_Verification::check()`). Defaults to `$sheet_hash` when omitted.
	 * @return object The newly created row, decoded (see `find()`).
	 */
	public static function issue( object $character, string $kind, string $sheet_hash, ?string $expires_at = null, ?string $document_hash = null ): object {
		$token      = Short_Code::generate_token();
		$short_code = Short_Code::generate_unique( [ 'character_attestations', 'item_attestations' ] );
		$document_hash = $document_hash ?? $sheet_hash;

		$id = Manager::insert( 'character_attestations', [
			'character_uuid' => $character->uuid,
			'character_id'   => $character->id,
			'game_slug'      => $character->owner_slug,
			'token'          => $token,
			'short_code'     => $short_code,
			'kind'           => $kind,
			'sheet_hash'     => $sheet_hash,
			'attested'       => wp_json_encode( [
				'name'          => $character->name,
				'stack'         => $character->stack_slug,
				'status'        => $character->status,
				'xp_earned'     => (int) $character->xp_earned,
				'xp_unspent'    => (int) $character->xp_unspent,
				'sheet_hash'    => $sheet_hash,
				'document_hash' => $document_hash,
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
	 * Issues a short-lived attestation binding one cross-site visit call to the character it concerns: the proof a
	 * visit's own accept/end notification carries so the receiving site can call back and confirm who really sent it.
	 *
	 * @param object               $character A row from `Character::find()`.
	 * @param string               $hash      The call's own binding hash (what the receiving site's verify callback
	 *                                         compares against to confirm this code was issued for this exact call).
	 * @param array<string,mixed>  $snapshot  Freeform context for the verify response's `attested` field.
	 * @return object The newly created row, decoded (see `find()`).
	 */
	public static function issue_visit_item( object $character, string $hash, array $snapshot ): object {
		$token      = Short_Code::generate_token();
		$short_code = Short_Code::generate_unique( [ 'character_attestations', 'item_attestations' ] );

		$id = Manager::insert( 'character_attestations', [
			'character_uuid' => $character->uuid,
			'character_id'   => $character->id,
			'game_slug'      => $character->owner_slug,
			'token'          => $token,
			'short_code'     => $short_code,
			'kind'           => 'visit_item',
			'sheet_hash'     => $hash,
			'attested'       => wp_json_encode( array_merge( $snapshot, [ 'sheet_hash' => $hash ] ) ),
			'issued_at'      => current_time( 'mysql', true ),
			'issued_by'      => get_current_user_id(),
			'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS ),
		] );

		$row = self::find( (int) $id );
		if ( $row === null ) {
			throw new \RuntimeException( 'Attestation::issue_visit_item() failed to insert a row.' );
		}
		return $row;
	}

	/**
	 * Issues a short-lived attestation binding a host's own request to pair a player-submitted character with its
	 * real home - the proof the request carries so home can call back and confirm the host really sent it.
	 *
	 * @param object               $character A row from `Character::find()`.
	 * @param string               $hash      The call's own binding hash.
	 * @param array<string,mixed>  $snapshot  Freeform context for the verify response's `attested` field.
	 * @return object The newly created row, decoded (see `find()`).
	 */
	public static function issue_visit_pairing( object $character, string $hash, array $snapshot ): object {
		$token      = Short_Code::generate_token();
		$short_code = Short_Code::generate_unique( [ 'character_attestations', 'item_attestations' ] );

		$id = Manager::insert( 'character_attestations', [
			'character_uuid' => $character->uuid,
			'character_id'   => $character->id,
			'game_slug'      => $character->owner_slug,
			'token'          => $token,
			'short_code'     => $short_code,
			'kind'           => 'visit_pairing',
			'sheet_hash'     => $hash,
			'attested'       => wp_json_encode( array_merge( $snapshot, [ 'sheet_hash' => $hash ] ) ),
			'issued_at'      => current_time( 'mysql', true ),
			'issued_by'      => get_current_user_id(),
			'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS ),
		] );

		$row = self::find( (int) $id );
		if ( $row === null ) {
			throw new \RuntimeException( 'Attestation::issue_visit_pairing() failed to insert a row.' );
		}
		return $row;
	}

	/**
	 * Revokes an attestation by its numeric id.
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
	 * Resolves a human-typed short code to its attestation row, recording the lookup (`check_count`/`last_checked_at`)
	 * regardless of outcome.
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
	 * Looks up an attestation by its numeric id, decoded.
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
	 * Marks every attestation past its own `expires_at` as revoked.
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
}
