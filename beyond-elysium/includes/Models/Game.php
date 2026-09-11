<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for chronicles (games).
 *
 * Game is a Database\Manager CRUD model backed by the games table. Each row is
 * one chronicle - name, slug, game_type, a JSON settings blob, and ASC role
 * path/notification configuration. delete_with_content() additionally cascades
 * a deletion to every character, plot, world object, template, and saved query
 * that belongs to the chronicle.
 */
class Game {

	/**
	 * Look up a single game by its slug. Returns the row with its settings
	 * field decoded into an object, or null when no game with that slug
	 * exists.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public static function find_by_slug( string $slug ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'games' ) . ' WHERE slug = %s',
			$slug
		);
		return $row ? self::decode_settings( $row ) : null;
	}

	/**
	 * Look up a single game by its primary key. Returns the row with its
	 * settings field decoded into an object, or null when no game with
	 * that ID exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'games' ) . ' WHERE id = %d',
			$id
		);
		return $row ? self::decode_settings( $row ) : null;
	}

	/**
	 * Return games matching optional filters. Supports filtering by game_type,
	 * plus pagination and sort order across name, slug, and creation/update
	 * dates.
	 *
	 * @param array $args Filters: game_type, orderby, order, per_page, offset.
	 * @return array
	 */
	public static function all( array $args = [] ): array {
		global $wpdb;
		$table = Manager::table( 'games' );
		$where = [];
		$values = [];

		if ( ! empty( $args['game_type'] ) ) {
			$where[] = 'game_type = %s';
			$values[] = $args['game_type'];
		}

		$sql = "SELECT * FROM {$table}";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		// The ?? default must apply in both branches or an unset orderby breaks the query.
		$orderby = in_array( $args['orderby'] ?? 'name', [ 'name', 'slug', 'created_at', 'updated_at' ], true )
			? ( $args['orderby'] ?? 'name' )
			: 'name';
		$order = strtoupper( $args['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
		$sql .= " ORDER BY {$orderby} {$order}";

		if ( isset( $args['per_page'] ) ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['per_page'], (int) ( $args['offset'] ?? 0 ) );
		}

		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values );
		}

		$rows = $wpdb->get_results( $sql ) ?: [];
		return array_map( [ self::class, 'decode_settings' ], $rows );
	}

	/**
	 * Count games matching the given filters. Accepts the same game_type filter
	 * as all(), without pagination, and returns a plain integer total rather
	 * than a result set.
	 *
	 * @param array $args Same filters as all().
	 * @return int
	 */
	public static function count( array $args = [] ): int {
		global $wpdb;
		$table = Manager::table( 'games' );
		$where = [];
		$values = [];

		if ( ! empty( $args['game_type'] ) ) {
			$where[] = 'game_type = %s';
			$values[] = $args['game_type'];
		}

		$sql = "SELECT COUNT(*) FROM {$table}";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Insert a new game. Generates a unique slug from the name when one is
	 * not supplied, JSON-encodes an array settings payload, and stamps
	 * created_at and updated_at.
	 *
	 * @param array $data Game data.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function create( array $data ) {
		$slug = ! empty( $data['slug'] )
			? sanitize_title( $data['slug'] )
			: self::unique_slug( $data['name'] );

		$insert = [
			'name'        => $data['name'],
			'slug'        => $slug,
			'game_type'   => $data['game_type'] ?? 'met',
			'description' => $data['description'] ?? '',
			'settings'    => is_array( $data['settings'] ?? null ) ? wp_json_encode( $data['settings'] ) : ( $data['settings'] ?? '{}' ),
			'created_by'  => get_current_user_id(),
			'created_at'  => current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
		];

		return Manager::insert( 'games', $insert );
	}

	/**
	 * Update a game identified by slug. Writes only the fields present in
	 * $data, JSON-encodes an array settings payload, and stamps updated_at
	 * before writing the row.
	 *
	 * @param string $slug
	 * @param array  $data Fields to update.
	 * @return bool
	 */
	public static function update( string $slug, array $data ): bool {
		$allowed = [ 'name', 'slug', 'game_type', 'description', 'settings', 'asc_role_path', 'notifications_enabled' ];
		$update = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		if ( isset( $update['settings'] ) && is_array( $update['settings'] ) ) {
			$update['settings'] = wp_json_encode( $update['settings'] );
		}

		$update['updated_at'] = current_time( 'mysql' );

		$result = Manager::update( 'games', $update, [ 'slug' => $slug ] );
		return $result !== false;
	}

	/**
	 * Delete a game row by slug. Does not cascade to its characters, plots, or
	 * other content - they remain in the database but become unreachable
	 * through the deleted game's slug. See delete_with_content() for the
	 * cascading variant.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function delete( string $slug ): bool {
		$result = Manager::delete( 'games', [ 'slug' => $slug ] );
		return $result !== false;
	}

	/**
	 * Delete a game and every piece of its content: characters, plots, world
	 * objects, game-scoped templates, and saved queries. Each entity's own
	 * delete() method is called so their own cascades (connections, and for
	 * characters, changes/snapshots/sheet style) run too, and the whole
	 * operation runs inside one transaction so a failure partway through leaves
	 * nothing committed. Not wired to any route or UI control; callable
	 * directly for one-off cleanup.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function delete_with_content( string $slug ): bool {
		$game = self::find_by_slug( $slug );
		if ( ! $game ) {
			return false;
		}
		$game_id = (int) $game->id;

		$savepoint = Transaction::begin( 'be_game_delete_with_content' );

		foreach ( Character::all_for_game( $slug ) as $character ) {
			Character::delete( (int) $character->id );
		}
		foreach ( Plot::for_game( $game_id ) as $plot ) {
			Plot::delete( (int) $plot->id );
		}
		foreach ( World_Object::for_game( $game_id ) as $object ) {
			World_Object::delete( (int) $object->id );
		}
		foreach ( Template::for_game( $game_id ) as $template ) {
			Template::delete( (int) $template->id );
		}
		foreach ( Saved_Query::for_game( $game_id ) as $query ) {
			Saved_Query::delete( (int) $query->id );
		}

		$result = Manager::delete( 'games', [ 'slug' => $slug ] );

		if ( $result === false ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * Generate a slug guaranteed not to collide with an existing game.
	 * Sanitizes the base string and appends an incrementing numeric suffix
	 * until the result is unique.
	 *
	 * @param string $base
	 * @return string
	 */
	private static function unique_slug( string $base ): string {
		$slug = sanitize_title( $base );
		$original = $slug;
		$i = 2;
		while ( self::find_by_slug( $slug ) ) {
			$slug = $original . '-' . $i;
			$i++;
		}
		return $slug;
	}

	/**
	 * Decode a row's settings JSON field into an object in place. Passes
	 * null rows through unchanged, and leaves a non-string settings value
	 * untouched rather than attempting to decode it.
	 *
	 * @param object|null $row Row from the database, or null when the query found nothing.
	 * @return object|null The same row, or null when null was passed in.
	 */
	private static function decode_settings( $row ) {
		if ( $row && isset( $row->settings ) && is_string( $row->settings ) ) {
			$row->settings = json_decode( $row->settings );
		}
		return $row;
	}
}
