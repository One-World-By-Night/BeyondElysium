<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for polymorphic entity-to-entity connections.
 *
 * Connection is a Database\Manager CRUD model backed by the connections table.
 * Any character, plot, world object, or tag can link to any other through one
 * shared table rather than a separate join table per entity pair. Each row
 * records a source entity, a target entity (or a tag label with no target row),
 * an optional descriptive label, and notes.
 *
 * @see BE_PROCESS/workflow-0.5.md Step 1.3
 */
class Connection {

	/** @var string[] Valid source_type / target_type values. */
	private static $valid_entity_types = [ 'character', 'plot', 'world_object', 'tag' ];

	/**
	 * Look up a single connection by its primary key. Returns the raw row
	 * exactly as stored, with no field decoding applied, or null when no
	 * connection with that ID exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'connections' ) . ' WHERE id = %d',
			$id
		);
	}

	/**
	 * Return every connection where the given entity is the source side. Ordered
	 * newest first; does not include connections where this entity appears only
	 * as the target.
	 *
	 * @param string $type
	 * @param int    $id
	 * @return array
	 */
	public static function for_source( string $type, int $id ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'connections' ) . ' WHERE source_type = %s AND source_id = %d ORDER BY created_at DESC',
			$type,
			$id
		);
	}

	/**
	 * Return every connection where the given entity is the target side. Ordered
	 * newest first; does not include connections where this entity appears only
	 * as the source.
	 *
	 * @param string $type
	 * @param int    $id
	 * @return array
	 */
	public static function for_target( string $type, int $id ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'connections' ) . ' WHERE target_type = %s AND target_id = %d ORDER BY created_at DESC',
			$type,
			$id
		);
	}

	/**
	 * Return every connection touching an entity, whether it appears as the
	 * source or the target. Combines both directions into one result set ordered
	 * newest first.
	 *
	 * @param string $type
	 * @param int    $id
	 * @return array
	 */
	public static function for_entity( string $type, int $id ): array {
		global $wpdb;
		$table = Manager::table( 'connections' );
		$sql   = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE (source_type = %s AND source_id = %d) OR (target_type = %s AND target_id = %d) ORDER BY created_at DESC",
			$type,
			$id,
			$type,
			$id
		);
		return $wpdb->get_results( $sql ) ?: [];
	}

	/**
	 * Return every connection belonging to a game. Supports filtering by
	 * source_type and target_type, and orders results newest first with no
	 * pagination.
	 *
	 * @param int   $game_id
	 * @param array $args Filters: source_type, target_type.
	 * @return array
	 */
	public static function for_game( int $game_id, array $args = [] ): array {
		global $wpdb;
		$table  = Manager::table( 'connections' );
		$where  = [ 'game_id = %d' ];
		$values = [ $game_id ];

		if ( ! empty( $args['source_type'] ) ) {
			$where[]  = 'source_type = %s';
			$values[] = $args['source_type'];
		}
		if ( ! empty( $args['target_type'] ) ) {
			$where[]  = 'target_type = %s';
			$values[] = $args['target_type'];
		}

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY created_at DESC';
		$sql = $wpdb->prepare( $sql, $values );
		return $wpdb->get_results( $sql ) ?: [];
	}

	/**
	 * Create a connection between two entities. Validates that source_type and
	 * target_type are recognized entity types, and refuses an exact duplicate -
	 * the same source, target, and label tuple returns the existing row's ID
	 * rather than inserting a second row.
	 *
	 * @param array $data
	 * @return int|false Insert ID (new or existing) on success, false on validation failure.
	 */
	public static function create( array $data ) {
		$source_type = $data['source_type'] ?? '';
		$target_type = $data['target_type'] ?? '';

		if ( ! in_array( $source_type, self::$valid_entity_types, true ) ) {
			return false;
		}
		if ( ! in_array( $target_type, self::$valid_entity_types, true ) ) {
			return false;
		}
		if ( empty( $data['source_id'] ) ) {
			return false;
		}
		// A tag target has no row to point at; every other target type must name one.
		if ( $target_type !== 'tag' && empty( $data['target_id'] ) ) {
			return false;
		}

		$target_id = $target_type === 'tag' ? null : (int) $data['target_id'];
		$label     = $data['label'] ?? null;

		$existing = self::find_duplicate(
			(int) $data['game_id'],
			$source_type,
			(int) $data['source_id'],
			$target_type,
			$target_id,
			$label
		);
		if ( $existing ) {
			return (int) $existing->id;
		}

		$insert = [
			'game_id'     => (int) $data['game_id'],
			'source_type' => $source_type,
			'source_id'   => (int) $data['source_id'],
			'target_type' => $target_type,
			'target_id'   => $target_id,
			'label'       => $label,
			'notes'       => $data['notes'] ?? null,
			'created_by'  => $data['created_by'] ?? get_current_user_id(),
			'created_at'  => current_time( 'mysql' ),
		];

		return Manager::insert( 'connections', $insert );
	}

	/**
	 * Find an existing connection matching the exact same game, source, target,
	 * and label tuple. Treats a null target_id or label as an IS NULL match
	 * rather than a bound placeholder, since MySQL's equality operator never
	 * matches NULL.
	 *
	 * @param int         $game_id
	 * @param string      $source_type
	 * @param int         $source_id
	 * @param string      $target_type
	 * @param int|null    $target_id
	 * @param string|null $label
	 * @return object|null
	 */
	private static function find_duplicate( int $game_id, string $source_type, int $source_id, string $target_type, ?int $target_id, ?string $label ) {
		global $wpdb;
		$table = Manager::table( 'connections' );

		$where  = [ 'game_id = %d', 'source_type = %s', 'source_id = %d', 'target_type = %s' ];
		$values = [ $game_id, $source_type, $source_id, $target_type ];

		if ( $target_id === null ) {
			$where[] = 'target_id IS NULL';
		} else {
			$where[]  = 'target_id = %d';
			$values[] = $target_id;
		}

		if ( $label === null ) {
			$where[] = 'label IS NULL';
		} else {
			$where[]  = 'label = %s';
			$values[] = $label;
		}

		$sql = $wpdb->prepare(
			'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' LIMIT 1',
			$values
		);
		return $wpdb->get_row( $sql );
	}

	/**
	 * Update a connection's label and notes. The source, target, and game fields
	 * are not editable through this method - changing what a connection points
	 * at means deleting it and creating a new one.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$allowed = [ 'label', 'notes' ];
		$update  = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		$result = Manager::update( 'connections', $update, [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Delete a single connection row by its primary key. Does not touch the
	 * entities it referenced, only the connection record itself, and does not
	 * cascade to anything else.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$result = Manager::delete( 'connections', [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Delete every connection referencing a given entity, whether it appears as
	 * the source or the target. Used to cascade deletes for entities that own
	 * connections, since this schema has no foreign keys to do it automatically.
	 *
	 * @param string $type
	 * @param int    $id
	 * @return void
	 */
	public static function delete_for_entity( string $type, int $id ): void {
		global $wpdb;
		$table = Manager::table( 'connections' );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE (source_type = %s AND source_id = %d) OR (target_type = %s AND target_id = %d)",
			$type,
			$id,
			$type,
			$id
		) );
	}

	/**
	 * Return the list of entity type strings a connection's source_type or
	 * target_type may hold. Used by callers that need to validate or render
	 * these values without duplicating the list.
	 *
	 * @return string[]
	 */
	public static function valid_entity_types(): array {
		return self::$valid_entity_types;
	}
}
