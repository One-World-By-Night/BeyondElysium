<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for character sheet layout templates.
 *
 * Template is a Database\Manager CRUD model backed by the templates table.
 * Each row is a JSON layout description a React renderer walks - which block
 * goes where, in what order, at what width, and with which display override.
 * Resolution is game-scoped template, then global template, then (outside
 * this class, in Layout_Generator) generated from the stack's own section
 * order when neither exists.
 *
 * @see BE_PROCESS/workflow-0.3.md Step 1
 */
class Template {

	/**
	 * The 11 recognized display type slugs, in a fixed order. A template
	 * section's `display` field is null or one of these.
	 */
	const DISPLAY_TYPES = [
		'simple', 'multiplier', 'multiplier_dot', 'dot', 'cost', 'note_only',
		'cost_only', 'dot_separate', 'simple_dots', 'simple_number', 'simple_note',
	];

	/**
	 * Look up a single template by its primary key. Returns the row with its
	 * layout field decoded, or null when no template with that ID exists or
	 * its layout fails to decode.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'templates' ) . ' WHERE id = %d',
			$id
		);
		return self::decode_layout( $row );
	}

	/**
	 * Resolve the effective template for a stack + type, optionally scoped to a game.
	 *
	 * Chain, stopping at the first hit:
	 *   1. game_id is not null: game-scoped row
	 *   2. global row (game_id IS NULL)
	 *   3. null - the caller falls back to the stack's section order
	 *
	 * A row whose `layout` fails to decode is a corrupt row: it is logged and treated as
	 * a miss at that tier, not returned half-built.
	 *
	 * @param string   $stack_slug
	 * @param string   $template_type
	 * @param int|null $game_id
	 * @return object|null
	 */
	public static function resolve( string $stack_slug, string $template_type, ?int $game_id ) {
		// A null $game_id skips the game-scoped query entirely; only "IS NULL" rows count as global.
		$game_row = $game_id !== null
			? Manager::get_row(
				'SELECT * FROM ' . Manager::table( 'templates' )
				. ' WHERE game_id = %d AND stack_slug = %s AND template_type = %s',
				$game_id,
				$stack_slug,
				$template_type
			)
			: null;

		$global_row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'templates' )
			. ' WHERE game_id IS NULL AND stack_slug = %s AND template_type = %s',
			$stack_slug,
			$template_type
		);

		return self::resolve_from_rows( $game_row, $global_row );
	}

	/**
	 * The resolution chain's decision logic, isolated from the database
	 * fetch. Returns the game-scoped row if it decodes cleanly, otherwise
	 * falls through to the global row, otherwise null.
	 *
	 * @param object|null $game_row   Raw row from the game-scoped query, or null.
	 * @param object|null $global_row Raw row from the global query, or null.
	 * @return object|null
	 */
	public static function resolve_from_rows( ?object $game_row, ?object $global_row ): ?object {
		$resolved = self::decode_layout( $game_row );
		if ( $resolved !== null ) {
			return $resolved;
		}

		return self::decode_layout( $global_row );
	}

	/**
	 * Return a merged template view for a game: every global template, with
	 * that game's own overrides replacing the global of the same
	 * (stack_slug, template_type) pair rather than appearing alongside it.
	 *
	 * @param int   $game_id
	 * @param array $args Filters: stack_slug, template_type.
	 * @return array Rows, each with an added `is_override` boolean.
	 */
	public static function for_game( int $game_id, array $args = [] ): array {
		$globals   = self::rows( [ 'game_id IS NULL' ], [], $args );
		$overrides = self::rows( [ 'game_id = %d' ], [ $game_id ], $args );

		$merged = [];
		foreach ( $globals as $row ) {
			$row->is_override            = false;
			$merged[ self::key( $row ) ] = $row;
		}
		foreach ( $overrides as $row ) {
			$row->is_override            = true;
			$merged[ self::key( $row ) ] = $row;
		}

		return array_values( $merged );
	}

	/**
	 * Return only global templates (game_id IS NULL), never any game's own
	 * overrides. Supports filtering by stack_slug and template_type, plus
	 * pagination.
	 *
	 * @param array $args Filters: stack_slug, template_type, per_page, offset.
	 * @return array
	 */
	public static function globals( array $args = [] ): array {
		return self::rows( [ 'game_id IS NULL' ], [], $args );
	}

	/**
	 * Insert a new template. A null or absent game_id makes it a global
	 * template; validates the layout against the template schema before
	 * inserting, and JSON-encodes it for storage.
	 *
	 * @param array $data Fields: game_id, stack_slug, name, template_type, layout.
	 * @return int Insert ID, or 0 on failure (including a layout that fails validation).
	 */
	public static function create( array $data ): int {
		$layout = self::to_array_layout( $data['layout'] ?? null );

		if ( self::validate_layout( $layout ) !== null ) {
			return 0;
		}

		$insert = [
			'game_id'       => isset( $data['game_id'] ) ? (int) $data['game_id'] : null,
			'stack_slug'    => $data['stack_slug'] ?? null,
			'name'          => $data['name'] ?? '',
			'template_type' => $data['template_type'] ?? '',
			'layout'        => wp_json_encode( $layout ),
			'is_system'     => (int) ( $data['is_system'] ?? 0 ),
			'created_by'    => $data['created_by'] ?? get_current_user_id(),
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		];

		$id = Manager::insert( 'templates', $insert );
		return $id ?: 0;
	}

	/**
	 * Update a template. `game_id` is never part of the allowed fields - a global
	 * template cannot be converted into a game override or vice versa; that is a create
	 * plus a delete.
	 *
	 * @param int   $id
	 * @param array $data Fields: name, template_type, layout.
	 * @return bool False when the template does not exist or a supplied layout is invalid.
	 */
	public static function update( int $id, array $data ): bool {
		if ( ! self::find( $id ) ) {
			return false;
		}

		$update = [];

		foreach ( [ 'name', 'template_type' ] as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( array_key_exists( 'layout', $data ) ) {
			$layout = self::to_array_layout( $data['layout'] );
			if ( self::validate_layout( $layout ) !== null ) {
				return false;
			}
			$update['layout'] = wp_json_encode( $layout );
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$result                = Manager::update( 'templates', $update, [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Delete a template. Deleting a game override simply removes that row, so
	 * the next resolve() for that game falls through to the global template.
	 * Deleting an is_system global is refused outright, since those are
	 * seeded defaults rather than user content.
	 *
	 * @param int $id
	 * @return bool False when the template does not exist or is a protected system global.
	 */
	public static function delete( int $id ): bool {
		$row = self::find( $id );
		if ( ! $row ) {
			return false;
		}

		if ( $row->game_id === null && ! empty( $row->is_system ) ) {
			return false;
		}

		$result = Manager::delete( 'templates', [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Validate a decoded layout against the template schema: version must be
	 * 1, columns an integer from 1 to 6, and every section must name a
	 * known, non-duplicate block_slug with a column in range and a
	 * recognized display type.
	 *
	 * @param mixed $layout
	 * @return \WP_Error|null Null when valid.
	 */
	public static function validate_layout( $layout ): ?\WP_Error {
		if ( ! is_array( $layout ) ) {
			return new \WP_Error( 'invalid_layout', 'layout must be a JSON object.', [ 'status' => 400 ] );
		}

		if ( ( $layout['version'] ?? null ) !== 1 ) {
			return new \WP_Error( 'invalid_layout', 'layout.version must be 1.', [ 'status' => 400 ] );
		}

		$columns = $layout['columns'] ?? null;
		// The grid is 6 tracks wide, matching templateLayout.ts.
		if ( ! is_int( $columns ) || $columns < 1 || $columns > 6 ) {
			return new \WP_Error( 'invalid_layout', 'layout.columns must be an integer from 1 to 6.', [ 'status' => 400 ] );
		}

		$sections = $layout['sections'] ?? null;
		if ( ! is_array( $sections ) ) {
			return new \WP_Error( 'invalid_layout', 'layout.sections must be an array.', [ 'status' => 400 ] );
		}

		$seen_slugs = [];

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || empty( $section['block_slug'] ) || ! is_string( $section['block_slug'] ) ) {
				return new \WP_Error( 'invalid_layout', 'Every section needs a block_slug.', [ 'status' => 400 ] );
			}

			$slug = $section['block_slug'];

			if ( isset( $seen_slugs[ $slug ] ) ) {
				return new \WP_Error( 'invalid_layout', "Duplicate block_slug: {$slug}.", [ 'status' => 400 ] );
			}
			$seen_slugs[ $slug ] = true;

			if ( ! Schema_Block::find_by_slug( $slug ) ) {
				return new \WP_Error( 'invalid_layout', "Unknown block_slug: {$slug}.", [ 'status' => 400 ] );
			}

			$column = $section['column'] ?? null;
			if ( ! is_int( $column ) || $column < 1 || $column > $columns ) {
				return new \WP_Error( 'invalid_layout', "Section '{$slug}': column must be an integer from 1 to layout.columns.", [ 'status' => 400 ] );
			}

			$display = $section['display'] ?? null;
			if ( $display !== null && ! in_array( $display, self::DISPLAY_TYPES, true ) ) {
				return new \WP_Error( 'invalid_layout', "Section '{$slug}': display must be null or one of the 11 display types.", [ 'status' => 400 ] );
			}
		}

		return null;
	}

	/**
	 * Shared query helper behind globals(), for_game(), and similar callers.
	 * Combines caller-supplied WHERE fragments with the common stack_slug,
	 * template_type, and pagination filters, and drops any row whose layout
	 * fails to decode from the result.
	 *
	 * @param string[] $where_extra SQL fragments already ANDed together with placeholders.
	 * @param array    $values_extra Placeholder values for $where_extra, in order.
	 * @param array    $args Filters: stack_slug, template_type, per_page, offset.
	 * @return array
	 */
	private static function rows( array $where_extra, array $values_extra, array $args ): array {
		global $wpdb;

		$where  = $where_extra;
		$values = $values_extra;

		if ( ! empty( $args['stack_slug'] ) ) {
			$where[]  = 'stack_slug = %s';
			$values[] = $args['stack_slug'];
		}

		if ( ! empty( $args['template_type'] ) ) {
			$where[]  = 'template_type = %s';
			$values[] = $args['template_type'];
		}

		$sql = 'SELECT * FROM ' . Manager::table( 'templates' ) . ' WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY stack_slug ASC, template_type ASC';

		if ( isset( $args['per_page'] ) ) {
			$values[] = (int) $args['per_page'];
			$values[] = (int) ( $args['offset'] ?? 0 );
			$sql .= ' LIMIT %d OFFSET %d';
		}

		$sql = $values ? $wpdb->prepare( $sql, $values ) : $sql;

		$rows = $wpdb->get_results( $sql ) ?: [];

		return array_values( array_filter( array_map( [ self::class, 'decode_layout' ], $rows ) ) );
	}

	/**
	 * Build the merge key used to match a game override to its global
	 * template: the row's stack_slug and template_type joined with a pipe,
	 * used by for_game() to line up overrides with their globals.
	 *
	 * @param object $row
	 * @return string
	 */
	private static function key( object $row ): string {
		return $row->stack_slug . '|' . $row->template_type;
	}

	/**
	 * Normalize a layout value into an array before validation or storage.
	 * Decodes a JSON string or re-encodes-then-decodes an object; anything
	 * else, including an already-array value, passes through unchanged.
	 *
	 * @param mixed $layout
	 * @return mixed The decoded array, or the original value if it was already something
	 *               other than a JSON string (so validate_layout() reports it uniformly).
	 */
	private static function to_array_layout( $layout ) {
		if ( is_string( $layout ) ) {
			$decoded = json_decode( $layout, true );
			return $decoded;
		}
		if ( is_object( $layout ) ) {
			return json_decode( wp_json_encode( $layout ), true );
		}
		return $layout;
	}

	/**
	 * Decode a row's layout JSON column into an array in place. Returns
	 * null, logging the failure, when the row is null or its layout does
	 * not decode to an array; also normalizes game_id and is_system to integers.
	 *
	 * @param object|null $row
	 * @return object|null The same row with `layout` decoded, or null when $row is null
	 *                      or its layout fails to decode.
	 */
	private static function decode_layout( $row ) {
		if ( ! $row ) {
			return null;
		}

		$layout = json_decode( $row->layout, true );

		if ( ! is_array( $layout ) ) {
			error_log( sprintf(
				'Beyond Elysium: template id %d has corrupt layout JSON; treating as a miss.',
				(int) $row->id
			) );
			return null;
		}

		$row->layout    = $layout;
		$row->game_id   = $row->game_id !== null ? (int) $row->game_id : null;
		$row->is_system = (int) $row->is_system;

		return $row;
	}
}
