<?php

namespace BeyondElysium\Models;

use BeyondElysium\Core\Bot_Signing;
use BeyondElysium\Core\Secret_Box;
use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * A chronicle's connection to a Discord server. One row per game, reused across a disconnect and a later reconnect.
 */
class Bot_Connection {

	/**
	 * Connects a game to a Discord server: reactivates its row if one exists, or creates one. Generates a fresh
	 * secret either way.
	 *
	 * @param int    $game_id
	 * @param string $guild_id
	 * @param string $guild_name
	 * @param int    $created_by
	 * @return array{id:int,secret:string}|null Null on failure.
	 */
	public static function connect( int $game_id, string $guild_id, string $guild_name, int $created_by ): ?array {
		$secret_bytes = random_bytes( 32 );
		$secret_enc   = Secret_Box::encrypt( $secret_bytes );

		$existing = Manager::get_row(
			'SELECT id FROM ' . Manager::table( 'bot_connections' ) . ' WHERE game_id = %d',
			$game_id
		);

		if ( $existing ) {
			$updated = Manager::update( 'bot_connections', [
				'guild_id'    => $guild_id,
				'guild_name'  => $guild_name,
				'secret'      => $secret_enc,
				'created_by'  => $created_by,
				'created_at'  => current_time( 'mysql' ),
				'last_seen_at' => null,
				'revoked_by'  => null,
				'revoked_at'  => null,
			], [ 'id' => (int) $existing->id ] );
			if ( $updated === false ) {
				return null;
			}
			return [ 'id' => (int) $existing->id, 'secret' => Bot_Signing::base64url_encode( $secret_bytes ) ];
		}

		$id = Manager::insert( 'bot_connections', [
			'game_id'    => $game_id,
			'guild_id'   => $guild_id,
			'guild_name' => $guild_name,
			'secret'     => $secret_enc,
			'created_by' => $created_by,
			'created_at' => current_time( 'mysql' ),
		] );
		if ( $id === false ) {
			return null;
		}
		return [ 'id' => $id, 'secret' => Bot_Signing::base64url_encode( $secret_bytes ) ];
	}

	/**
	 * The active (non-revoked) connection for a game, or null.
	 *
	 * @param int $game_id
	 * @return object|null
	 */
	public static function active_for_game( int $game_id ): ?object {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'bot_connections' ) . ' WHERE game_id = %d AND revoked_at IS NULL',
			$game_id
		);
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		$row = Manager::get_row( 'SELECT * FROM ' . Manager::table( 'bot_connections' ) . ' WHERE id = %d', $id );
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Decrypts a connection's stored secret to its raw 32 bytes.
	 *
	 * @param object $connection
	 * @return string|null
	 */
	public static function secret_plain( object $connection ): ?string {
		return Secret_Box::decrypt( (string) $connection->secret );
	}

	/**
	 * Ends a connection: empties its secret, drops its nonces, keeps its row and its audit history.
	 *
	 * @param int      $id
	 * @param int|null $revoked_by
	 * @return bool
	 */
	public static function disconnect( int $id, ?int $revoked_by ): bool {
		$updated = Manager::update( 'bot_connections', [
			'secret'     => '',
			'revoked_by' => $revoked_by,
			'revoked_at' => current_time( 'mysql' ),
		], [ 'id' => $id ] );
		Manager::delete( 'bot_nonces', [ 'connection_id' => $id ] );
		return $updated !== false;
	}

	/**
	 * Updates `last_seen_at`, at most once a minute.
	 *
	 * @param int $id
	 * @return void
	 */
	public static function touch_last_seen( int $id ): void {
		global $wpdb;
		$table = Manager::table( 'bot_connections' );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET last_seen_at = %s WHERE id = %d AND ( last_seen_at IS NULL OR last_seen_at < %s )",
			current_time( 'mysql' ),
			$id,
			wp_date( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS )
		) );
	}

	/**
	 * @param object $row
	 * @return object
	 */
	private static function decode( object $row ): object {
		foreach ( [ 'id', 'game_id', 'created_by', 'revoked_by' ] as $field ) {
			if ( isset( $row->$field ) ) {
				$row->$field = (int) $row->$field;
			}
		}
		return $row;
	}
}
