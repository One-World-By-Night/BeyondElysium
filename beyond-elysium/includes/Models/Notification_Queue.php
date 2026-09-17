<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for queued daily-digest notifications (1.1.0 §3.5).
 *
 * A row here is one plot-post notification a user has opted to receive as part of a once-daily
 * digest rather than immediately - `Notifications::enqueue_or_queue_post()` writes one per
 * recipient per post, and `Maintenance::run()` reads every row for a user, sends one email
 * naming all of them, and deletes exactly the rows it sent (by id, never by user, so a row
 * inserted mid-send is never lost to a race).
 */
class Notification_Queue {

	/**
	 * Queues one notification for later digesting.
	 *
	 * @param int    $wp_user_id
	 * @param int    $game_id
	 * @param string $kind    A short label distinguishing what this notification is about.
	 * @param array  $payload Whatever the eventual digest email needs to describe it.
	 * @return int|false Insert id, or false on failure.
	 */
	public static function create( int $wp_user_id, int $game_id, string $kind, array $payload ) {
		return Manager::insert( 'notification_queue', [
			'wp_user_id' => $wp_user_id,
			'game_id'    => $game_id,
			'kind'       => $kind,
			'payload'    => wp_json_encode( $payload ),
			'created_at' => current_time( 'mysql' ),
		] );
	}

	/**
	 * Every distinct wp_user_id with at least one queued notification - who Maintenance::run()'s
	 * digest sweep has anything to send.
	 *
	 * @return int[]
	 */
	public static function distinct_user_ids(): array {
		$rows = Manager::get_results(
			'SELECT DISTINCT wp_user_id FROM ' . Manager::table( 'notification_queue' ) . ' ORDER BY wp_user_id ASC'
		);
		return array_map( static fn( $row ) => (int) $row->wp_user_id, $rows );
	}

	/**
	 * Every queued row for one user, oldest first, with payload decoded.
	 *
	 * @param int $wp_user_id
	 * @return object[]
	 */
	public static function for_user( int $wp_user_id ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'notification_queue' ) . ' WHERE wp_user_id = %d ORDER BY created_at ASC, id ASC',
			$wp_user_id
		);
		foreach ( $rows as $row ) {
			$row->payload = is_string( $row->payload ) ? ( json_decode( $row->payload, true ) ?? [] ) : [];
		}
		return $rows;
	}

	/**
	 * Deletes exactly the given rows - the digest's own "delete what it sent" step, scoped to
	 * ids rather than a user so a row queued after the digest read its own set is never lost.
	 *
	 * @param int[] $ids
	 * @return void
	 */
	public static function delete_ids( array $ids ): void {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		global $wpdb;
		$table        = Manager::table( 'notification_queue' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}
}
