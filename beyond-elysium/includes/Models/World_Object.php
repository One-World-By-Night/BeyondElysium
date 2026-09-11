<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for items, locations, rotes, and boons.
 *
 * World_Object is a Database\Manager CRUD model backed by the world_objects
 * table. One shared schema serves all four object types, distinguished by an
 * object_type discriminator column plus a properties JSON bag whose allowed
 * keys are validated per type against the schema defined in
 * Services/world-object-schemas.php.
 *
 * @see BE_PROCESS/workflow-0.7.md Step 1
 */
class World_Object {

	/** Source of truth for what each `object_type` may carry in `properties`. */
	const SCHEMAS_FILE = __DIR__ . '/../Services/world-object-schemas.php';

	/** @var array<string,array<string,string>>|null */
	private static ?array $schemas = null;

	/**
	 * Return the object_type-to-properties schema map, loading it from
	 * Services/world-object-schemas.php on first call and caching it in a
	 * static property for every call after that.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function schemas(): array {
		if ( self::$schemas === null ) {
			self::$schemas = require self::SCHEMAS_FILE;
		}
		return self::$schemas;
	}

	/**
	 * Return the list of recognized object_type strings - the keys of the
	 * schema map. Used by callers that need to validate or render these
	 * values without duplicating the list.
	 *
	 * @return string[]
	 */
	public static function valid_types(): array {
		return array_keys( self::schemas() );
	}

	/**
	 * Look up a single world object by its primary key. Returns the row with
	 * its properties field decoded, or null when no object with that ID
	 * exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'world_objects' ) . ' WHERE id = %d',
			$id
		);
		return self::decode( $row );
	}

	/**
	 * Find a world object by exact name within one game and object_type.
	 * Scoped by object_type as well as game, so an item and a location may
	 * share a name without colliding. When more than one object matches,
	 * returns the oldest row by ID rather than picking unpredictably.
	 *
	 * @param int    $game_id
	 * @param string $object_type
	 * @param string $name
	 * @return object|null
	 */
	public static function find_by_name_in_game( int $game_id, string $object_type, string $name ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'world_objects' ) . '
			 WHERE game_id = %d AND object_type = %s AND name = %s
			 ORDER BY id ASC LIMIT 1',
			$game_id,
			$object_type,
			$name
		);
		return self::decode( $row );
	}

	/**
	 * Return world objects belonging to a game. Supports filtering by
	 * object_type, rarity, and name/description search, plus pagination and
	 * sort order.
	 *
	 * @param int   $game_id
	 * @param array $args Filters: object_type, rarity, search, per_page, offset, orderby, order.
	 * @return array
	 */
	public static function for_game( int $game_id, array $args = [] ): array {
		global $wpdb;
		$table  = Manager::table( 'world_objects' );
		$where  = [ 'game_id = %d' ];
		$values = [ $game_id ];

		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$values[] = $args['object_type'];
		}
		if ( ! empty( $args['rarity'] ) ) {
			$where[]  = 'rarity = %s';
			$values[] = $args['rarity'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(name LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values[] = $like;
			$values[] = $like;
		}

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where );

		$orderby = in_array( $args['orderby'] ?? 'name', [ 'name', 'object_type', 'rarity', 'created_at', 'updated_at' ], true )
			? ( $args['orderby'] ?? 'name' )
			: 'name';
		$order   = strtoupper( $args['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
		$sql    .= " ORDER BY {$orderby} {$order}";

		if ( isset( $args['per_page'] ) ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['per_page'], (int) ( $args['offset'] ?? 0 ) );
		}

		$sql  = $wpdb->prepare( $sql, $values );
		$rows = $wpdb->get_results( $sql ) ?: [];
		return array_map( [ self::class, 'decode' ], $rows );
	}

	/**
	 * Count world objects belonging to a game that match the given filters.
	 * Accepts the same object_type, rarity, and search filters as
	 * for_game(), without pagination, and returns a plain integer total.
	 *
	 * @param int   $game_id
	 * @param array $args Same filters as for_game() (no pagination).
	 * @return int
	 */
	public static function count_for_game( int $game_id, array $args = [] ): int {
		global $wpdb;
		$table  = Manager::table( 'world_objects' );
		$where  = [ 'game_id = %d' ];
		$values = [ $game_id ];

		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$values[] = $args['object_type'];
		}
		if ( ! empty( $args['rarity'] ) ) {
			$where[]  = 'rarity = %s';
			$values[] = $args['rarity'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(name LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values[] = $like;
			$values[] = $like;
		}

		$sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode( ' AND ', $where );
		$sql = $wpdb->prepare( $sql, $values );
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Validate a properties payload against its object_type's schema.
	 * Rejects unknown keys rather than storing them, and checks that int,
	 * trait_list, and date typed fields hold values of the right shape.
	 *
	 * @param string $object_type
	 * @param array  $properties
	 * @return string|null Null if valid, otherwise an error message naming the problem key.
	 */
	public static function validate_properties( string $object_type, array $properties ): ?string {
		$schema = self::schemas()[ $object_type ] ?? null;
		if ( $schema === null ) {
			return "Unknown object_type \"{$object_type}\".";
		}

		foreach ( $properties as $key => $value ) {
			if ( ! array_key_exists( $key, $schema ) ) {
				return "\"{$key}\" is not a valid property of object_type \"{$object_type}\".";
			}

			$type = $schema[ $key ];
			if ( $type === 'int' && $value !== null && ! is_numeric( $value ) ) {
				return "\"{$key}\" must be a number.";
			}
			if ( $type === 'trait_list' && $value !== null && ! is_array( $value ) ) {
				return "\"{$key}\" must be a list of traits.";
			}
			if ( $type === 'date' && $value !== null && $value !== '' && strtotime( (string) $value ) === false ) {
				return "\"{$key}\" must be a valid date.";
			}
		}

		return null;
	}

	/**
	 * Insert a new world object. Validates object_type against the known
	 * types and its properties against that type's schema before inserting,
	 * JSON-encoding properties for storage.
	 *
	 * @param array $data
	 * @return int|false Insert ID, or false if object_type/properties are invalid.
	 */
	public static function create( array $data ) {
		$object_type = $data['object_type'] ?? '';
		if ( ! in_array( $object_type, self::valid_types(), true ) ) {
			return false;
		}

		$properties = $data['properties'] ?? [];
		if ( self::validate_properties( $object_type, $properties ) !== null ) {
			return false;
		}

		$insert = [
			'game_id'      => (int) $data['game_id'],
			'object_type'  => $object_type,
			'name'         => $data['name'] ?? '',
			'description'  => $data['description'] ?? null,
			'rarity'       => $data['rarity'] ?? null,
			'cost'         => $data['cost'] ?? null,
			'limitations'  => $data['limitations'] ?? null,
			'properties'   => wp_json_encode( $properties ),
			'created_by'   => $data['created_by'] ?? get_current_user_id(),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		];

		return Manager::insert( 'world_objects', $insert );
	}

	/**
	 * Update a world object. Writes only the fields present in $data, and
	 * when properties is included, validates it against the object's
	 * existing object_type before JSON-encoding and saving it.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$existing = self::find( $id );
		if ( ! $existing ) {
			return false;
		}

		$allowed = [ 'name', 'description', 'rarity', 'cost', 'limitations', 'properties' ];
		$update  = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( array_key_exists( 'properties', $update ) ) {
			if ( self::validate_properties( $existing->object_type, (array) $update['properties'] ) !== null ) {
				return false;
			}
			$update['properties'] = wp_json_encode( $update['properties'] );
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		return Manager::update( 'world_objects', $update, [ 'id' => $id ] ) !== false;
	}

	/**
	 * Delete a world object by ID, cascading to connections that reference
	 * it. Runs inside a transaction that correctly nests within an
	 * already-open outer transaction, so a failure leaves nothing committed.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$savepoint = Transaction::begin( 'be_world_object_delete' );

		Connection::delete_for_entity( 'world_object', $id );
		$result = Manager::delete( 'world_objects', [ 'id' => $id ] );

		if ( $result === false ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * Decode a row's properties JSON field into an array in place. Passes
	 * null rows through unchanged, and normalizes an unparseable or absent
	 * value to an empty array.
	 *
	 * @param object|null $row
	 * @return object|null
	 */
	private static function decode( $row ) {
		if ( $row && isset( $row->properties ) && is_string( $row->properties ) ) {
			$row->properties = json_decode( $row->properties, true ) ?? [];
		}
		return $row;
	}
}
