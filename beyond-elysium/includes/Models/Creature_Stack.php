<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Fork_Merge;
use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for creature stack definitions: the book's rows (`game_slug` empty) and each chronicle's
 * layer over one, a row holding the chronicle's resolved copy and the changes it made.
 */
class Creature_Stack {

	/**
	 * Look up a creature stack by its slug: the book's row, whatever any chronicle changed.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public static function find_by_slug( string $slug ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'creature_stacks' ) . " WHERE slug = %s AND game_slug = ''",
			$slug
		);
		return self::decode_json_fields( $row );
	}

	/**
	 * Look up a creature stack as one chronicle has it: its layer when it has one, otherwise the book's row.
	 *
	 * @param string $slug
	 * @param string $game_slug
	 * @return object|null
	 */
	public static function find_for_game( string $slug, string $game_slug = '' ) {
		if ( $game_slug !== '' ) {
			$layer = self::layer_row( $slug, $game_slug );
			if ( $layer ) {
				return self::decode_json_fields( $layer );
			}
		}
		return self::find_by_slug( $slug );
	}

	/**
	 * Whether any row uses this slug, book or chronicle-owned.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function slug_in_use( string $slug ): bool {
		return (bool) Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'creature_stacks' ) . ' WHERE slug = %s',
			$slug
		);
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
		$where = [ "game_slug = ''" ];
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

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where );

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
	 * all() as the given chronicle has it: its layers in place of the book's rows, filtered by its own
	 * `settings.enabled_stacks`.
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

		foreach ( $stacks as $i => $stack ) {
			$layer = self::layer_row( (string) $stack->slug, $game_slug );
			if ( $layer ) {
				$stacks[ $i ] = self::decode_json_fields( $layer );
			}
		}

		$stacks = array_merge( $stacks, self::own_creature_types( $game_slug, $args, array_column( $stacks, 'slug' ) ) );

		$enabled = $game->settings->enabled_stacks ?? null;
		if ( ! is_array( $enabled ) || empty( $enabled ) ) {
			return $stacks;
		}

		return array_values( array_filter( $stacks, static function ( $stack ) use ( $enabled ) {
			return in_array( $stack->slug, $enabled, true );
		} ) );
	}

	/**
	 * A chronicle's own creature types: rows it owns outright with no book counterpart at all, matching the given
	 * filters.
	 *
	 * @param string   $game_slug
	 * @param array    $args        Same filters as all(): game_line, is_system, search.
	 * @param string[] $book_slugs  Slugs already covered by the book, excluded here.
	 * @return object[]
	 */
	private static function own_creature_types( string $game_slug, array $args, array $book_slugs ): array {
		if ( $game_slug === '' ) {
			return [];
		}
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'creature_stacks' ) . ' WHERE game_slug = %s',
			$game_slug
		);
		$own = array_values( array_filter( array_map( [ self::class, 'decode_json_fields' ], $rows ?: [] ) ) );
		$own = array_values( array_filter( $own, static function ( object $stack ) use ( $book_slugs ) {
			return ! in_array( $stack->slug, $book_slugs, true );
		} ) );

		if ( ! empty( $args['game_line'] ) ) {
			$own = array_values( array_filter( $own, static fn( $s ) => $s->game_line === $args['game_line'] ) );
		}
		if ( isset( $args['is_system'] ) && $args['is_system'] !== '' ) {
			$own = array_values( array_filter( $own, static fn( $s ) => (int) $s->is_system === (int) $args['is_system'] ) );
		}
		if ( ! empty( $args['search'] ) ) {
			$needle = strtolower( (string) $args['search'] );
			$own    = array_values( array_filter( $own, static fn( $s ) => str_contains( strtolower( $s->name ), $needle ) ) );
		}

		return $own;
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
		$where = [ "game_slug = ''" ];
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

		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );

		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * How many creature types a chronicle can choose from: the book's, plus its own, regardless of which are
	 * currently enabled.
	 *
	 * @param string $game_slug
	 * @return int
	 */
	public static function total_for_game( string $game_slug ): int {
		return self::count() + count( self::own_creature_types( $game_slug, [], self::all_slugs() ) );
	}

	/**
	 * Every book slug, for excluding from a chronicle's own creature-type count.
	 *
	 * @return string[]
	 */
	private static function all_slugs(): array {
		global $wpdb;
		return $wpdb->get_col( "SELECT slug FROM " . Manager::table( 'creature_stacks' ) . " WHERE game_slug = ''" );
	}

	/**
	 * Global blocks no stack's own `stack_definition` ever references.
	 */
	private const GLOBAL_NPC_BLOCK_SLUGS = [ 'npc-roleplaying-notes', 'npc-quick-stats' ];

	/**
	 * Resolve a creature stack into its full definition as a chronicle has it: the stack row itself, plus every schema
	 * block its sections reference, hidden sections included, keyed by slug.
	 *
	 * @param string $slug
	 * @param string $game_slug
	 * @return array|null [ 'stack' => object, 'blocks' => array ] or null if not found.
	 */
	public static function resolve( string $slug, string $game_slug = '' ) {
		$stack = self::find_for_game( $slug, $game_slug );
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

		self::fill_options_ref( $blocks );

		return [
			'stack'  => $stack,
			'blocks' => $blocks,
		];
	}

	/**
	 * Fills every identity field's `options_ref` with its named block's own entry names.
	 *
	 * @param array<string,object> $blocks
	 */
	private static function fill_options_ref( array $blocks ): void {
		foreach ( $blocks as $block ) {
			if ( ( $block->section_type ?? '' ) !== 'identity_field' || ! is_object( $block->definition ?? null ) ) {
				continue;
			}
			foreach ( (array) ( $block->definition->fields ?? [] ) as $field ) {
				$ref = is_object( $field ) ? ( $field->options_ref ?? null ) : null;
				if ( is_string( $ref ) && $ref !== '' && isset( $blocks[ $ref ] ) && is_object( $blocks[ $ref ]->definition ?? null ) ) {
					$field->options = Schema_Block::entry_names( $blocks[ $ref ]->definition );
				}
			}
		}
	}

	/**
	 * Narrows an already-resolved stack for a new character: hidden sections and the blocks only they show are left
	 * out, and every `identity_field` option list is narrowed to this game's `settings.enabled_factions.{stack_slug}`
	 * restriction.
	 *
	 * @param array{stack:object,blocks:array<string,object>} $resolved
	 * @return array{stack:object,blocks:array<string,object>}
	 */
	public static function narrow_for_creation( array $resolved, string $stack_slug, string $game_slug ): array {
		if ( $game_slug === '' ) {
			return $resolved;
		}

		$closed = self::closed_blocks( $resolved['stack'] );
		if ( $closed !== [] && isset( $resolved['stack']->stack_definition->sections ) && is_array( $resolved['stack']->stack_definition->sections ) ) {
			$resolved['stack']->stack_definition->sections = array_values( array_filter(
				$resolved['stack']->stack_definition->sections,
				static fn( $section ): bool => empty( $section->hidden )
			) );
			$resolved['blocks'] = array_diff_key( $resolved['blocks'], array_flip( $closed ) );
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
	 * The blocks a stack's hidden sections show and no shown section does: closed to new purchases, open to every read.
	 *
	 * @param object|null $stack A decoded creature stack row.
	 * @return string[]
	 */
	public static function closed_blocks( $stack ): array {
		$hidden = [];
		$shown  = [];
		foreach ( ( $stack->stack_definition->sections ?? [] ) as $section ) {
			foreach ( [ 'block_slug', 'negative_block_slug' ] as $key ) {
				if ( empty( $section->$key ) || ! is_string( $section->$key ) ) {
					continue;
				}
				if ( empty( $section->hidden ) ) {
					$shown[] = $section->$key;
				} else {
					$hidden[] = $section->$key;
				}
			}
		}
		return array_values( array_unique( array_diff( $hidden, $shown ) ) );
	}

	/**
	 * The label of the first hidden section a new character's `$sheet_data` holds anything in, or null.
	 *
	 * @param object|null         $stack      A decoded creature stack row.
	 * @param array<string,mixed> $sheet_data Keyed by block_slug.
	 */
	public static function find_closed_entry( $stack, array $sheet_data ): ?string {
		$closed = self::closed_blocks( $stack );
		foreach ( $sheet_data as $block_slug => $held ) {
			if ( ! in_array( (string) $block_slug, $closed, true ) || empty( $held ) ) {
				continue;
			}
			foreach ( ( $stack->stack_definition->sections ?? [] ) as $section ) {
				if ( in_array( (string) $block_slug, [ $section->block_slug ?? null, $section->negative_block_slug ?? null ], true ) ) {
					return (string) ( $section->label ?? $block_slug );
				}
			}
			return (string) $block_slug;
		}
		return null;
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
	 * Insert a chronicle's own brand-new creature type: a row it owns outright, with no book counterpart to layer
	 * over. `find_for_game()` reads it back directly, since a layer lookup matching its own `game_slug` always wins.
	 *
	 * @param string $game_slug
	 * @param array  $data Stack data: slug, name, game_line, stack_definition, creation_rules.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function create_for_game( string $game_slug, array $data ) {
		if ( $game_slug === '' ) {
			return false;
		}
		$insert = [
			'slug'             => sanitize_title( $data['slug'] ),
			'game_slug'        => $game_slug,
			'name'             => $data['name'],
			'game_line'        => $data['game_line'] ?? 'met',
			'stack_definition' => self::encode_if_array( $data['stack_definition'] ?? [] ),
			'creation_rules'   => self::encode_if_array( $data['creation_rules'] ?? [] ),
			'is_system'        => 0,
			'created_by'       => get_current_user_id(),
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		];

		return Manager::insert( 'creature_stacks', $insert );
	}

	/**
	 * Update the book's row of a creature stack identified by slug.
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

		$result = Manager::update( 'creature_stacks', $update, [ 'slug' => $slug, 'game_slug' => '' ] );
		return $result !== false;
	}

	/**
	 * Saves a chronicle's creature stack: its layer over the book's row takes the given `stack_definition` and
	 * `creation_rules`, and records what changed, over the book's values as they now stand. A layer left changing
	 * nothing is removed.
	 *
	 * @param string              $slug
	 * @param string              $game_slug
	 * @param array<string,mixed> $data `stack_definition` and/or `creation_rules`, each whole.
	 * @return bool
	 */
	public static function update_for_game( string $slug, string $game_slug, array $data ): bool {
		$book = $game_slug !== '' ? self::find_by_slug( $slug ) : null;
		if ( ! $book ) {
			return self::update_own_creature_type( $slug, $game_slug, $data );
		}

		$layer    = self::layer_row( $slug, $game_slug );
		$under    = self::document( $book );
		$stored   = $layer ? self::document( $layer ) : $under;
		$incoming = $stored;
		foreach ( [ 'stack_definition', 'creation_rules' ] as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$incoming[ $field ] = self::as_array( $data[ $field ] );
			}
		}

		$changes = $layer ? self::recorded_changes( $layer->fork_changes, $under, $stored ) : Fork_Merge::NO_CHANGES;
		$changes = Fork_Merge::stamp( $stored, $incoming, $changes, $under );
		if ( $changes['changes'] === [] ) {
			return ! $layer || self::delete_for_game( $slug, $game_slug );
		}

		$row = self::layer_fields( $incoming, $changes, $book );
		if ( $layer ) {
			return Manager::update( 'creature_stacks', $row, [ 'id' => (int) $layer->id ] ) !== false;
		}
		return (bool) Manager::insert( 'creature_stacks', $row + [
			'slug'       => $slug,
			'game_slug'  => $game_slug,
			'name'       => $book->name,
			'game_line'  => $book->game_line,
			'is_system'  => 0,
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
		] );
	}

	/**
	 * Updates a chronicle's own creature type directly: it has no book row to layer over or diff against, so there is
	 * nothing to record as a fork change - the row itself is the only copy.
	 *
	 * @param array<string,mixed> $data `stack_definition` and/or `creation_rules`, each whole.
	 */
	private static function update_own_creature_type( string $slug, string $game_slug, array $data ): bool {
		if ( $game_slug === '' ) {
			return false;
		}
		$own = self::layer_row( $slug, $game_slug );
		if ( ! $own ) {
			return false;
		}
		$update = [];
		foreach ( [ 'stack_definition', 'creation_rules' ] as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = self::encode_if_array( $data[ $field ] );
			}
		}
		if ( empty( $update ) ) {
			return true;
		}
		$update['updated_at'] = current_time( 'mysql' );
		return Manager::update( 'creature_stacks', $update, [ 'id' => (int) $own->id ] ) !== false;
	}

	/**
	 * Rebuilds every chronicle's layer of a creature stack from the book's row as it now stands, keeping what each
	 * chronicle changed.
	 *
	 * @param string $slug
	 * @return int How many layers were rebuilt.
	 */
	public static function refresh_layers( string $slug ): int {
		$book = self::find_by_slug( $slug );
		if ( ! $book ) {
			return 0;
		}
		$under = self::document( $book );

		$rebuilt = 0;
		$layers  = Manager::get_results( 'SELECT * FROM ' . Manager::table( 'creature_stacks' ) . " WHERE slug = %s AND game_slug <> ''", $slug );
		foreach ( $layers as $layer ) {
			$copy    = self::document( $layer );
			$changes = self::recorded_changes( $layer->fork_changes, $under, $copy );
			$row     = self::layer_fields( Fork_Merge::merge( $under, $copy, $changes ), $changes, $book );
			if ( Manager::update( 'creature_stacks', $row, [ 'id' => (int) $layer->id ] ) === false ) {
				error_log( 'Beyond Elysium: failed to bring creature type changes to chronicle layer ' . (int) $layer->id );
				continue;
			}
			$rebuilt++;
		}
		return $rebuilt;
	}

	/**
	 * Writes a chronicle's layer with its recorded changes, removing it when it changes nothing.
	 *
	 * @param int                 $id       The layer's row.
	 * @param array<string,mixed> $document `stack_definition` and `creation_rules`.
	 * @param array<string,mixed> $changes
	 */
	public static function store_layer( int $id, array $document, array $changes ): bool {
		$layer = Manager::get_row( 'SELECT * FROM ' . Manager::table( 'creature_stacks' ) . " WHERE id = %d AND game_slug <> ''", $id );
		$book  = $layer ? self::find_by_slug( (string) $layer->slug ) : null;
		if ( ! $layer || ! $book ) {
			return false;
		}
		if ( ( $changes['changes'] ?? [] ) === [] ) {
			return Manager::delete( 'creature_stacks', [ 'id' => $id ] ) !== false;
		}
		return Manager::update( 'creature_stacks', self::layer_fields( $document, $changes, $book ), [ 'id' => $id ] ) !== false;
	}

	/**
	 * Hides one of a chronicle's creature stack sections: nothing new is bought in it, and what characters hold there
	 * stays readable.
	 */
	public static function hide_section( string $slug, string $game_slug, string $block_slug ): bool {
		return self::change_section( $slug, $game_slug, $block_slug, static function ( array $section ): array {
			$section['hidden'] = true;
			return $section;
		} );
	}

	/**
	 * Shows a section a chronicle hid.
	 */
	public static function show_section( string $slug, string $game_slug, string $block_slug ): bool {
		return self::change_section( $slug, $game_slug, $block_slug, static function ( array $section ): array {
			unset( $section['hidden'] );
			return $section;
		} );
	}

	/**
	 * Adds a section showing a block the chronicle can read to its creature stack, after the others, and to its full
	 * sheets.
	 *
	 * @param string $slug
	 * @param string $game_slug
	 * @param string $block_slug
	 * @param string $label The section's name; the block's own name when empty.
	 * @return bool False when the block is unknown to the chronicle or already on the stack.
	 */
	public static function add_section( string $slug, string $game_slug, string $block_slug, string $label = '' ): bool {
		$game  = $game_slug !== '' ? Game::find_by_slug( $game_slug ) : null;
		$stack = self::find_for_game( $slug, $game_slug );
		$block = Schema_Block::find_for_game( $block_slug, $game_slug );
		if ( ! $game || ! $stack || ! $block ) {
			return false;
		}
		$definition = self::as_array( $stack->stack_definition );
		$sections   = (array) ( $definition['sections'] ?? [] );
		if ( in_array( $block_slug, array_column( $sections, 'block_slug' ), true ) ) {
			return false;
		}
		$orders     = array_map( 'intval', array_column( $sections, 'display_order' ) );
		$sections[] = [
			'block_slug'    => $block_slug,
			'label'         => $label !== '' ? $label : (string) $block->name,
			'display_order' => ( $orders ? max( $orders ) : 0 ) + 1,
			'required'      => false,
		];
		$definition['sections'] = $sections;
		if ( ! self::update_for_game( $slug, $game_slug, [ 'stack_definition' => $definition ] ) ) {
			return false;
		}

		foreach ( [ 'sheet_full', 'npc_full' ] as $template_type ) {
			Template::add_section_for_game( $slug, $template_type, (int) $game->id, $block_slug, $label !== '' ? $label : (string) $block->name );
		}
		return true;
	}

	/**
	 * Removes a section a chronicle added to its creature stack, and from its sheets.
	 *
	 * @return bool False when the section is the book's or not there.
	 */
	public static function remove_section( string $slug, string $game_slug, string $block_slug ): bool {
		$game  = $game_slug !== '' ? Game::find_by_slug( $game_slug ) : null;
		$book  = self::find_by_slug( $slug );
		$stack = self::find_for_game( $slug, $game_slug );
		if ( ! $game || ! $stack ) {
			return false;
		}
		if ( $book && in_array( $block_slug, array_column( (array) ( self::as_array( $book->stack_definition )['sections'] ?? [] ), 'block_slug' ), true ) ) {
			return false;
		}
		$definition = self::as_array( $stack->stack_definition );
		$sections   = (array) ( $definition['sections'] ?? [] );
		$kept       = array_values( array_filter( $sections, static fn( $section ): bool => ( $section['block_slug'] ?? null ) !== $block_slug ) );
		if ( count( $kept ) === count( $sections ) ) {
			return false;
		}
		$definition['sections'] = $kept;
		if ( ! self::update_for_game( $slug, $game_slug, [ 'stack_definition' => $definition ] ) ) {
			return false;
		}

		foreach ( [ 'sheet_full', 'npc_full' ] as $template_type ) {
			Template::remove_section_for_game( $slug, $template_type, (int) $game->id, $block_slug );
		}
		return true;
	}

	/**
	 * Delete a creature stack identified by slug, with every chronicle's layer over it.
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
	 * Removes a chronicle's layer, so it has the book's creature stack again.
	 */
	public static function delete_for_game( string $slug, string $game_slug ): bool {
		if ( $game_slug === '' ) {
			return false;
		}
		return Manager::delete( 'creature_stacks', [ 'slug' => $slug, 'game_slug' => $game_slug ] ) !== false;
	}

	/**
	 * Changes one section of a chronicle's creature stack.
	 *
	 * @param callable(array<string,mixed>):array<string,mixed> $change
	 */
	private static function change_section( string $slug, string $game_slug, string $block_slug, callable $change ): bool {
		$stack = $game_slug !== '' ? self::find_for_game( $slug, $game_slug ) : null;
		if ( ! $stack ) {
			return false;
		}
		$definition = self::as_array( $stack->stack_definition );
		$found      = false;
		foreach ( (array) ( $definition['sections'] ?? [] ) as $i => $section ) {
			if ( is_array( $section ) && ( $section['block_slug'] ?? null ) === $block_slug ) {
				$definition['sections'][ $i ] = $change( $section );
				$found                        = true;
			}
		}
		return $found && self::update_for_game( $slug, $game_slug, [ 'stack_definition' => $definition ] );
	}

	/**
	 * A chronicle's layer row, undecoded, or null.
	 *
	 * @return object|null
	 */
	private static function layer_row( string $slug, string $game_slug ) {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'creature_stacks' ) . ' WHERE slug = %s AND game_slug = %s',
			$slug,
			$game_slug
		);
	}

	/**
	 * The part of a stack a chronicle's changes are recorded over.
	 *
	 * @param object $row A stack row, decoded or not.
	 * @return array{stack_definition:array<string,mixed>,creation_rules:array<string,mixed>}
	 */
	private static function document( object $row ): array {
		return [
			'stack_definition' => self::as_array( $row->stack_definition ?? null ),
			'creation_rules'   => self::as_array( $row->creation_rules ?? null ),
		];
	}

	/**
	 * A layer row's stored fields.
	 *
	 * @param array<string,mixed> $document
	 * @param array<string,mixed> $changes
	 * @param object              $book
	 * @return array<string,mixed>
	 */
	private static function layer_fields( array $document, array $changes, object $book ): array {
		$rules = (array) ( $document['creation_rules'] ?? [] );
		return [
			'stack_definition' => (string) wp_json_encode( (array) ( $document['stack_definition'] ?? [] ) ),
			'creation_rules'   => $rules === [] && $book->creation_rules === null ? null : (string) wp_json_encode( $rules ),
			'fork_changes'     => (string) wp_json_encode( $changes ),
			'updated_at'       => current_time( 'mysql' ),
		];
	}

	/**
	 * A layer's changes by path: the ones it recorded, or how it differs from the book's row.
	 *
	 * @param mixed               $recorded
	 * @param array<string,mixed> $under
	 * @param array<string,mixed> $copy
	 * @return array<string,mixed>
	 */
	private static function recorded_changes( $recorded, array $under, array $copy ): array {
		$changes = $recorded !== null ? self::as_array( $recorded ) : null;
		return Fork_Merge::is_by_path( $changes ) ? (array) $changes : Fork_Merge::changes_against( $under, $copy );
	}

	/**
	 * A JSON field as a plain array, whatever form it arrived in.
	 *
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	private static function as_array( $value ): array {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		} elseif ( is_object( $value ) || is_array( $value ) ) {
			$value = json_decode( (string) wp_json_encode( $value ), true );
		}
		return is_array( $value ) ? $value : [];
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
