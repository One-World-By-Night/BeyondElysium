<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Services\Short_Code;

defined( 'ABSPATH' ) || exit;

/**
 * A per-issuance verification code for an item (1.1.0 §3.13) - the item sibling of
 * `Attestation` (character verification, GX-7). Printing an Item Card issues one of these,
 * reusing the newest unrevoked code for that same item and holder when its attested name,
 * uses, and expiry still match, rather than minting a fresh one on every print.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.13
 */
class Item_Attestation {

	/**
	 * Issues a new attestation, or reuses the newest unrevoked one for this exact item and
	 * holder if what it attested to still matches. `$holder` is the character currently
	 * holding the item (or null, unheld), and is itself part of what "still matches" means -
	 * a transfer to a new character never reuses the old holder's code.
	 *
	 * @param object      $item      A decoded row from `World_Object::find()`.
	 * @param object|null $holder    A row from `Character::find()`, or null.
	 * @param string      $game_slug
	 * @param string      $chronicle Display name of the chronicle, for the attested snapshot.
	 * @return object The reused or newly created row, decoded.
	 */
	public static function issue_or_reuse( object $item, ?object $holder, string $game_slug, string $chronicle ): object {
		$holder_id = $holder->id ?? null;
		$attested  = self::snapshot( $item, $holder, $chronicle );

		$existing = self::newest_unrevoked_for( (int) $item->id, $holder_id !== null ? (int) $holder_id : null );
		if ( $existing !== null && $existing->attested === $attested ) {
			return $existing;
		}

		return self::issue( $item, $holder, $game_slug, $chronicle, $attested );
	}

	/**
	 * Unconditionally mints a fresh attestation - `issue_or_reuse()` is the usual entry point;
	 * this is exposed separately for callers (and tests) that need a guaranteed-new row.
	 *
	 * @param object                    $item
	 * @param object|null               $holder
	 * @param string                    $game_slug
	 * @param string                    $chronicle
	 * @param array<string,mixed>|null  $attested Precomputed snapshot, or null to derive one.
	 * @return object
	 */
	public static function issue( object $item, ?object $holder, string $game_slug, string $chronicle, ?array $attested = null ): object {
		$token      = Short_Code::generate_token();
		$short_code = Short_Code::generate_unique( [ 'character_attestations', 'item_attestations' ] );

		$id = Manager::insert( 'item_attestations', [
			'game_slug'       => $game_slug,
			'world_object_id' => (int) $item->id,
			'character_id'    => $holder->id ?? null,
			'token'           => $token,
			'short_code'      => $short_code,
			'attested'        => wp_json_encode( $attested ?? self::snapshot( $item, $holder, $chronicle ) ),
			'issued_at'       => current_time( 'mysql', true ),
			'issued_by'       => get_current_user_id(),
		] );

		$row = self::find( (int) $id );
		if ( $row === null ) {
			throw new \RuntimeException( 'Item_Attestation::issue() failed to insert a row.' );
		}
		return $row;
	}

	/**
	 * What is attested to for an item: the facts a printed card is meant to freeze, compared
	 * against a live re-derivation later (`still_matches` in `Verify_Controller`).
	 *
	 * @param object      $item
	 * @param object|null $holder
	 * @param string      $chronicle
	 * @return array{name:string,holder:string|null,chronicle:string,uses_left:int|null,expires_on:string|null}
	 */
	private static function snapshot( object $item, ?object $holder, string $chronicle ): array {
		return [
			'name'       => $item->name,
			'holder'     => $holder->name ?? null,
			'chronicle'  => $chronicle,
			'uses_left'  => isset( $item->properties['uses_left'] ) ? (int) $item->properties['uses_left'] : null,
			'expires_on' => $item->properties['expires_on'] ?? null,
		];
	}

	/**
	 * The most recently issued, not-yet-revoked attestation for this exact item and holder,
	 * or null when none exists.
	 *
	 * @param int      $world_object_id
	 * @param int|null $holder_id
	 * @return object|null
	 */
	private static function newest_unrevoked_for( int $world_object_id, ?int $holder_id ): ?object {
		$table = Manager::table( 'item_attestations' );
		$sql   = "SELECT * FROM {$table} WHERE world_object_id = %d AND revoked_at IS NULL AND "
			. ( $holder_id === null ? 'character_id IS NULL' : 'character_id = %d' )
			. ' ORDER BY issued_at DESC, id DESC LIMIT 1';

		$row = $holder_id === null
			? Manager::get_row( $sql, $world_object_id )
			: Manager::get_row( $sql, $world_object_id, $holder_id );

		return $row === null ? null : self::decode( $row );
	}

	/**
	 * Revokes every unrevoked attestation for one item (1.1.0 §3.13) - delete's own cascade,
	 * and the `POST .../revoke-cards` route. A transfer deliberately does NOT call this: an
	 * old card stays live but reports a holder mismatch through `still_matches`, which would
	 * have nothing left to report if the code were revoked out from under it instead.
	 *
	 * @param int $world_object_id
	 * @return int Number of rows revoked.
	 */
	public static function revoke_for_object( int $world_object_id ): int {
		global $wpdb;
		$table = Manager::table( 'item_attestations' );
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET revoked_at = %s WHERE world_object_id = %d AND revoked_at IS NULL",
			current_time( 'mysql', true ),
			$world_object_id
		) );
	}

	/**
	 * Resolves a human-typed short code to its attestation row, recording the lookup
	 * (`check_count`/`last_checked_at`) regardless of outcome - same discipline as
	 * `Attestation::resolve()`, which this mirrors exactly.
	 *
	 * @param string $short_code
	 * @return object|null
	 */
	public static function resolve( string $short_code ): ?object {
		global $wpdb;
		$table = Manager::table( 'item_attestations' );
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
		$table = Manager::table( 'item_attestations' );
		$row   = Manager::get_row( "SELECT * FROM {$table} WHERE id = %d", $id );
		return $row === null ? null : self::decode( $row );
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
