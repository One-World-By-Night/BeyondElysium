<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for creature stack definitions.
 *
 * Creature_Stack is a Database\Manager CRUD model backed by the creature_stacks
 * table. Each row is one playable creature type - Vampire, Werewolf, Mage, and
 * so on - identified by slug, holding a stack_definition JSON tree of
 * section/trait layout and a creation_rules JSON payload governing new-character
 * point allocation. resolve() expands a stack into its definition plus every
 * schema block it references.
 */
class Creature_Stack {

	/**
	 * Look up a single creature stack by its slug. Returns the row with its
	 * stack_definition and creation_rules JSON fields decoded, or null when no
	 * stack with that slug exists.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public static function find_by_slug( string $slug ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'creature_stacks' ) . ' WHERE slug = %s',
			$slug
		);
		return self::decode_json_fields( $row );
	}

	/**
	 * Look up a single creature stack by its primary key. Returns the row with
	 * its stack_definition and creation_rules JSON fields decoded, or null when
	 * no stack with that ID exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'creature_stacks' ) . ' WHERE id = %d',
			$id
		);
		return self::decode_json_fields( $row );
	}

	/**
	 * Return creature stacks matching optional filters. Supports filtering by
	 * game_line, system flag, and name search, plus pagination and sort order
	 * across name, slug, game_line, and creation date.
	 *
	 * @param array $args Filters: game_line, is_system, search, orderby, order, per_page, offset.
	 * @return array
	 */
	public static function all( array $args = [] ): array {
		global $wpdb;
		$table = Manager::table( 'creature_stacks' );
		$where = [];
		$values = [];

		if ( ! empty( $args['game_line'] ) ) {
			$where[] = 'game_line = %s';
			$values[] = $args['game_line'];
		}

		if ( isset( $args['is_system'] ) && $args['is_system'] !== '' ) {
			$where[] = 'is_system = %d';
			$values[] = (int) $args['is_system'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[] = 'name LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$sql = "SELECT * FROM {$table}";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		// The ?? default must apply in both branches or an unset orderby breaks the query.
		$orderby = in_array( $args['orderby'] ?? 'name', [ 'name', 'slug', 'game_line', 'created_at' ], true )
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
		return array_map( [ self::class, 'decode_json_fields' ], $rows );
	}

	/**
	 * Count creature stacks matching the given filters. Accepts the same
	 * game_line, is_system, and search filters as all(), without pagination,
	 * and returns a plain integer total.
	 *
	 * @param array $args Same filters as all().
	 * @return int
	 */
	public static function count( array $args = [] ): int {
		global $wpdb;
		$table = Manager::table( 'creature_stacks' );
		$where = [];
		$values = [];

		if ( ! empty( $args['game_line'] ) ) {
			$where[] = 'game_line = %s';
			$values[] = $args['game_line'];
		}

		if ( isset( $args['is_system'] ) && $args['is_system'] !== '' ) {
			$where[] = 'is_system = %d';
			$values[] = (int) $args['is_system'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[] = 'name LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
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
	 * Resolve a creature stack into its full definition: the stack row itself,
	 * plus every schema block its sections reference, keyed by slug. When
	 * $game_slug is given, a chronicle's own customized fork of a block is
	 * returned in place of the global one; an empty $game_slug returns only the
	 * base catalog.
	 *
	 * @param string $slug
	 * @param string $game_slug
	 * @return array|null [ 'stack' => object, 'blocks' => array ] or null if not found.
	 */
	public static function resolve( string $slug, string $game_slug = '' ) {
		$stack = self::find_by_slug( $slug );
		if ( ! $stack ) {
			return null;
		}

		$block_slugs = [];
		$sections = $stack->stack_definition->sections ?? [];
		foreach ( $sections as $section ) {
			if ( ! empty( $section->block_slug ) ) {
				$block_slugs[] = $section->block_slug;
			}
			if ( ! empty( $section->negative_block_slug ) ) {
				$block_slugs[] = $section->negative_block_slug;
			}
		}

		$block_slugs = array_values( array_unique( $block_slugs ) );
		$blocks = Schema_Block::find_by_slugs_for_game( $block_slugs, $game_slug );

		return [
			'stack'  => $stack,
			'blocks' => $blocks,
		];
	}

	/**
	 * Insert a new creature stack. Sanitizes the slug, JSON-encodes
	 * stack_definition and creation_rules, and defaults game_line to 'met' when
	 * not supplied.
	 *
	 * @param array $data Stack data.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function create( array $data ) {
		$insert = [
			'slug'             => sanitize_title( $data['slug'] ),
			'name'             => $data['name'],
			'game_line'        => $data['game_line'] ?? 'met',
			'stack_definition' => self::encode_if_array( $data['stack_definition'] ?? [] ),
			'creation_rules'   => self::encode_if_array( $data['creation_rules'] ?? [] ),
			'is_system'        => (int) ( $data['is_system'] ?? 0 ),
			'created_by'       => $data['created_by'] ?? get_current_user_id(),
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		];

		return Manager::insert( 'creature_stacks', $insert );
	}

	/**
	 * Update a creature stack identified by slug. Writes only the fields present
	 * in $data - name, game_line, stack_definition, creation_rules - JSON-encoding
	 * the two definition fields when present, and stamps updated_at.
	 *
	 * @param string $slug
	 * @param array  $data Fields to update.
	 * @return bool
	 */
	public static function update( string $slug, array $data ): bool {
		$allowed = [ 'name', 'game_line', 'stack_definition', 'creation_rules' ];
		$update = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		foreach ( [ 'stack_definition', 'creation_rules' ] as $json_field ) {
			if ( isset( $update[ $json_field ] ) ) {
				$update[ $json_field ] = self::encode_if_array( $update[ $json_field ] );
			}
		}

		$update['updated_at'] = current_time( 'mysql' );

		$result = Manager::update( 'creature_stacks', $update, [ 'slug' => $slug ] );
		return $result !== false;
	}

	/**
	 * Delete a creature stack identified by slug. Refuses to delete a stack
	 * flagged is_system, returning false rather than removing a seeded default,
	 * and returns false when no stack with that slug exists.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function delete( string $slug ): bool {
		$stack = self::find_by_slug( $slug );
		if ( ! $stack ) {
			return false;
		}
		if ( ! empty( $stack->is_system ) ) {
			return false;
		}
		$result = Manager::delete( 'creature_stacks', [ 'slug' => $slug ] );
		return $result !== false;
	}

	/**
	 * Decode a row's stack_definition and creation_rules JSON fields into arrays
	 * in place. Passes null rows through unchanged, leaving any field that is
	 * not a JSON string untouched.
	 *
	 * @param object|null $row
	 * @return object|null
	 */
	private static function decode_json_fields( $row ) {
		if ( ! $row ) {
			return null;
		}
		foreach ( [ 'stack_definition', 'creation_rules' ] as $field ) {
			if ( isset( $row->$field ) && is_string( $row->$field ) ) {
				$row->$field = json_decode( $row->$field );
			}
		}
		return $row;
	}

	/**
	 * Normalize a value for storage in a JSON column. Encodes an array or object
	 * to a JSON string, passes an existing string through unchanged, and falls
	 * back to an empty JSON object for anything else.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function encode_if_array( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}
		return is_string( $value ) ? $value : '{}';
	}
}
