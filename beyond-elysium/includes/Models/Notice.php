<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * What the site tells a person in its own header: one row for each person a notice is for.
 */
class Notice {

	/**
	 * How long a notice is kept.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Adds one notice for one person.
	 *
	 * @param int                 $game_id
	 * @param int                 $wp_user_id
	 * @param string              $kind
	 * @param array<string,mixed> $payload
	 * @return int The new notice's id.
	 */
	public static function add( int $game_id, int $wp_user_id, string $kind, array $payload ): int {
		Manager::insert( 'notices', [
			'game_id'    => $game_id,
			'wp_user_id' => $wp_user_id,
			'kind'       => $kind,
			'payload'    => wp_json_encode( $payload ),
			'created_at' => current_time( 'mysql' ),
			'read_at'    => null,
		] );
		global $wpdb;
		return (int) $wpdb->insert_id;
	}

	/**
	 * A person's own notices in one chronicle, newest first, each with its payload decoded.
	 *
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @param int $limit
	 * @return array<int,object>
	 */
	public static function for_person( int $game_id, int $wp_user_id, int $limit = 30 ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'notices' ) . ' WHERE game_id = %d AND wp_user_id = %d ORDER BY id DESC LIMIT %d',
			$game_id,
			$wp_user_id,
			$limit
		);
		foreach ( $rows as $row ) {
			$row->id      = (int) $row->id;
			$row->payload = $row->payload ? json_decode( (string) $row->payload, true ) : [];
		}
		return $rows;
	}

	/**
	 * How many of a person's notices in one chronicle they have not read.
	 *
	 * @param int $game_id
	 * @param int $wp_user_id
	 */
	public static function unread_count( int $game_id, int $wp_user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . Manager::table( 'notices' ) . ' WHERE game_id = %d AND wp_user_id = %d AND read_at IS NULL',
			$game_id,
			$wp_user_id
		) );
	}

	/**
	 * Marks a person's own notices read: the ones named, or every unread one when none is named. Another person's
	 * notice is never touched.
	 *
	 * @param int        $game_id
	 * @param int        $wp_user_id
	 * @param int[]|null $ids
	 * @return int How many were marked.
	 */
	public static function mark_read( int $game_id, int $wp_user_id, ?array $ids ): int {
		global $wpdb;
		$table = Manager::table( 'notices' );
		$now   = current_time( 'mysql' );

		if ( $ids === null ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET read_at = %s WHERE game_id = %d AND wp_user_id = %d AND read_at IS NULL",
				$now,
				$game_id,
				$wp_user_id
			) );
			return (int) $wpdb->rows_affected;
		}

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( $ids === [] ) {
			return 0;
		}
		$holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET read_at = %s WHERE game_id = %d AND wp_user_id = %d AND read_at IS NULL AND id IN ({$holders})",
			array_merge( [ $now, $game_id, $wp_user_id ], $ids )
		) );
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Deletes the notices older than RETENTION_DAYS.
	 */
	public static function prune(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . Manager::table( 'notices' ) . ' WHERE created_at < %s',
			wp_date( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS )
		) );
	}
}
