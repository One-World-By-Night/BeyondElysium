<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * An event the bot should deliver to Discord - a linked player's own room or direct message, or a
 * chronicle's staff channel when no player is named.
 */
class Bot_Notice {

	/**
	 * How long a delivered row is kept.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Adds one notice. A null `$wp_user_id` is a staff notice; a real one is for that player alone.
	 *
	 * @param int                  $game_id
	 * @param int|null             $wp_user_id
	 * @param string               $kind
	 * @param array<string,mixed>  $payload
	 * @return int
	 */
	public static function add( int $game_id, ?int $wp_user_id, string $kind, array $payload ): int {
		Manager::insert( 'bot_notices', [
			'game_id'      => $game_id,
			'wp_user_id'   => $wp_user_id,
			'kind'         => $kind,
			'payload'      => wp_json_encode( $payload ),
			'created_at'   => current_time( 'mysql' ),
			'delivered_at' => null,
		] );
		global $wpdb;
		return (int) $wpdb->insert_id;
	}

	/**
	 * A chronicle's own undelivered notices, oldest first, each with its own decoded payload.
	 *
	 * @param int $game_id
	 * @param int $limit
	 * @return array<int,object>
	 */
	public static function undelivered_for_game( int $game_id, int $limit = 100 ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'bot_notices' ) . ' WHERE game_id = %d AND delivered_at IS NULL ORDER BY id ASC LIMIT %d',
			$game_id,
			$limit
		);
		foreach ( $rows as $row ) {
			$row->payload = $row->payload ? json_decode( (string) $row->payload, true ) : [];
			$row->wp_user_id = $row->wp_user_id !== null ? (int) $row->wp_user_id : null;
		}
		return $rows;
	}

	/**
	 * Marks the given ids delivered, scoped to one chronicle so a bot can never ack another's rows.
	 *
	 * @param int        $game_id
	 * @param int[]      $ids
	 * @return int How many rows were actually marked.
	 */
	public static function mark_delivered( int $game_id, array $ids ): int {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		global $wpdb;
		$table        = Manager::table( 'bot_notices' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$query        = $wpdb->prepare(
			"UPDATE {$table} SET delivered_at = %s WHERE game_id = %d AND delivered_at IS NULL AND id IN ({$placeholders})",
			array_merge( [ current_time( 'mysql' ), $game_id ], $ids )
		);
		return (int) $wpdb->query( $query );
	}

	/**
	 * Deletes every delivered row older than the retention period.
	 *
	 * @return void
	 */
	public static function prune(): void {
		global $wpdb;
		$table = Manager::table( 'bot_notices' );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE delivered_at IS NOT NULL AND delivered_at < %s",
			wp_date( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS )
		) );
	}
}
