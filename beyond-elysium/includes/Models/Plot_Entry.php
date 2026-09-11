<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for individual posts within a plot's thread.
 *
 * Plot_Entry is a Database\Manager CRUD model backed by the plot_entries
 * table. Each row is one chronological post on a plot's thread, distinguished
 * by its entry_type - action, response, note, or resolution - rather than by
 * separate tables per type. Creating an entry also touches its parent plot's
 * updated_at so activity feeds stay current.
 *
 * @see BE_PROCESS/workflow-0.5.md Step 1.2
 */
class Plot_Entry {

	/**
	 * Entry types. `note` is ST-only, enforced by the REST controller rather
	 * than here, since permission checks belong at the request boundary.
	 */
	const ENTRY_TYPES = [ 'action', 'response', 'note', 'resolution' ];

	/**
	 * Look up a single plot entry by its primary key. Returns the raw row
	 * exactly as stored, with no field decoding applied, or null when no
	 * entry with that ID exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'plot_entries' ) . ' WHERE id = %d',
			$id
		);
	}

	/**
	 * Return the entries belonging to one plot, ordered oldest first - the
	 * order a thread reads in. Supports filtering by entry_type, with no
	 * pagination.
	 *
	 * @param int   $plot_id
	 * @param array $args Filters: entry_type.
	 * @return array
	 */
	public static function for_plot( int $plot_id, array $args = [] ): array {
		global $wpdb;
		$table  = Manager::table( 'plot_entries' );
		$where  = [ 'plot_id = %d' ];
		$values = [ $plot_id ];

		if ( ! empty( $args['entry_type'] ) ) {
			$where[]  = 'entry_type = %s';
			$values[] = $args['entry_type'];
		}

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY created_at ASC, id ASC';
		$sql = $wpdb->prepare( $sql, $values );
		return $wpdb->get_results( $sql ) ?: [];
	}

	/**
	 * Insert a new plot entry. Validates entry_type against ENTRY_TYPES, and on
	 * success also updates the parent plot's updated_at timestamp so a plot
	 * with a new entry sorts as recently changed.
	 *
	 * @param array $data
	 * @return int|false Insert ID, or false if the entry type is not recognized.
	 */
	public static function create( array $data ) {
		$entry_type = $data['entry_type'] ?? '';
		if ( ! in_array( $entry_type, self::ENTRY_TYPES, true ) ) {
			return false;
		}

		$insert = [
			'plot_id'    => (int) $data['plot_id'],
			'author_id'  => $data['author_id'] ?? get_current_user_id(),
			'entry_type' => $entry_type,
			'content'    => $data['content'] ?? '',
			'event_date' => $data['event_date'] ?? null,
			'created_at' => current_time( 'mysql' ),
		];

		$id = Manager::insert( 'plot_entries', $insert );

		if ( $id ) {
			Manager::update( 'plots', [ 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $insert['plot_id'] ] );
		}

		return $id;
	}

	/**
	 * Update a plot entry's content and event_date fields. Writes only the
	 * fields present in $data; no other fields on the entry are editable
	 * through this method.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$allowed = [ 'content', 'event_date' ];
		$update  = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		$result = Manager::update( 'plot_entries', $update, [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Delete a single plot entry by its primary key. Does not touch the parent
	 * plot or any other entry on its thread, and does not renumber or reorder
	 * the entries that remain.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$result = Manager::delete( 'plot_entries', [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Delete every entry belonging to a plot. Used to cascade a plot deletion
	 * so no orphaned entries remain once the plot itself is gone, removing the
	 * whole thread in one query.
	 *
	 * @param int $plot_id
	 * @return void
	 */
	public static function delete_for_plot( int $plot_id ): void {
		Manager::delete( 'plot_entries', [ 'plot_id' => $plot_id ] );
	}
}
