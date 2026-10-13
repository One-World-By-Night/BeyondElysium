<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Every write a bot made while acting as a linked user.
 */
class Bot_Audit {

	/**
	 * How long a row is kept.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Records one call.
	 *
	 * @param int    $connection_id
	 * @param string $discord_user_id
	 * @param int    $wp_user_id
	 * @param string $method
	 * @param string $route
	 * @param int    $status
	 * @return void
	 */
	public static function record(
		int $connection_id,
		string $discord_user_id,
		int $wp_user_id,
		string $method,
		string $route,
		int $status
	): void {
		Manager::insert( 'bot_audit', [
			'connection_id'    => $connection_id,
			'discord_user_id'  => $discord_user_id,
			'wp_user_id'       => $wp_user_id,
			'method'           => $method,
			'route'            => $route,
			'status'           => $status,
			'created_at'       => current_time( 'mysql' ),
		] );
	}

	/**
	 * A connection's own rows, newest first.
	 *
	 * @param int $connection_id
	 * @param int $limit
	 * @return array<int,object>
	 */
	public static function for_connection( int $connection_id, int $limit = 100 ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'bot_audit' ) . ' WHERE connection_id = %d ORDER BY id DESC LIMIT %d',
			$connection_id,
			$limit
		);
	}

	/**
	 * Deletes every row older than the retention period.
	 *
	 * @return void
	 */
	public static function prune(): void {
		global $wpdb;
		$table = Manager::table( 'bot_audit' );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE created_at < %s",
			wp_date( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS )
		) );
	}
}
