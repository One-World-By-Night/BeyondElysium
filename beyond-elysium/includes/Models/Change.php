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
 *                     modify_identity, xp_earn, xp_adjust, import_note,
 *                     catalog_rekey, catalog_rekey_revert
 *
 * `import_note`, `catalog_rekey` and `catalog_rekey_revert` are records the system writes
 * about itself, already approved and never priced: nothing submits them and no queue lists them.
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
	 * Look up a change and lock its row until the surrounding transaction
	 * ends, so a review decided on this read cannot race another review or
	 * a resubmission of the same change. Must run inside a Transaction.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find_for_update( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'character_changes' ) . ' WHERE id = %d FOR UPDATE',
			$id
		);
		return $row ? self::decode_change_data( $row ) : null;
	}

	/**
	 * A token for exactly the content a reviewer was shown: what the change
	 * does, what it costs, and when it was last submitted. A player's
	 * resubmission rewrites all three in place, so a token taken before it no
	 * longer matches (1.0.0-review F-031).
	 *
	 * @param object $change A decoded change row.
	 * @return string
	 */
	public static function review_token( $change ): string {
		return hash( 'sha256', (string) json_encode( [
			(string) $change->change_type,
			$change->change_data,
			(string) (float) $change->xp_cost,
			(string) $change->submitted_at,
		] ) );
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
		[ $where, $values ] = self::build_where( $game_slug, $args );

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
		[ $where, $values ] = self::build_where( $game_slug, $args );

		$sql = "SELECT COUNT(*) FROM {$changes} ch INNER JOIN {$characters} c ON c.id = ch.character_id "
			. 'WHERE ' . implode( ' AND ', $where );
		$sql = $wpdb->prepare( $sql, $values );
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * The shared WHERE-clause builder behind `for_game()` and `count_for_game()` - both
	 * accept the identical filter vocabulary (status, change_type, character_id,
	 * wp_user_id) and had built it twice, byte-for-byte, until this was extracted
	 * (1.1.1 audit).
	 *
	 * @param string $game_slug
	 * @param array  $args
	 * @return array{0: string[], 1: array<int,mixed>} `[$where_clauses, $bind_values]`.
	 */
	private static function build_where( string $game_slug, array $args ): array {
		$where  = [ 'c.owner_slug = %s' ];
		$values = [ $game_slug ];

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

		return [ $where, $values ];
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
	 * Overwrites a still-pending change's own submitted content in place, re-stamping
	 * `submitted_at` as though it were a fresh submission. Used when a player resubmits an
	 * edit to the same trait/field before a Storyteller has reviewed the first one
	 * (BE_PROCESS/releases/0.99.2-workflow.md, "Resubmitting creates duplicate pending changes") -
	 * updates the one existing row rather than leaving a second, indistinguishable pending
	 * row in the queue. Never touches `status`, `submitted_by`, `character_id`, or anything
	 * review-related - only what a fresh submit() call would have set.
	 *
	 * Returns false when no still-pending row matched - a review that landed
	 * first leaves nothing to overwrite, and the caller must not report the
	 * resubmission as accepted (1.0.0-review F-031).
	 *
	 * @param int   $id
	 * @param array $data change_type, category, change_data, xp_cost, notes, reason - same
	 *                     shape create() accepts.
	 * @return bool
	 */
	public static function update_pending_data( int $id, array $data ): bool {
		$update = [
			'change_type' => $data['change_type'],
			'category'    => $data['category'] ?? null,
			'change_data' => is_array( $data['change_data'] ?? null )
				? wp_json_encode( $data['change_data'] )
				: ( $data['change_data'] ?? '{}' ),
			'xp_cost'     => isset( $data['xp_cost'] ) ? (float) $data['xp_cost'] : 0,
			'submitted_at' => current_time( 'mysql' ),
			'notes'       => $data['notes'] ?? null,
			'reason'      => $data['reason'] ?? null,
		];

		$updated = Manager::update( 'character_changes', $update, [ 'id' => $id, 'status' => 'pending' ] );
		if ( $updated === false ) {
			return false;
		}
		if ( $updated > 0 ) {
			return true;
		}
		// MySQL counts an UPDATE that writes identical values as zero rows, so zero means either
		// "already reviewed" or "resubmitted unchanged within the same second" - tell them apart.
		$status = Manager::get_var( 'SELECT status FROM ' . Manager::table( 'character_changes' ) . ' WHERE id = %d', $id );
		return $status === 'pending';
	}

	/**
	 * Writes the price a Storyteller set on a change that was waiting for one - its `xp_cost`, and
	 * the change data with the price stamped onto its trait - while it is still pending. Called by
	 * `Change_Engine::approve()` inside the same transaction that then applies the change, so the
	 * record and the sheet agree on what was charged.
	 *
	 * Returns false when no still-pending row matched: a review that landed first leaves nothing to
	 * price. A write of identical values reads as zero rows changed, so that case is told apart from
	 * "already reviewed" by the status.
	 *
	 * @param int                      $id
	 * @param float                    $xp_cost     Signed total.
	 * @param array<string,mixed>|null $change_data Replaces the stored data when given.
	 * @return bool
	 */
	public static function update_xp_cost( int $id, float $xp_cost, ?array $change_data = null ): bool {
		$update = [ 'xp_cost' => $xp_cost ];
		if ( $change_data !== null ) {
			$update['change_data'] = wp_json_encode( $change_data );
		}

		$updated = Manager::update( 'character_changes', $update, [ 'id' => $id, 'status' => 'pending' ] );
		if ( $updated === false ) {
			return false;
		}
		if ( $updated > 0 ) {
			return true;
		}
		$status = Manager::get_var( 'SELECT status FROM ' . Manager::table( 'character_changes' ) . ' WHERE id = %d', $id );
		return $status === 'pending';
	}

	/**
	 * Update a change record's review outcome. Sets status, reviewed_by, and the
	 * reviewer's own note, and stamps reviewed_at with the current time; used
	 * when a storyteller approves or rejects a pending change. The submitter's
	 * `notes` are never touched (1.0.0-review F-032).
	 *
	 * @param int      $id
	 * @param string   $status      'approved' or 'rejected'.
	 * @param int      $reviewed_by
	 * @param string|null $notes    The reviewer's note.
	 * @return bool
	 */
	public static function update_status( int $id, string $status, int $reviewed_by, $notes ): bool {
		$update = [
			'status'       => $status,
			'reviewed_by'  => $reviewed_by,
			'reviewed_at'  => current_time( 'mysql' ),
			'review_notes' => $notes,
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
