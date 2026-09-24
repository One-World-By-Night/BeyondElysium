<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for creature stack definitions.
 */
class Creature_Stack {

	/**
	 * Look up a single creature stack by its slug.
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
	 * Look up a single creature stack by its primary key.
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
	 * Return creature stacks matching optional filters.
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
	 * Filters all() by the given chronicle's own `settings.enabled_stacks`.
	 *
	 * @param string $game_slug
	 * @param array  $args Same filters as all().
	 * @return array
	 */
	public static function all_for_game( string $game_slug, array $args = [] ): array {
		$stacks = self::all( $args );

		$game = \BeyondElysium\Models\Game::find_by_slug( $game_slug );
		if ( $game === null ) {
			return $stacks;
		}

		$enabled = $game->settings->enabled_stacks ?? null;
		if ( ! is_array( $enabled ) || empty( $enabled ) ) {
			return $stacks;
		}

		return array_values( array_filter( $stacks, static function ( $stack ) use ( $enabled ) {
			return in_array( $stack->slug, $enabled, true );
		} ) );
	}

	/**
	 * Count creature stacks matching the given filters.
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
	 * Resolve a creature stack into its full definition: the stack row itself, plus every schema block its sections
	 * reference, keyed by slug.
	 *
	 * @param string $slug
	 * @param string $game_slug
	 * @return array|null [ 'stack' => object, 'blocks' => array ] or null if not found.
	 */
	/**
	 * Global blocks no stack's own `stack_definition` ever references.
	 */
	private const GLOBAL_NPC_BLOCK_SLUGS = [ 'npc-roleplaying-notes', 'npc-quick-stats' ];

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

		$block_slugs = array_values( array_unique( array_merge( $block_slugs, self::GLOBAL_NPC_BLOCK_SLUGS ) ) );
		$blocks = Schema_Block::find_by_slugs_for_game( $block_slugs, $game_slug );

		// A chronicle that has opened a purchase list buys from every creature type's entries for that area.
		if ( $game_slug !== '' ) {
			$blocks = \BeyondElysium\Services\Purchase_Scope::widen_blocks( $blocks, $game_slug );
		}

		return [
			'stack'  => $stack,
			'blocks' => $blocks,
		];
	}

	/**
	 * Narrows every `identity_field` option list in an already-resolved stack down to this game's
	 * `settings.enabled_factions.{stack_slug}` restriction.
	 *
	 * @param array{stack:object,blocks:array<string,object>} $resolved
	 * @return array{stack:object,blocks:array<string,object>}
	 */
	public static function narrow_for_creation( array $resolved, string $stack_slug, string $game_slug ): array {
		if ( $game_slug === '' ) {
			return $resolved;
		}

		$game = \BeyondElysium\Models\Game::find_by_slug( $game_slug );
		if ( $game === null ) {
			return $resolved;
		}

		$restrictions = (array) ( $game->settings->enabled_factions->$stack_slug ?? [] );
		if ( $restrictions === [] ) {
			return $resolved;
		}

		foreach ( $resolved['blocks'] as $block ) {
			if ( $block->section_type !== 'identity_field' || empty( $block->definition->fields ) ) {
				continue;
			}
			foreach ( $block->definition->fields as $field ) {
				$allowed = $restrictions[ $field->name ] ?? null;
				if ( ! is_array( $allowed ) || $allowed === [] || empty( $field->options ) ) {
					continue;
				}
				$field->options = array_values( array_intersect( (array) $field->options, $allowed ) );
			}
		}

		return $resolved;
	}

	/**
	 * Finds the first catalog-item value submitted in `$sheet_data` that this game's `enabled_factions` restriction
	 * disallows, for a creature stack's own identity fields.
	 *
	 * @param array<string,mixed>   $sheet_data Keyed by block_slug, as a character's own sheet_data is shaped.
	 * @param array<string,object>  $blocks     Keyed by block_slug, as resolve()['blocks'] returns.
	 * @return array{block:string,field:string,value:string}|null
	 */
	public static function find_disallowed_identity_value( string $stack_slug, array $sheet_data, array $blocks, string $game_slug ): ?array {
		$game = \BeyondElysium\Models\Game::find_by_slug( $game_slug );
		if ( $game === null ) {
			return null;
		}

		$restrictions = (array) ( $game->settings->enabled_factions->$stack_slug ?? [] );
		if ( $restrictions === [] ) {
			return null;
		}

		foreach ( $sheet_data as $block_slug => $values ) {
			$block = $blocks[ $block_slug ] ?? null;
			if ( ! $block || $block->section_type !== 'identity_field' || ! is_array( $values ) ) {
				continue;
			}
			foreach ( $values as $field_name => $value ) {
				$allowed = $restrictions[ $field_name ] ?? null;
				if ( ! is_array( $allowed ) || $allowed === [] ) {
					continue;
				}
				foreach ( is_array( $value ) ? $value : [ $value ] as $one ) {
					if ( $one !== '' && $one !== null && ! in_array( $one, $allowed, true ) ) {
						return [ 'block' => (string) $block_slug, 'field' => (string) $field_name, 'value' => (string) $one ];
					}
				}
			}
		}

		return null;
	}

	/**
	 * Insert a new creature stack.
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
	 * Update a creature stack identified by slug.
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
	 * Delete a creature stack identified by slug.
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
	 * Decode a row's stack_definition and creation_rules JSON fields into arrays in place.
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
		if ( isset( $row->is_system ) ) {
			$row->is_system = (bool) $row->is_system;
		}
		return $row;
	}

	/**
	 * Normalize a value for storage in a JSON column.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function encode_if_array( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		return is_string( $value ) ? $value : '{}';
	}
}
