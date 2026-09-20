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
 * @see BE_PROCESS/releases/workflow-0.7.md Step 1
 */
class World_Object {

	/** Source of truth for what each `object_type` may carry in `properties`. */
	const SCHEMAS_FILE = __DIR__ . '/../Services/world-object-schemas.php';

	/**
	 * Valid stored `audience` values (1.1.0 §2.1) - duplicated from `Services\Audience::VALUES`
	 * rather than imported, the same reasoning `Plot::AUDIENCE_VALUES` gives: Models does not
	 * depend on Services classes in this codebase.
	 *
	 * @var string[]
	 */
	const AUDIENCE_VALUES = [ 'everyone', 'storytellers', 'restricted' ];

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
	 * Look up a single world object and lock its row for the rest of the current transaction
	 * (1.1.0 §3.12 item 2 - the "use" route decrements `uses_left` and must never race with a
	 * concurrent use of the same item). Same `SELECT ... FOR UPDATE` shape as
	 * `Transfer::find_for_update()`/`Submission::find_for_update()`. Caller must already be
	 * inside a `Transaction::begin()` block - a lock taken outside one releases immediately.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find_for_update( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'world_objects' ) . ' WHERE id = %d FOR UPDATE',
			$id
		);
		return self::decode( $row );
	}

	/**
	 * Whether an item's uses are exhausted (1.1.0 §3.12 item 2) - true only when `uses_max` is
	 * actually set (an item with no uses concept is never "used up") and `uses_left` has
	 * reached zero or below. Derived on every read, never stored.
	 *
	 * @param object $item A decoded item row.
	 * @return bool
	 */
	public static function is_used_up( object $item ): bool {
		$uses_max = $item->properties['uses_max'] ?? null;
		if ( $uses_max === null || $uses_max === '' ) {
			return false;
		}
		return (int) ( $item->properties['uses_left'] ?? 0 ) <= 0;
	}

	/**
	 * Whether an item's `expires_on` date has passed (1.1.0 §3.12 item 2), measured against
	 * the site's own current date. Derived on every read, never stored.
	 *
	 * @param object $item A decoded item row.
	 * @return bool
	 */
	public static function is_expired( object $item ): bool {
		$expires_on = $item->properties['expires_on'] ?? null;
		if ( $expires_on === null || $expires_on === '' ) {
			return false;
		}
		return strtotime( (string) $expires_on ) < strtotime( current_time( 'Y-m-d' ) );
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
	 * @param array $args Filters: object_type, rarity, search, copies ('exclude'|'only'|'include',
	 *                    default 'exclude' - 1.1.0 §3.12 item 1), per_page, offset, orderby, order.
	 * @return array
	 */
	public static function for_game( int $game_id, array $args = [] ): array {
		global $wpdb;
		$table = Manager::table( 'world_objects' );
		[ $where, $values ] = self::build_where( $game_id, $args );

		if ( ( $args['copies'] ?? 'exclude' ) === 'exclude' ) {
			$where[] = 'based_on_id IS NULL';
		} elseif ( $args['copies'] === 'only' ) {
			$where[] = 'based_on_id IS NOT NULL';
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
		$table = Manager::table( 'world_objects' );
		[ $where, $values ] = self::build_where( $game_id, $args );

		$sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode( ' AND ', $where );
		$sql = $wpdb->prepare( $sql, $values );
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * The shared WHERE-clause builder behind `for_game()` and `count_for_game()` - both
	 * accept the same object_type/rarity/search filters and had built that part twice,
	 * byte-for-byte, until this was extracted (1.1.1 audit). `copies` stays out of this
	 * helper deliberately: only `for_game()` has ever applied it, and folding it in here
	 * would change `count_for_game()`'s real behavior rather than just deduplicating it -
	 * out of scope for a mechanical extraction.
	 *
	 * @param int   $game_id
	 * @param array $args
	 * @return array{0: string[], 1: array<int,mixed>} `[$where_clauses, $bind_values]`.
	 */
	private static function build_where( int $game_id, array $args ): array {
		global $wpdb;
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

		return [ $where, $values ];
	}

	/**
	 * The immediate child locations of a location, via `parent_id` (1.1.0 §3.9 item 1 -
	 * "Inside of"). Always empty for a non-location, and for a location with no children.
	 *
	 * @param int $id
	 * @return object[]
	 */
	public static function children( int $id ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'world_objects' ) . " WHERE parent_id = %d AND object_type = 'location' ORDER BY name ASC",
			$id
		);
		// decode() never actually returns null for a real, non-null row - its nullable
		// signature exists only for find()'s own "id doesn't exist" case.
		return array_map( static fn( object $row ): object => self::decode( $row ) ?? $row, $rows ?: [] );
	}

	/**
	 * Whether a location has any child location at all - the create-time check behind the
	 * design's own `location_has_children` 409 on delete.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function has_children( int $id ): bool {
		return count( self::children( $id ) ) > 0;
	}

	/**
	 * Walk up a location's `parent_id` chain to its root, for breadcrumb display ("Downtown
	 * › Elysium"). Stops after 50 hops as a safeguard against a corrupt or cyclic chain, even
	 * though update() normally prevents a cycle from being created - identical shape to
	 * `Plot::ancestors()`.
	 *
	 * @param int $id
	 * @return object[] Nearest ancestor first.
	 */
	public static function ancestors( int $id ): array {
		$chain   = [];
		$seen    = [];
		$current = self::find( $id );

		while ( $current && ! empty( $current->parent_id ) && count( $chain ) < 50 ) {
			if ( isset( $seen[ $current->parent_id ] ) ) {
				break;
			}
			$seen[ $current->parent_id ] = true;
			$parent                      = self::find( (int) $current->parent_id );
			if ( ! $parent ) {
				break;
			}
			$chain[] = $parent;
			$current = $parent;
		}

		return $chain;
	}

	/**
	 * Refuses a parent assignment that would make a location its own descendant, walking the
	 * candidate parent's own chain up to 50 hops (the design's explicit cap, matching
	 * ancestors()'s own safeguard) - identical shape to `Plot::assert_no_cycle()`.
	 *
	 * @param int $id
	 * @param int $new_parent_id
	 * @throws \RuntimeException When the assignment would create a cycle.
	 */
	private static function assert_no_cycle( int $id, int $new_parent_id ): void {
		$chain   = [ $new_parent_id ];
		$current = self::find( $new_parent_id );

		while ( $current && ! empty( $current->parent_id ) && count( $chain ) < 50 ) {
			$next = (int) $current->parent_id;
			if ( $next === $id ) {
				throw new \RuntimeException( sprintf(
					'Setting location %d\'s parent to %d would create a cycle: %s -> %d',
					$id,
					$new_parent_id,
					implode( ' -> ', $chain ),
					$id
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
	 * Sanitizes each string property value by its schema type, mirroring how the REST
	 * controller already treats the fixed description/limitations columns: a `text`
	 * property is a rich-text field (Decision 111) sanitized with `wp_kses_post()`, a
	 * `string` one carries no markup and gets `sanitize_text_field()`. Called after
	 * validate_properties() confirms every key and its structural shape are already
	 * correct - a value validate_properties() would have rejected never reaches here.
	 *
	 * @param string $object_type
	 * @param array  $properties
	 * @return array
	 */
	private static function sanitize_properties( string $object_type, array $properties ): array {
		$schema = self::schemas()[ $object_type ] ?? [];
		foreach ( $properties as $key => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			if ( ( $schema[ $key ] ?? null ) === 'text' ) {
				$properties[ $key ] = wp_kses_post( $value );
			} elseif ( ( $schema[ $key ] ?? null ) === 'string' ) {
				$properties[ $key ] = sanitize_text_field( $value );
			}
		}
		return $properties;
	}

	/**
	 * Insert a new world object. Validates object_type against the known
	 * types and its properties against that type's schema before inserting,
	 * JSON-encoding properties for storage. When a parent_id is given (a
	 * location nested "inside of" another, 1.1.0 §3.9 item 1), checks that the
	 * parent exists, is itself a location, and belongs to the same game.
	 *
	 * @param array $data
	 * @return int|false Insert ID, or false if object_type/properties/parent_id are invalid.
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
		$properties = self::sanitize_properties( $object_type, $properties );

		$parent_id = ! empty( $data['parent_id'] ) ? (int) $data['parent_id'] : null;
		if ( $parent_id !== null ) {
			$parent = self::find( $parent_id );
			if ( ! $parent || $parent->object_type !== 'location' || (int) $parent->game_id !== (int) $data['game_id'] ) {
				return false;
			}
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
			'parent_id'    => $parent_id,
			'based_on_id'  => ! empty( $data['based_on_id'] ) ? (int) $data['based_on_id'] : null,
			'created_by'   => $data['created_by'] ?? get_current_user_id(),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		];

		if ( isset( $data['audience'] ) ) {
			if ( ! in_array( $data['audience'], self::AUDIENCE_VALUES, true ) ) {
				return false;
			}
			$insert['audience'] = $data['audience'];
		}
		if ( array_key_exists( 'audience_rules', $data ) ) {
			$insert['audience_rules'] = $data['audience_rules'] === null ? null : wp_json_encode( $data['audience_rules'] );
		}

		return Manager::insert( 'world_objects', $insert );
	}

	/**
	 * Update a world object. Writes only the fields present in $data, and
	 * when properties is included, validates it against the object's
	 * existing object_type before JSON-encoding and saving it. When
	 * parent_id changes, checks the new parent is a real location in the
	 * same game, is not the object itself, and would not create a cycle
	 * (1.1.0 §3.9 item 1).
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 * @throws \RuntimeException When a new parent_id would create a cycle.
	 */
	public static function update( int $id, array $data ): bool {
		$existing = self::find( $id );
		if ( ! $existing ) {
			return false;
		}

		$allowed = [ 'name', 'description', 'rarity', 'cost', 'limitations', 'properties', 'audience', 'audience_rules', 'parent_id' ];
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
			$update['properties'] = wp_json_encode( self::sanitize_properties( $existing->object_type, (array) $update['properties'] ) );
		}

		if ( isset( $update['audience'] ) && ! in_array( $update['audience'], self::AUDIENCE_VALUES, true ) ) {
			return false;
		}
		if ( array_key_exists( 'audience_rules', $update ) ) {
			$update['audience_rules'] = $update['audience_rules'] === null ? null : wp_json_encode( $update['audience_rules'] );
		}

		if ( array_key_exists( 'parent_id', $update ) ) {
			$update['parent_id'] = ! empty( $update['parent_id'] ) ? (int) $update['parent_id'] : null;
			if ( $update['parent_id'] === $id ) {
				return false;
			}
			if ( $update['parent_id'] !== null ) {
				$parent = self::find( $update['parent_id'] );
				if ( ! $parent || $parent->object_type !== 'location' || (int) $parent->game_id !== (int) $existing->game_id ) {
					return false;
				}
				self::assert_no_cycle( $id, $update['parent_id'] );
			}
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

		// Attachment rows are keyed by object_type ('item'/'location'), not the generic
		// 'world_object' Connection uses - Audience's own entity types, not this table's name.
		$object = self::find( $id );
		if ( $object ) {
			Attachment::delete_for_entity( $object->object_type, $id );
			if ( $object->object_type === 'item' ) {
				// 1.1.0 §3.13 - a deleted item's own verification codes go with it; unlike a
				// transfer, there is nothing left for a still_matches check to report on.
				Item_Attestation::revoke_for_object( $id );
			}
		}
		Connection::delete_for_entity( 'world_object', $id );
		// D1 (1.2.5-design-workflow.md §D): the one real gap in this otherwise-complete delete.
		Item_Event::delete_for_object( $id );
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
		if ( $row && isset( $row->audience_rules ) && is_string( $row->audience_rules ) ) {
			$row->audience_rules = json_decode( $row->audience_rules, true );
		}
		return $row;
	}
}
