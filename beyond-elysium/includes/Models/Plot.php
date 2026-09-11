<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for narrative threads: plots, actions, and rumors.
 *
 * Plot is a Database\Manager CRUD model backed by the plots table, one shared
 * schema for what were previously three separate narrative types. What
 * distinguishes an action from a plot from a rumor is its initiated_by value,
 * its target_query, and the entry types posted to its thread, not a separate
 * table or schema. Plots can nest under a parent_plot_id to form arcs,
 * subplots, seasons, and episodes.
 *
 * @see BE_PROCESS/workflow-0.5.md Step 1.1
 */
class Plot {

	/** @var string[] Valid stored `status` values. */
	const STATUSES = [ 'active', 'resolved', 'archived' ];

	/** @var string[] Valid `initiated_by` values. */
	const INITIATORS = [ 'player', 'st' ];

	/**
	 * Display and filtering grouping labels only; nothing branches behavior on
	 * this value. An ordinary plot, action, or rumor leaves this null.
	 *
	 * @var string[]
	 */
	const PLOT_CATEGORIES = [ 'arc', 'subplot', 'season', 'episode' ];

	/**
	 * Look up a single plot by its primary key. A row whose target_query or
	 * faction_goals field fails to decode is treated as corrupt: the error is
	 * logged and that field is returned as null rather than the plot being
	 * dropped entirely.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'plots' ) . ' WHERE id = %d',
			$id
		);
		return self::decode_json_columns( $row );
	}

	/**
	 * Return the immediate child plots of a given plot via the parent_plot_id
	 * hierarchy - an action nested under a plot, or a rumor, subplot, or episode
	 * nested under whichever plot it belongs to. Ordered oldest first.
	 *
	 * @param int $plot_id
	 * @return object[]
	 */
	public static function children( int $plot_id ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'plots' ) . ' WHERE parent_plot_id = %d ORDER BY created_at ASC',
			$plot_id
		);
		return array_map( [ self::class, 'decode_json_columns' ], $rows ?: [] );
	}

	/**
	 * Walk up a plot's parent_plot_id chain to its root, for breadcrumb display.
	 * Stops after 50 hops as a safeguard against a corrupt or cyclic chain, even
	 * though update() normally prevents a cycle from being created.
	 *
	 * @param int $plot_id
	 * @return object[] Nearest ancestor first.
	 */
	public static function ancestors( int $plot_id ): array {
		$chain = [];
		$seen  = [];
		$current = self::find( $plot_id );

		while ( $current && ! empty( $current->parent_plot_id ) && count( $chain ) < 50 ) {
			if ( isset( $seen[ $current->parent_plot_id ] ) ) {
				break;
			}
			$seen[ $current->parent_plot_id ] = true;
			$parent                           = self::find( (int) $current->parent_plot_id );
			if ( ! $parent ) {
				break;
			}
			$chain[] = $parent;
			$current = $parent;
		}

		return $chain;
	}

	/**
	 * Return plots belonging to a game. Supports filtering by status,
	 * initiated_by, title/description search, and a created_at date range, plus
	 * pagination and sort order; defaults to newest-updated first. date_from
	 * and date_to filter on created_at (when the thread started), not on the
	 * plot's in-fiction start_date/end_date.
	 *
	 * @param int   $game_id
	 * @param array $args Filters: status, initiated_by, search, date_from, date_to,
	 *                    per_page, offset, orderby, order.
	 * @return array
	 */
	public static function for_game( int $game_id, array $args = [] ): array {
		global $wpdb;
		$table  = Manager::table( 'plots' );
		$where  = [ 'game_id = %d' ];
		$values = [ $game_id ];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['initiated_by'] ) ) {
			$where[]  = 'initiated_by = %s';
			$values[] = $args['initiated_by'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(title LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = $args['date_from'];
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = $args['date_to'];
		}

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where );

		$orderby = in_array( $args['orderby'] ?? 'updated_at', [ 'title', 'status', 'created_at', 'updated_at' ], true )
			? ( $args['orderby'] ?? 'updated_at' )
			: 'updated_at';
		$order   = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
		$sql    .= " ORDER BY {$orderby} {$order}";

		if ( isset( $args['per_page'] ) ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['per_page'], (int) ( $args['offset'] ?? 0 ) );
		}

		$sql  = $wpdb->prepare( $sql, $values );
		$rows = $wpdb->get_results( $sql ) ?: [];
		return array_map( [ self::class, 'decode_json_columns' ], $rows );
	}

	/**
	 * Count plots belonging to a game that match the given filters. Accepts the
	 * same status, initiated_by, search, and date range filters as for_game(),
	 * without pagination, and returns a plain integer total.
	 *
	 * @param int   $game_id
	 * @param array $args
	 * @return int
	 */
	public static function count_for_game( int $game_id, array $args = [] ): int {
		global $wpdb;
		$table  = Manager::table( 'plots' );
		$where  = [ 'game_id = %d' ];
		$values = [ $game_id ];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}
		if ( ! empty( $args['initiated_by'] ) ) {
			$where[]  = 'initiated_by = %s';
			$values[] = $args['initiated_by'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(title LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values[] = $like;
			$values[] = $like;
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = $args['date_to'];
		}

		$sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode( ' AND ', $where );
		$sql = $wpdb->prepare( $sql, $values );
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Return plots reachable through direct connections from a user's
	 * characters in a game. Collects every character the user owns, follows
	 * their plot connections in either direction, and returns the matching
	 * plots newest updated first.
	 *
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return array
	 */
	public static function for_user( int $game_id, int $wp_user_id ): array {
		$game = Game::find( $game_id );
		if ( ! $game ) {
			return [];
		}

		$characters = Character::find_for_user( $wp_user_id, $game->slug );
		if ( empty( $characters ) ) {
			return [];
		}

		$plot_ids = [];
		foreach ( $characters as $character ) {
			foreach ( Connection::for_entity( 'character', (int) $character->id ) as $connection ) {
				if ( $connection->source_type === 'plot' ) {
					$plot_ids[ (int) $connection->source_id ] = true;
				}
				if ( $connection->target_type === 'plot' && $connection->target_id !== null ) {
					$plot_ids[ (int) $connection->target_id ] = true;
				}
			}
		}

		if ( empty( $plot_ids ) ) {
			return [];
		}

		$plots = [];
		foreach ( array_keys( $plot_ids ) as $plot_id ) {
			$plot = self::find( $plot_id );
			// Skips a connection whose plot no longer exists rather than returning a null entry.
			if ( $plot && (int) $plot->game_id === $game_id ) {
				$plots[] = $plot;
			}
		}

		usort( $plots, static function ( $a, $b ) {
			return strcmp( $b->updated_at, $a->updated_at );
		} );

		return $plots;
	}

	/**
	 * Return plots eligible for target_query resolution in a game: those with
	 * an explicit non-null target_query, plus those with a null target_query
	 * that carry an 'apr_rumor' tag connection marking them intentionally
	 * public. A plot with no connections and no target_query set is not a
	 * candidate - a null target_query only means "reaches everyone" when the
	 * apr_rumor tag says so.
	 *
	 * @param int $game_id
	 * @return object[]
	 */
	public static function for_target_query_candidates( int $game_id ): array {
		global $wpdb;
		$plots_table       = Manager::table( 'plots' );
		$connections_table = Manager::table( 'connections' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT p.* FROM {$plots_table} p
			 LEFT JOIN {$connections_table} c
			   ON c.source_type = 'plot' AND c.source_id = p.id AND c.target_type = 'tag' AND c.label = 'apr_rumor'
			 WHERE p.game_id = %d AND (p.target_query IS NOT NULL OR c.id IS NOT NULL)",
			$game_id
		) ) ?: [];

		return array_map( [ self::class, 'decode_json_columns' ], $rows );
	}

	/**
	 * Derive a plot's lifecycle state from its dates relative to a reference
	 * date, independent of the stored status column. Returns 'pending' when
	 * as_of is before start_date, 'active' when within range or end_date is
	 * unset, and 'finished' otherwise; defaults as_of to today when omitted.
	 *
	 * @param object      $plot
	 * @param string|null $as_of `Y-m-d` date to compare against; defaults to today.
	 * @return string One of 'pending', 'active', 'finished'.
	 */
	public static function derive_status( $plot, ?string $as_of = null ): string {
		$as_of = $as_of ?? current_time( 'Y-m-d' );

		if ( ! empty( $plot->start_date ) && $as_of < $plot->start_date ) {
			return 'pending';
		}

		if ( empty( $plot->end_date ) || $as_of <= $plot->end_date ) {
			return 'active';
		}

		return 'finished';
	}

	/**
	 * Insert a new plot. Validates status, initiated_by, and plot_category
	 * against their allowed values, and when a parent_plot_id is given, checks
	 * that the parent exists and belongs to the same game before nesting under it.
	 *
	 * @param array $data
	 * @return int|false Insert ID, or false if status/initiated_by is invalid.
	 */
	public static function create( array $data ) {
		$status       = $data['status'] ?? 'active';
		$initiated_by = $data['initiated_by'] ?? 'st';

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		if ( ! in_array( $initiated_by, self::INITIATORS, true ) ) {
			return false;
		}
		if ( ! empty( $data['plot_category'] ) && ! in_array( $data['plot_category'], self::PLOT_CATEGORIES, true ) ) {
			return false;
		}

		$parent_plot_id = ! empty( $data['parent_plot_id'] ) ? (int) $data['parent_plot_id'] : null;
		if ( $parent_plot_id !== null ) {
			$parent = self::find( $parent_plot_id );
			// A parent must exist and belong to the same game, or nesting would leak across chronicles.
			if ( ! $parent || (int) $parent->game_id !== (int) $data['game_id'] ) {
				return false;
			}
		}

		$insert = [
			'game_id'             => (int) $data['game_id'],
			'parent_plot_id'      => $parent_plot_id,
			'image_id'            => ! empty( $data['image_id'] ) ? (int) $data['image_id'] : null,
			'title'               => $data['title'] ?? '',
			'description'         => $data['description'] ?? null,
			'status'              => $status,
			'initiated_by'        => $initiated_by,
			'plot_category'       => $data['plot_category'] ?? null,
			'created_by'          => $data['created_by'] ?? get_current_user_id(),
			'first_introduced'    => $data['first_introduced'] ?? null,
			'start_date'          => $data['start_date'] ?? null,
			'end_date'            => $data['end_date'] ?? null,
			'game_date'           => $data['game_date'] ?? null,
			'resolution_details'  => $data['resolution_details'] ?? null,
			'resolution_impact'   => $data['resolution_impact'] ?? null,
			'cliffhanger'         => $data['cliffhanger'] ?? null,
			'st_notes'            => $data['st_notes'] ?? null,
			'created_at'          => current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		];

		if ( array_key_exists( 'target_query', $data ) ) {
			$insert['target_query'] = self::encode_target_query( $data['target_query'] );
		}
		if ( array_key_exists( 'faction_goals', $data ) ) {
			$insert['faction_goals'] = self::encode_json_field( $data['faction_goals'] );
		}

		return Manager::insert( 'plots', $insert );
	}

	/**
	 * Update a plot. Writes only the fields present in $data, revalidates
	 * status, initiated_by, and plot_category when present, and when
	 * parent_plot_id changes, checks the new parent belongs to the same game
	 * and would not create a cycle.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$allowed = [
			'title', 'description', 'status', 'initiated_by', 'first_introduced',
			'start_date', 'end_date', 'game_date', 'resolution_details', 'resolution_impact',
			'target_query', 'st_notes', 'parent_plot_id', 'plot_category', 'faction_goals',
			'cliffhanger', 'image_id',
		];

		$update = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( isset( $update['status'] ) && ! in_array( $update['status'], self::STATUSES, true ) ) {
			return false;
		}
		if ( isset( $update['initiated_by'] ) && ! in_array( $update['initiated_by'], self::INITIATORS, true ) ) {
			return false;
		}
		if ( ! empty( $update['plot_category'] ) && ! in_array( $update['plot_category'], self::PLOT_CATEGORIES, true ) ) {
			return false;
		}
		if ( array_key_exists( 'parent_plot_id', $update ) ) {
			$update['parent_plot_id'] = ! empty( $update['parent_plot_id'] ) ? (int) $update['parent_plot_id'] : null;
			if ( $update['parent_plot_id'] === $id ) {
				return false;
			}
			if ( $update['parent_plot_id'] !== null ) {
				$parent    = self::find( $update['parent_plot_id'] );
				$this_plot = self::find( $id );
				// A parent must exist and belong to this plot's own game.
				if ( ! $parent || ! $this_plot || (int) $parent->game_id !== (int) $this_plot->game_id ) {
					return false;
				}
				self::assert_no_cycle( $id, $update['parent_plot_id'] );
			}
		}
		if ( array_key_exists( 'target_query', $update ) ) {
			$update['target_query'] = self::encode_target_query( $update['target_query'] );
		}
		if ( array_key_exists( 'faction_goals', $update ) ) {
			$update['faction_goals'] = self::encode_json_field( $update['faction_goals'] );
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$result               = Manager::update( 'plots', $update, [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Verify that setting $plot_id's parent to $new_parent_id would not make
	 * $plot_id its own ancestor. Walks $new_parent_id's existing ancestor chain
	 * looking for $plot_id, throwing if found.
	 *
	 * @param int $plot_id
	 * @param int $new_parent_id
	 * @throws \RuntimeException When the proposed parent's own ancestor chain already
	 *                           contains $plot_id.
	 */
	private static function assert_no_cycle( int $plot_id, int $new_parent_id ): void {
		$chain   = [ $new_parent_id ];
		$current = self::find( $new_parent_id );

		while ( $current && ! empty( $current->parent_plot_id ) ) {
			$next = (int) $current->parent_plot_id;
			if ( $next === $plot_id ) {
				throw new \RuntimeException( sprintf(
					'Setting plot %d\'s parent to %d would create a cycle: %s -> %d',
					$plot_id,
					$new_parent_id,
					implode( ' -> ', $chain ),
					$plot_id
				) );
			}
			if ( in_array( $next, $chain, true ) ) {
				// Breaks out on a pre-existing cycle rather than looping forever.
				break;
			}
			$chain[] = $next;
			$current = self::find( $next );
		}
	}

	/**
	 * Delete a plot by ID, cascading to its entries and to connections
	 * referencing it. Runs inside a transaction that correctly nests within an
	 * already-open outer transaction, so a failure partway through leaves
	 * nothing committed.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$savepoint = Transaction::begin( 'be_plot_delete' );

		Plot_Entry::delete_for_plot( $id );
		Connection::delete_for_entity( 'plot', $id );
		$result = Manager::delete( 'plots', [ 'id' => $id ] );

		if ( $result === false ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * JSON-encode a value for storage in the target_query column. A thin
	 * wrapper around encode_json_field(), kept separate only to name its
	 * purpose clearly at each call site.
	 *
	 * @param mixed $value
	 * @return string|null
	 */
	private static function encode_target_query( $value ): ?string {
		return self::encode_json_field( $value );
	}

	/**
	 * Normalize a value for storage in a JSON column. Passes null through
	 * unchanged, JSON-encodes an array or object, and casts anything else to a
	 * plain string.
	 *
	 * @param mixed $value
	 * @return string|null
	 */
	private static function encode_json_field( $value ): ?string {
		if ( $value === null ) {
			return null;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}

	/**
	 * Decode the target_query and faction_goals JSON columns on a row object in
	 * place. A NULL column value is left as null (a legitimate value, not
	 * corruption); a non-NULL value that fails to decode is logged and replaced
	 * with null rather than the row being dropped.
	 *
	 * @param object|null $row
	 * @return object|null
	 */
	private static function decode_json_columns( $row ) {
		if ( ! $row ) {
			return null;
		}

		foreach ( [ 'target_query', 'faction_goals' ] as $field ) {
			if ( ! property_exists( $row, $field ) || $row->$field === null ) {
				continue;
			}

			$decoded = json_decode( $row->$field, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( sprintf(
					'Beyond Elysium: plot id %d has corrupt %s JSON; treating as null.',
					(int) $row->id,
					$field
				) );
				$decoded = null;
			}

			$row->$field = $decoded;
		}

		return $row;
	}
}
