<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for character change records.
 *
 * Change is a Database\Manager CRUD model backed by the character_changes table.
 * Each row is one proposed edit to a character - a trait added or removed, a
 * resource or identity field modified, or XP earned, spent, or adjusted - along
 * with its review status. Rows are read per character or across a whole game's
 * approval queue, filtered by status, change_type, and related fields.
 *
 * change_type values: add_trait, remove_trait, modify_trait, modify_resource,
 *                     modify_identity, xp_earn, xp_adjust, import_note
 */
class Change {

	/**
	 * Delete every change record belonging to a character. Removes all rows from
	 * character_changes matching the given character ID, leaving no change history
	 * behind once the character itself is deleted.
	 *
	 * @param int $character_id
	 * @return void
	 */
	public static function delete_for_character( int $character_id ): void {
		global $wpdb;
		$table = Manager::table( 'character_changes' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE character_id = %d", $character_id ) );
	}

	/**
	 * Look up a single change record by its primary key. Returns the row with its
	 * change_data JSON field decoded into an array, or null when no change with
	 * that ID exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'character_changes' ) . ' WHERE id = %d',
			$id
		);
		return $row ? self::decode_change_data( $row ) : null;
	}

	/**
	 * Return the change records belonging to one character. Supports filtering by
	 * status and change_type, plus pagination and sort order, and returns decoded
	 * rows ordered by submission time.
	 *
	 * @param int   $character_id
	 * @param array $args Filters: status, change_type, per_page, offset, order.
	 * @return array
	 */
	public static function for_character( int $character_id, array $args = [] ): array {
		global $wpdb;
		$table  = Manager::table( 'character_changes' );
		$where  = [ 'character_id = %d' ];
		$values = [ $character_id ];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['change_type'] ) ) {
			$where[]  = 'change_type = %s';
			$values[] = $args['change_type'];
		}

		$order = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
		$sql   = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . " ORDER BY submitted_at {$order}";

		if ( isset( $args['per_page'] ) ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['per_page'], (int) ( $args['offset'] ?? 0 ) );
		}

		$sql  = $wpdb->prepare( $sql, $values );
		$rows = $wpdb->get_results( $sql ) ?: [];
		return array_map( [ self::class, 'decode_change_data' ], $rows );
	}

	/**
	 * Count the change records belonging to one character that match the given
	 * filters. Accepts the same status and change_type filters as for_character(),
	 * without pagination, and returns a plain integer total.
	 *
	 * @param int   $character_id
	 * @param array $args Same filters as for_character() (no pagination).
	 * @return int
	 */
	public static function count_for_character( int $character_id, array $args = [] ): int {
		global $wpdb;
		$table  = Manager::table( 'character_changes' );
		$where  = [ 'character_id = %d' ];
		$values = [ $character_id ];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['change_type'] ) ) {
			$where[]  = 'change_type = %s';
			$values[] = $args['change_type'];
		}

		$sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode( ' AND ', $where );
		$sql = $wpdb->prepare( $sql, $values );
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Return change records across every character belonging to a game. Joins to
	 * the characters table to scope by owner_slug, and supports filtering by
	 * status, change_type, character_id, and wp_user_id, plus pagination and order.
	 *
	 * @param string $game_slug
	 * @param array  $args Filters: status, change_type, character_id, wp_user_id, per_page, offset, order.
	 * @return array
	 */
	public static function for_game( string $game_slug, array $args = [] ): array {
		global $wpdb;
		$changes    = Manager::table( 'character_changes' );
		$characters = Manager::table( 'characters' );
		$where      = [ 'c.owner_slug = %s' ];
		$values     = [ $game_slug ];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'ch.status = %s';
			$values[] = $args['status'];
		}
		if ( ! empty( $args['change_type'] ) ) {
			$where[]  = 'ch.change_type = %s';
			$values[] = $args['change_type'];
		}
		if ( ! empty( $args['character_id'] ) ) {
			$where[]  = 'ch.character_id = %d';
			$values[] = (int) $args['character_id'];
		}

		// Filters to changes belonging to characters owned by this WP user.
		if ( ! empty( $args['wp_user_id'] ) ) {
			$where[]  = 'c.wp_user_id = %d';
			$values[] = (int) $args['wp_user_id'];
		}

		$order = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
		$sql   = "SELECT ch.* FROM {$changes} ch INNER JOIN {$characters} c ON c.id = ch.character_id "
			. 'WHERE ' . implode( ' AND ', $where ) . " ORDER BY ch.submitted_at {$order}";

		if ( isset( $args['per_page'] ) ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['per_page'], (int) ( $args['offset'] ?? 0 ) );
		}

		$sql  = $wpdb->prepare( $sql, $values );
		$rows = $wpdb->get_results( $sql ) ?: [];
		return array_map( [ self::class, 'decode_change_data' ], $rows );
	}

	/**
	 * Count change records across every character belonging to a game. Accepts the
	 * same status, change_type, character_id, and wp_user_id filters as for_game(),
	 * without pagination, and returns a plain integer total.
	 *
	 * @param string $game_slug
	 * @param array  $args
	 * @return int
	 */
	public static function count_for_game( string $game_slug, array $args = [] ): int {
		global $wpdb;
		$changes    = Manager::table( 'character_changes' );
		$characters = Manager::table( 'characters' );
		$where      = [ 'c.owner_slug = %s' ];
		$values     = [ $game_slug ];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'ch.status = %s';
			$values[] = $args['status'];
		}
		if ( ! empty( $args['change_type'] ) ) {
			$where[]  = 'ch.change_type = %s';
			$values[] = $args['change_type'];
		}
		if ( ! empty( $args['character_id'] ) ) {
			$where[]  = 'ch.character_id = %d';
			$values[] = (int) $args['character_id'];
		}

		if ( ! empty( $args['wp_user_id'] ) ) {
			$where[]  = 'c.wp_user_id = %d';
			$values[] = (int) $args['wp_user_id'];
		}

		$sql = "SELECT COUNT(*) FROM {$changes} ch INNER JOIN {$characters} c ON c.id = ch.character_id "
			. 'WHERE ' . implode( ' AND ', $where );
		$sql = $wpdb->prepare( $sql, $values );
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Insert a new change record. Encodes an array change_data payload to JSON,
	 * defaults status to pending and submitted_by to the current user, and stamps
	 * submitted_at with the current time.
	 *
	 * @param array $data Change data.
	 * @return int Insert ID, or 0 on failure.
	 */
	public static function create( array $data ): int {
		$insert = [
			'character_id' => (int) $data['character_id'],
			'change_type'  => $data['change_type'],
			'category'     => $data['category'] ?? null,
			'change_data'  => is_array( $data['change_data'] ?? null )
				? wp_json_encode( $data['change_data'] )
				: ( $data['change_data'] ?? '{}' ),
			'xp_cost'      => isset( $data['xp_cost'] ) ? (float) $data['xp_cost'] : 0,
			'status'       => $data['status'] ?? 'pending',
			'submitted_by' => (int) ( $data['submitted_by'] ?? get_current_user_id() ),
			'submitted_at' => current_time( 'mysql' ),
			'notes'        => $data['notes'] ?? null,
			'reason'       => $data['reason'] ?? null,
		];

		$id = Manager::insert( 'character_changes', $insert );
		return (int) $id;
	}

	/**
	 * Update a change record's review outcome. Sets status, reviewed_by, and notes,
	 * and stamps reviewed_at with the current time; used when a storyteller
	 * approves or rejects a pending change.
	 *
	 * @param int      $id
	 * @param string   $status      'approved' or 'rejected'.
	 * @param int      $reviewed_by
	 * @param string|null $notes
	 * @return bool
	 */
	public static function update_status( int $id, string $status, int $reviewed_by, $notes ): bool {
		$update = [
			'status'      => $status,
			'reviewed_by' => $reviewed_by,
			'reviewed_at' => current_time( 'mysql' ),
			'notes'       => $notes,
		];
		$result = Manager::update( 'character_changes', $update, [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Decode a row's change_data JSON field into an array in place. Passes null
	 * rows through unchanged, and normalizes an unparseable or absent value to an
	 * empty array so callers never see a raw JSON string.
	 *
	 * @param object|null $row Row from the database, or null when the query found nothing.
	 * @return object|null The same row, or null when null was passed in.
	 */
	private static function decode_change_data( $row ) {
		if ( $row && isset( $row->change_data ) && is_string( $row->change_data ) ) {
			$row->change_data = json_decode( $row->change_data, true );
			if ( $row->change_data === null ) {
				$row->change_data = [];
			}
		}
		return $row;
	}
}
