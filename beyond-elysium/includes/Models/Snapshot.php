<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for character sheet history snapshots.
 *
 * Snapshot is a Database\Manager CRUD model backed by the
 * character_snapshots table. Each row is a point-in-time copy of a
 * character's sheet_data JSON, optionally tied to the change record that
 * triggered it, giving a full history of how a character's sheet has looked
 * over time.
 */
class Snapshot {

	/**
	 * Return the snapshots belonging to one character. Supports pagination
	 * and sort order, defaulting to newest first, with decoded snapshot_data
	 * on every row.
	 *
	 * @param int   $character_id
	 * @param array $args Filters: per_page, offset, order.
	 * @return array
	 */
	public static function for_character( int $character_id, array $args = [] ): array {
		global $wpdb;
		$table  = Manager::table( 'character_snapshots' );
		$order  = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
		$sql    = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE character_id = %d ORDER BY created_at {$order}",
			$character_id
		);

		if ( isset( $args['per_page'] ) ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['per_page'], (int) ( $args['offset'] ?? 0 ) );
		}

		$rows = $wpdb->get_results( $sql ) ?: [];
		return array_map( [ self::class, 'decode_snapshot_data' ], $rows );
	}

	/**
	 * Count the total number of snapshots recorded for one character, across
	 * its entire history, with no filtering and no pagination applied to the
	 * count itself.
	 *
	 * @param int $character_id
	 * @return int
	 */
	public static function count_for_character( int $character_id ): int {
		global $wpdb;
		$table = Manager::table( 'character_snapshots' );
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE character_id = %d", $character_id )
		);
	}

	/**
	 * Insert a new snapshot capturing a character's current sheet_data as of
	 * right now. Reads only the sheet_data column rather than the full
	 * character row, and stores '{}' when the character has no sheet_data yet.
	 *
	 * @param int      $character_id
	 * @param int|null $change_id Optional triggering change ID.
	 * @return int Insert ID, or 0 on failure.
	 */
	public static function create( int $character_id, $change_id ): int {
		global $wpdb;

		// Fetch only the sheet_data column to avoid a full decode cycle.
		$table       = Manager::table( 'characters' );
		$sheet_data  = $wpdb->get_var(
			$wpdb->prepare( "SELECT sheet_data FROM {$table} WHERE id = %d", $character_id )
		);

		// Normalise to a valid JSON string.
		if ( empty( $sheet_data ) ) {
			$sheet_data = '{}';
		}

		$insert = [
			'character_id'  => $character_id,
			'snapshot_data' => $sheet_data,
			'change_id'     => $change_id,
			'created_at'    => current_time( 'mysql' ),
		];

		$id = Manager::insert( 'character_snapshots', $insert );
		return (int) $id;
	}

	/**
	 * Delete every snapshot belonging to a character. Removes all rows from
	 * character_snapshots matching the given character ID, leaving no
	 * snapshot history behind once the character itself is deleted.
	 *
	 * @param int $character_id
	 * @return void
	 */
	public static function delete_for_character( int $character_id ): void {
		global $wpdb;
		$table = Manager::table( 'character_snapshots' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE character_id = %d", $character_id ) );
	}

	/**
	 * Look up a single snapshot by its primary key. Returns the row with its
	 * snapshot_data field decoded into an array, or null when no snapshot
	 * with that ID exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'character_snapshots' ) . ' WHERE id = %d',
			$id
		);
		return $row ? self::decode_snapshot_data( $row ) : null;
	}

	/**
	 * Decode a row's snapshot_data JSON field into an array in place. Passes
	 * null rows through unchanged, and normalizes an unparseable or absent
	 * value to an empty array.
	 *
	 * @param object|null $row Row from the database, or null when the query found nothing.
	 * @return object|null The same row, or null when null was passed in.
	 */
	private static function decode_snapshot_data( $row ) {
		if ( $row && isset( $row->snapshot_data ) && is_string( $row->snapshot_data ) ) {
			$row->snapshot_data = json_decode( $row->snapshot_data, true );
			if ( $row->snapshot_data === null ) {
				$row->snapshot_data = [];
			}
		}
		return $row;
	}
}
