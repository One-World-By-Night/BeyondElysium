<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for one position's holder history (1.1.0 §3.10, F2) - one row
 * per holder, `ended` null while they still hold it. Written only by `Position::set_holder()`;
 * never edited directly, an append-only log of who held an office and when.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.10
 */
class Position_History {

	/**
	 * Every history row for one position, oldest first.
	 *
	 * @param int $position_id
	 * @return object[]
	 */
	public static function for_position( int $position_id ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'position_history' ) . ' WHERE position_id = %d ORDER BY started ASC, id ASC',
			$position_id
		) ?: [];
	}

	/**
	 * Closes the position's own currently-open row (`ended IS NULL`), if one exists - the
	 * outgoing holder's tenure ends the day the incoming holder (or a vacancy) begins.
	 *
	 * @param int    $position_id
	 * @param string $ended Y-m-d.
	 * @return bool
	 */
	public static function close_open_row( int $position_id, string $ended ): bool {
		global $wpdb;
		$table = Manager::table( 'position_history' );
		return $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET ended = %s WHERE position_id = %d AND ended IS NULL",
			$ended,
			$position_id
		) ) !== false;
	}

	/**
	 * Opens a new history row for an incoming holder.
	 *
	 * @param int    $position_id
	 * @param int    $character_id
	 * @param string $started Y-m-d.
	 * @return int|false Insert ID, or false on failure.
	 */
	public static function open_row( int $position_id, int $character_id, string $started ) {
		return Manager::insert( 'position_history', [
			'position_id'  => $position_id,
			'character_id' => $character_id,
			'started'      => $started,
			'ended'        => null,
			'created_at'   => current_time( 'mysql' ),
		] );
	}

	/**
	 * Deletes every history row for a position being deleted outright.
	 *
	 * @param int $position_id
	 * @return bool
	 */
	public static function delete_for_position( int $position_id ): bool {
		return Manager::delete( 'position_history', [ 'position_id' => $position_id ] ) !== false;
	}
}
