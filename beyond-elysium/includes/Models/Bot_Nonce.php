<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Every nonce a connection's signed call has used, so a replay of it is refused.
 */
class Bot_Nonce {

	/**
	 * Records a connection's nonce as used. False means it was already seen for that connection.
	 *
	 * @param int    $connection_id
	 * @param string $nonce
	 * @return bool
	 */
	public static function claim( int $connection_id, string $nonce ): bool {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		$inserted = Manager::insert( 'bot_nonces', [
			'connection_id' => $connection_id,
			'nonce'         => $nonce,
			'created_at'    => current_time( 'mysql' ),
		] );
		$wpdb->suppress_errors( $previous );
		return $inserted !== false;
	}

	/**
	 * Deletes every nonce older than an hour.
	 *
	 * @return void
	 */
	public static function prune(): void {
		global $wpdb;
		$table = Manager::table( 'bot_nonces' );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE created_at < %s",
			wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
		) );
	}
}
