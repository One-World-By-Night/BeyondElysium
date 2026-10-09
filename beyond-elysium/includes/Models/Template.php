<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Fork_Merge;
use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for character sheet layout templates: the site's templates (`game_id` null) and each
 * chronicle's own. A chronicle's template of the same creature type and kind as a site template is a layer over it,
 * holding the chronicle's resolved layout and the changes it made.
 */
class Template {

	/**
	 * The recognized display type slugs. A template section's `display` field is null or one of these.
	 */
	const DISPLAY_TYPES = [
		'simple', 'multiplier', 'multiplier_dot', 'dot', 'cost', 'note_only',
		'cost_only', 'dot_separate', 'simple_dots', 'simple_number', 'simple_note',
	];

	/**
	 * Look up a single template by its primary key.
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
	 * @param string   $stack_slug
	 * @param string   $template_type
	 * @param int|null $game_id
	 * @return object|null
	 */
	public static function resolve( string $stack_slug, string $template_type, ?int $game_id ) {
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
	 * The resolution chain's decision logic, isolated from the database fetch.
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
	 * Return a merged template view for a game: every global template, with that game's own overrides replacing the
	 * global of the same (stack_slug, template_type) pair.
	 *
	 * @param int                 $game_id
	 * @param array<string,mixed> $args Filters: stack_slug, template_type.
	 * @return array<int,object> Rows, each with an added `is_override` boolean.
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
	 * Return only global templates (game_id IS NULL).
	 *
	 * @param array<string,mixed> $args Filters: stack_slug, template_type, per_page, offset.
	 * @return array<int,object>
	 */
	public static function globals( array $args = [] ): array {
		return self::rows( [ 'game_id IS NULL' ], [], $args );
	}

	/**
	 * Every template row regardless of scope.
	 *
	 * @param array<string,mixed> $args Filters: stack_slug, template_type.
	 * @return array<int,object>
	 */
	public static function all( array $args = [] ): array {
		return self::rows( [ '1=1' ], [], $args );
	}

	/**
	 * Insert a new template.
	 *
	 * @param array<string,mixed> $data Fields: game_id, stack_slug, name, template_type, layout.
	 * @return int Insert ID, or 0 on failure (including a layout that fails validation).
	 */
	public static function create( array $data ): int {
		$layout  = self::to_array_layout( $data['layout'] ?? null );
		$game_id = isset( $data['game_id'] ) ? (int) $data['game_id'] : null;

		if ( self::validate_layout( $layout, self::game_slug_for( $game_id ) ) !== null ) {
			return 0;
		}

		$site = $game_id !== null ? self::site_row( (string) ( $data['stack_slug'] ?? '' ), (string) ( $data['template_type'] ?? '' ) ) : null;

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
		if ( $site ) {
			$insert['fork_changes'] = wp_json_encode( Fork_Merge::changes_against( self::layout_of( $site ), (array) $layout, true ) );
		}

		$id = Manager::insert( 'templates', $insert );
		return $id ?: 0;
	}

	/**
	 * Update a template.
	 *
	 * @param int                 $id
	 * @param array<string,mixed> $data Fields: name, template_type, layout.
	 * @return bool False when the template does not exist or a supplied layout is invalid.
	 */
	public static function update( int $id, array $data ): bool {
		$row = self::find( $id );
		if ( ! $row ) {
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
			if ( self::validate_layout( $layout, self::game_slug_for( $row->game_id ) ) !== null ) {
				return false;
			}
			$update['layout'] = wp_json_encode( $layout );

			$site = $row->game_id !== null ? self::site_row( (string) $row->stack_slug, (string) $row->template_type ) : null;
			if ( $site ) {
				$under   = self::layout_of( $site );
				$changes = self::recorded_changes( $row->fork_changes ?? null, $under, (array) $row->layout );
				$update['fork_changes'] = wp_json_encode( Fork_Merge::stamp( (array) $row->layout, (array) $layout, $changes, $under ) );
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$result                = Manager::update( 'templates', $update, [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Rebuilds every chronicle's template that is a layer over a site template from that site template as it now stands,
	 * keeping what each chronicle changed.
	 *
	 * @param string $stack_slug    Only this creature type's, when given.
	 * @param string $template_type Only this kind, when given.
	 * @return int How many were rebuilt.
	 */
	public static function refresh_layers( string $stack_slug = '', string $template_type = '' ): int {
		global $wpdb;
		$table  = Manager::table( 'templates' );
		$where  = [ 'own.game_id IS NOT NULL' ];
		$values = [];
		if ( $stack_slug !== '' ) {
			$where[]  = 'own.stack_slug = %s';
			$values[] = $stack_slug;
		}
		if ( $template_type !== '' ) {
			$where[]  = 'own.template_type = %s';
			$values[] = $template_type;
		}
		$sql = "SELECT own.id, own.layout, own.fork_changes, site.layout AS site_layout FROM {$table} own
			INNER JOIN {$table} site ON site.game_id IS NULL AND site.stack_slug = own.stack_slug AND site.template_type = own.template_type
			WHERE " . implode( ' AND ', $where );
		$rows = $wpdb->get_results( $values ? $wpdb->prepare( $sql, $values ) : $sql ) ?: [];

		$rebuilt = 0;
		foreach ( $rows as $row ) {
			$under = json_decode( (string) $row->site_layout, true );
			$copy  = json_decode( (string) $row->layout, true );
			if ( ! is_array( $under ) || ! is_array( $copy ) ) {
				continue;
			}
			$changes = self::recorded_changes( $row->fork_changes, $under, $copy );
			$result  = $wpdb->update( $table, [
				'layout'       => wp_json_encode( Fork_Merge::merge( $under, $copy, $changes ) ),
				'fork_changes' => wp_json_encode( $changes ),
				'updated_at'   => current_time( 'mysql' ),
			], [ 'id' => (int) $row->id ] );
			if ( $result === false ) {
				error_log( 'Beyond Elysium: failed to bring site template changes to chronicle template ' . (int) $row->id . ': ' . $wpdb->last_error );
				continue;
			}
			$rebuilt++;
		}
		return $rebuilt;
	}

	/**
	 * Writes a chronicle's template with its recorded changes.
	 *
	 * @param int                 $id     The template's row.
	 * @param array<string,mixed> $layout
	 * @param array<string,mixed> $changes
	 */
	public static function store_layer( int $id, array $layout, array $changes ): bool {
		global $wpdb;
		$table = Manager::table( 'templates' );
		return $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET layout = %s, fork_changes = %s, updated_at = %s WHERE id = %d AND game_id IS NOT NULL",
			wp_json_encode( $layout ),
			wp_json_encode( $changes ),
			current_time( 'mysql' ),
			$id
		) ) !== false;
	}

	/**
	 * Adds a section showing a block to a chronicle's template of one creature type and kind, after the others, making
	 * the chronicle's template from the site's when it has none.
	 *
	 * @return bool False when neither exists, or the block is already shown.
	 */
	public static function add_section_for_game( string $stack_slug, string $template_type, int $game_id, string $block_slug, string $title ): bool {
		$own = self::own_template( $stack_slug, $template_type, $game_id );
		if ( ! $own ) {
			return false;
		}
		$layout   = (array) $own->layout;
		$sections = (array) ( $layout['sections'] ?? [] );
		if ( in_array( $block_slug, array_column( $sections, 'block_slug' ), true ) ) {
			return false;
		}
		$orders     = array_map( 'intval', array_column( $sections, 'order' ) );
		$sections[] = [
			'block_slug' => $block_slug,
			'column'     => 1,
			'order'      => ( $orders ? max( $orders ) : 0 ) + 1,
			'title'      => $title,
			'display'    => null,
			'collapsed'  => false,
			'width'      => 'full',
		];
		$layout['sections'] = $sections;
		return self::update( (int) $own->id, [ 'layout' => $layout ] );
	}

	/**
	 * Removes a section from a chronicle's own template of one creature type and kind.
	 */
	public static function remove_section_for_game( string $stack_slug, string $template_type, int $game_id, string $block_slug ): bool {
		$own = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'templates' ) . ' WHERE game_id = %d AND stack_slug = %s AND template_type = %s',
			$game_id,
			$stack_slug,
			$template_type
		);
		$own = self::decode_layout( $own );
		if ( ! $own ) {
			return false;
		}
		$layout             = (array) $own->layout;
		$layout['sections'] = array_values( array_filter(
			(array) ( $layout['sections'] ?? [] ),
			static fn( $section ): bool => ( $section['block_slug'] ?? null ) !== $block_slug
		) );
		return self::update( (int) $own->id, [ 'layout' => $layout ] );
	}

	/**
	 * Delete a template.
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
	 * Validate a decoded layout against the template schema: version must be 1, columns an integer from 1 to 6, and every
	 * section must name a non-duplicate block_slug the chronicle can read, with a column in range and a recognized display
	 * type.
	 *
	 * @param mixed  $layout
	 * @param string $game_slug The chronicle whose template this is; '' for a site template, which shows book blocks only.
	 * @return \WP_Error|null Null when valid.
	 */
	public static function validate_layout( $layout, string $game_slug = '' ): ?\WP_Error {
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

			if ( ! Schema_Block::find_for_game( $slug, $game_slug ) ) {
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

			// Checks the width like display.
			$width = $section['width'] ?? null;
			if ( $width !== null && ! in_array( $width, [ 'third', 'half', 'full' ], true ) ) {
				return new \WP_Error( 'invalid_layout', "Section '{$slug}': width must be null, third, half, or full.", [ 'status' => 400 ] );
			}
		}

		return null;
	}

	/**
	 * Shared query helper behind globals(), for_game(), and similar callers.
	 *
	 * @param string[]              $where_extra SQL fragments already ANDed together with placeholders.
	 * @param array<int,int|string> $values_extra Placeholder values for $where_extra, in order.
	 * @param array<string,mixed>   $args Filters: stack_slug, template_type, per_page, offset.
	 * @return array<int,object>
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
	 * The site template of a creature type and kind, undecoded, or null.
	 *
	 * @return object|null
	 */
	private static function site_row( string $stack_slug, string $template_type ) {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'templates' ) . ' WHERE game_id IS NULL AND stack_slug = %s AND template_type = %s',
			$stack_slug,
			$template_type
		);
	}

	/**
	 * A chronicle's own template of a creature type and kind, made from the site's when it has none.
	 *
	 * @return object|null
	 */
	private static function own_template( string $stack_slug, string $template_type, int $game_id ) {
		$own = self::decode_layout( Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'templates' ) . ' WHERE game_id = %d AND stack_slug = %s AND template_type = %s',
			$game_id,
			$stack_slug,
			$template_type
		) );
		if ( $own ) {
			return $own;
		}
		$site = self::decode_layout( self::site_row( $stack_slug, $template_type ) );
		if ( ! $site ) {
			return null;
		}
		$id = self::create( [
			'game_id'       => $game_id,
			'stack_slug'    => $stack_slug,
			'name'          => $site->name,
			'template_type' => $template_type,
			'layout'        => $site->layout,
		] );
		return $id ? self::find( $id ) : null;
	}

	/**
	 * A stored template's layout as a plain array.
	 *
	 * @param object $row
	 * @return array<string,mixed>
	 */
	private static function layout_of( object $row ): array {
		$layout = is_string( $row->layout ) ? json_decode( $row->layout, true ) : json_decode( (string) wp_json_encode( $row->layout ), true );
		return is_array( $layout ) ? $layout : [];
	}

	/**
	 * A chronicle template's changes by path: the ones it recorded, or how it differs from the site template, counting
	 * what it leaves out as left out.
	 *
	 * @param mixed               $recorded
	 * @param array<string,mixed> $under
	 * @param array<string,mixed> $copy
	 * @return array<string,mixed>
	 */
	private static function recorded_changes( $recorded, array $under, array $copy ): array {
		$changes = is_string( $recorded ) ? json_decode( $recorded, true ) : null;
		return Fork_Merge::is_by_path( $changes ) ? (array) $changes : Fork_Merge::changes_against( $under, $copy, true );
	}

	/**
	 * The slug of the chronicle a template belongs to; '' for a site template.
	 */
	private static function game_slug_for( ?int $game_id ): string {
		if ( $game_id === null ) {
			return '';
		}
		$game = Game::find( $game_id );
		return $game ? (string) $game->slug : '';
	}

	/**
	 * Builds the merge key that matches a game override to its global template: the row's stack_slug and template_type
	 * joined with a pipe.
	 *
	 * @param object $row
	 * @return string
	 */
	private static function key( object $row ): string {
		return $row->stack_slug . '|' . $row->template_type;
	}

	/**
	 * Normalize a layout value into an array before validation or storage.
	 *
	 * @param mixed $layout
	 * @return mixed The decoded array, or the original value if it was already something
	 *               other than a JSON string.
	 */
	private static function to_array_layout( $layout ) {
		if ( is_string( $layout ) ) {
			$decoded = json_decode( $layout, true );
			return $decoded;
		}
		if ( is_object( $layout ) ) {
			return json_decode( (string) wp_json_encode( $layout ), true );
		}
		return $layout;
	}

	/**
	 * Decode a row's layout JSON column into an array in place.
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
		$row->is_system = (bool) $row->is_system;

		return $row;
	}
}
