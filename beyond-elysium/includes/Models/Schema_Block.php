<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Fork_Merge;
use BeyondElysium\Database\Manager;
use BeyondElysium\Services\Catalog_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for reusable character sheet section definitions.
 */
class Schema_Block {

	/** @var string[] Valid section types. */
	private static $valid_section_types = [ 'trait_list', 'tiered_power', 'resource_pool', 'identity_field' ];

	/**
	 * The option holding each base block's variants, by base slug, as the seeder read them from the catalog.
	 */
	const VARIANTS_OPTION = 'be_catalog_variants';

	/**
	 * Find a schema block by slug, always the global/system definition (game_slug = ''), regardless of whether any
	 * chronicle has its own forked customization of the same slug.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public static function find_by_slug( string $slug ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'schema_blocks' ) . " WHERE slug = %s AND game_slug = ''",
			$slug
		);
		return self::decode_definition( $row );
	}

	/**
	 * Whether any row uses this slug.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function slug_in_use( string $slug ): bool {
		return (bool) Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'schema_blocks' ) . ' WHERE slug = %s',
			$slug
		);
	}

	/**
	 * Find a schema block for a specific game, preferring that chronicle's own fork over the global/system block of the
	 * same slug when one exists.
	 *
	 * @param string $slug
	 * @param string $game_slug
	 * @return object|null
	 */
	public static function find_for_game( string $slug, string $game_slug = '' ) {
		if ( $game_slug === '' ) {
			return self::find_by_slug( $slug );
		}

		$table = Manager::table( 'schema_blocks' );
		$row   = Manager::get_row(
			"SELECT * FROM {$table} WHERE slug = %s AND game_slug = %s",
			$slug,
			$game_slug
		);
		if ( $row ) {
			return self::decode_definition( $row );
		}

		return self::find_by_slug( $slug );
	}

	/**
	 * Returns this game's own fork of a schema block identified by $slug, creating it first as a copy of the current
	 * global definition if this chronicle has never customized the block.
	 *
	 * @param string $slug
	 * @param string $game_slug Must not be ''.
	 * @return object|null Null if `$slug` doesn't exist at all, even globally, or the copy couldn't be written.
	 */
	public static function find_or_create_fork_for_game( string $slug, string $game_slug ) {
		if ( $game_slug === '' ) {
			return null;
		}

		$existing_fork = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'schema_blocks' ) . ' WHERE slug = %s AND game_slug = %s',
			$slug,
			$game_slug
		);
		if ( $existing_fork ) {
			return self::decode_definition( $existing_fork );
		}

		$global = self::find_by_slug( $slug );
		if ( ! $global ) {
			return null;
		}

		Manager::insert( 'schema_blocks', [
			'slug'         => $slug,
			'game_slug'    => $game_slug,
			'name'         => $global->name,
			'section_type' => $global->section_type,
			'definition'   => wp_json_encode( self::under_for_game( $slug, $game_slug ) ),
			'is_system'    => 0,
			// A copy of a Storyteller-only block starts Storyteller-only.
			'storyteller_only' => (int) ( $global->storyteller_only ?? 0 ),
			// A fresh copy has changed nothing yet.
			'fork_changes' => wp_json_encode( Fork_Merge::NO_CHANGES ),
			'version'      => 1,
			'created_by'   => get_current_user_id(),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		] );

		$fork = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'schema_blocks' ) . ' WHERE slug = %s AND game_slug = %s',
			$slug,
			$game_slug
		);
		return $fork ? self::decode_definition( $fork ) : null;
	}

	/**
	 * Look up a single schema block by its primary key, whether it is a global definition or a chronicle's fork.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'schema_blocks' ) . ' WHERE id = %d',
			$id
		);
		return self::decode_definition( $row );
	}

	/**
	 * Return the global/system schema block catalog matching optional filters.
	 *
	 * @param array<string,mixed> $args Filters: section_type, is_system, search, orderby, order, per_page, offset.
	 * @return array<int,object>
	 */
	public static function all( array $args = [] ): array {
		global $wpdb;
		$table = Manager::table( 'schema_blocks' );
		// Global/system catalog only.
		$where  = [ "game_slug = ''" ];
		$values = [];

		if ( ! empty( $args['section_type'] ) ) {
			$where[] = 'section_type = %s';
			$values[] = $args['section_type'];
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

		$orderby = in_array( $args['orderby'] ?? 'name', [ 'name', 'slug', 'section_type', 'created_at' ], true )
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
		return array_map( [ self::class, 'decode_row' ], $rows );
	}

	/**
	 * Count global/system schema blocks matching the given filters.
	 *
	 * @param array<string,mixed> $args Same filters as all().
	 * @return int
	 */
	public static function count( array $args = [] ): int {
		global $wpdb;
		$table = Manager::table( 'schema_blocks' );
		// Global catalog only, as in all().
		$where  = [ "game_slug = ''" ];
		$values = [];

		if ( ! empty( $args['section_type'] ) ) {
			$where[] = 'section_type = %s';
			$values[] = $args['section_type'];
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
	 * Return the global catalog with any of this chronicle's own forks substituted in place of the global block of the
	 * same slug.
	 *
	 * @param array<string,mixed> $args
	 * @param string              $game_slug
	 * @return array<int,object>
	 */
	public static function all_for_game( array $args, string $game_slug ): array {
		$blocks = self::all( $args );
		if ( $game_slug === '' ) {
			return $blocks;
		}

		global $wpdb;
		$table = Manager::table( 'schema_blocks' );
		$forks = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE game_slug = %s", $game_slug )
		) ?: [];

		foreach ( $forks as $fork ) {
			$fork = self::decode_row( $fork );
			foreach ( $blocks as $i => $block ) {
				if ( $block->slug === $fork->slug ) {
					$blocks[ $i ] = $fork;
					continue 2;
				}
			}
			// A fork whose global block was filtered out is not added in on its own.
		}

		return $blocks;
	}

	/**
	 * Same merge behavior as all_for_game(), for one or more section types at once, in no particular order.
	 *
	 * @param string[] $section_types
	 * @param string   $game_slug
	 * @return array<int,object>
	 */
	public static function all_for_game_by_types( array $section_types, string $game_slug ): array {
		global $wpdb;
		$table = Manager::table( 'schema_blocks' );

		$placeholders = implode( ', ', array_fill( 0, count( $section_types ), '%s' ) );
		$globals = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE game_slug = '' AND section_type IN ({$placeholders})",
			$section_types
		) ) ?: [];
		$blocks = array_map( [ self::class, 'decode_row' ], $globals );

		if ( $game_slug === '' ) {
			return $blocks;
		}

		$forks = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE game_slug = %s AND section_type IN ({$placeholders})",
			array_merge( [ $game_slug ], $section_types )
		) ) ?: [];

		foreach ( $forks as $fork ) {
			$fork = self::decode_row( $fork );
			foreach ( $blocks as $i => $block ) {
				if ( $block->slug === $fork->slug ) {
					$blocks[ $i ] = $fork;
					continue 2;
				}
			}
		}

		return $blocks;
	}

	/**
	 * A chronicle's own forks of the given section types and nothing else: no global block is read.
	 *
	 * @param string[] $section_types
	 * @param string   $game_slug
	 * @return array<int,object>
	 */
	public static function forks_for_game_by_types( array $section_types, string $game_slug ): array {
		if ( $game_slug === '' || $section_types === [] ) {
			return [];
		}

		global $wpdb;
		$table        = Manager::table( 'schema_blocks' );
		$placeholders = implode( ', ', array_fill( 0, count( $section_types ), '%s' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE game_slug = %s AND section_type IN ({$placeholders})",
			array_merge( [ $game_slug ], $section_types )
		) ) ?: [];

		return array_map( [ self::class, 'decode_row' ], $rows );
	}

	/**
	 * Find multiple schema blocks by a list of slugs, always the global/system definitions regardless of any chronicle
	 * forks.
	 *
	 * @param array<int,string> $slugs
	 * @return array<string,object> Keyed by slug.
	 */
	public static function find_by_slugs( array $slugs ): array {
		if ( empty( $slugs ) ) {
			return [];
		}
		global $wpdb;
		$table = Manager::table( 'schema_blocks' );
		$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE slug IN ({$placeholders}) AND game_slug = ''",
			$slugs
		);
		$rows = $wpdb->get_results( $sql ) ?: [];
		$result = [];
		foreach ( $rows as $row ) {
			$row = self::decode_row( $row );
			$result[ $row->slug ] = $row;
		}
		return $result;
	}

	/**
	 * Batched version of find_for_game() for multiple slugs at once: one query for the global rows, one for the
	 * requesting game's own forks, with the fork winning per slug wherever both exist.
	 *
	 * @param array<int,string> $slugs
	 * @param string            $game_slug
	 * @return array<string,object> Keyed by slug.
	 */
	public static function find_by_slugs_for_game( array $slugs, string $game_slug = '' ): array {
		$base = self::find_by_slugs( $slugs );
		if ( $game_slug === '' || empty( $slugs ) ) {
			return $base;
		}

		global $wpdb;
		$table        = Manager::table( 'schema_blocks' );
		$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$sql          = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE slug IN ({$placeholders}) AND game_slug = %s",
			array_merge( $slugs, [ $game_slug ] )
		);
		$rows = $wpdb->get_results( $sql ) ?: [];
		foreach ( $rows as $row ) {
			$row          = self::decode_row( $row );
			$base[ $row->slug ] = $row;
		}
		return $base;
	}

	/**
	 * Insert a new schema block.
	 *
	 * @param array<string,mixed> $data Block data.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function create( array $data ) {
		if ( ! in_array( $data['section_type'] ?? '', self::$valid_section_types, true ) ) {
			return false;
		}

		$definition = $data['definition'] ?? null;
		if ( is_array( $definition ) || is_object( $definition ) ) {
			$definition = Catalog_Translator::strip( $definition );
		}

		$insert = [
			'slug'         => sanitize_title( $data['slug'] ),
			// '' (never null) means the global/system catalog.
			'game_slug'    => $data['game_slug'] ?? '',
			'name'         => $data['name'],
			'section_type' => $data['section_type'],
			'definition'   => is_array( $definition ) || is_object( $definition )
				? wp_json_encode( $definition )
				: ( $definition ?? '{}' ),
			'is_system'    => (int) ( $data['is_system'] ?? 0 ),
			'storyteller_only' => (int) ( $data['storyteller_only'] ?? 0 ),
			'version'      => 1,
			'created_by'   => $data['created_by'] ?? get_current_user_id(),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		];

		return Manager::insert( 'schema_blocks', $insert );
	}

	/**
	 * Update a schema block identified by slug and game_slug, incrementing its version counter. $game_slug defaults to ''
	 * (the global block).
	 *
	 * @param string              $slug
	 * @param array<string,mixed> $data      Fields to update.
	 * @param string              $game_slug
	 * @return bool
	 */
	public static function update( string $slug, array $data, string $game_slug = '' ): bool {
		global $wpdb;
		$allowed = [ 'name', 'section_type', 'definition', 'storyteller_only' ];
		$update = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		if ( isset( $update['definition'] ) && ( is_array( $update['definition'] ) || is_object( $update['definition'] ) ) ) {
			$update['definition'] = Catalog_Translator::strip( $update['definition'] );
		}

		if ( $game_slug !== '' && isset( $update['definition'] ) ) {
			$changes = self::changes_for_save( $slug, $game_slug, $update['definition'] );
			if ( $changes !== null ) {
				$update['fork_changes'] = wp_json_encode( $changes );
			}
		}

		if ( isset( $update['definition'] ) && ( is_array( $update['definition'] ) || is_object( $update['definition'] ) ) ) {
			$update['definition'] = wp_json_encode( $update['definition'] );
		}

		if ( isset( $update['section_type'] ) && ! in_array( $update['section_type'], self::$valid_section_types, true ) ) {
			return false;
		}

		if ( isset( $update['storyteller_only'] ) ) {
			$update['storyteller_only'] = $update['storyteller_only'] ? 1 : 0;
		}

		$update['updated_at'] = current_time( 'mysql' );

		// Atomic version increment via raw SQL.
		$table = Manager::table( 'schema_blocks' );
		$set_parts = [];
		$values = [];
		foreach ( $update as $col => $val ) {
			$set_parts[] = "{$col} = %s";
			$values[] = $val;
		}
		$set_parts[] = 'version = version + 1';
		$set_sql = implode( ', ', $set_parts );
		$values[] = $slug;
		$values[] = $game_slug;

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET {$set_sql} WHERE slug = %s AND game_slug = %s",
			$values
		);

		return $wpdb->query( $sql ) !== false;
	}

	/**
	 * What a chronicle's copy will have changed once this definition is saved, over the catalog block as it now stands.
	 * Null for a block with no catalog block beneath it.
	 *
	 * @param string       $slug
	 * @param string       $game_slug
	 * @param mixed        $incoming The definition being saved: array, object, or JSON.
	 * @return array<string,mixed>|null
	 */
	private static function changes_for_save( string $slug, string $game_slug, $incoming ): ?array {
		$table = Manager::table( 'schema_blocks' );
		$row   = Manager::get_row( "SELECT definition, fork_changes FROM {$table} WHERE slug = %s AND game_slug = %s", $slug, $game_slug );
		if ( ! $row ) {
			return null;
		}
		if ( ! self::find_by_slug( $slug ) ) {
			return null;
		}

		$catalog = self::under_for_game( $slug, $game_slug );
		$stored  = self::as_array( $row->definition );
		return Fork_Merge::stamp( $stored, self::as_array( $incoming ), self::recorded_changes( $row->fork_changes, $catalog, $stored ), $catalog );
	}

	/**
	 * A copy's changes by path: the ones it recorded, or, when it recorded none by path, how it differs from the catalog
	 * block.
	 *
	 * @param mixed               $recorded The copy's stored change record.
	 * @param array<string,mixed> $catalog  The catalog definition.
	 * @param array<string,mixed> $copy     The chronicle's copy.
	 * @return array<string,mixed>
	 */
	private static function recorded_changes( $recorded, array $catalog, array $copy ): array {
		$changes = $recorded !== null ? self::as_array( $recorded ) : null;
		return Fork_Merge::is_by_path( $changes ) ? (array) $changes : Fork_Merge::changes_against( $catalog, $copy );
	}

	/**
	 * Rebuilds every chronicle's copy of a catalog block from the catalog block as it now stands, keeping what each
	 * chronicle changed.
	 *
	 * @param string $slug
	 * @return int How many copies were rebuilt.
	 */
	public static function refresh_forks( string $slug ): int {
		global $wpdb;
		$table  = Manager::table( 'schema_blocks' );
		$shared = Manager::get_var( "SELECT COUNT(*) FROM {$table} WHERE slug = %s AND game_slug = ''", $slug );
		if ( (int) $shared === 0 ) {
			return 0;
		}

		$rebuilt = 0;
		$copies  = Manager::get_results( "SELECT id, game_slug, definition, fork_changes FROM {$table} WHERE slug = %s AND game_slug <> ''", $slug );
		foreach ( $copies as $copy ) {
			$catalog    = self::under_for_game( $slug, (string) $copy->game_slug );
			$definition = self::as_array( $copy->definition );
			$changes    = self::recorded_changes( $copy->fork_changes, $catalog, $definition );
			$merged     = Fork_Merge::merge( $catalog, $definition, $changes );

			$result = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET definition = %s, fork_changes = %s, updated_at = %s, version = version + 1 WHERE id = %d",
				wp_json_encode( $merged ),
				wp_json_encode( $changes ),
				current_time( 'mysql' ),
				(int) $copy->id
			) );
			if ( $result === false ) {
				error_log( 'Beyond Elysium: failed to bring catalog changes to schema block copy ' . (int) $copy->id . ': ' . $wpdb->last_error );
				continue;
			}
			$rebuilt++;
		}
		return $rebuilt;
	}

	/**
	 * Writes a chronicle's copy of a catalog block with its recorded changes.
	 *
	 * @param int                 $id         The copy's row.
	 * @param array<string,mixed> $definition
	 * @param array<string,mixed> $changes
	 */
	public static function store_copy( int $id, array $definition, array $changes ): bool {
		global $wpdb;
		$table = Manager::table( 'schema_blocks' );
		return $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET definition = %s, fork_changes = %s, updated_at = %s, version = version + 1 WHERE id = %d AND game_slug <> ''",
			wp_json_encode( $definition ),
			wp_json_encode( $changes ),
			current_time( 'mysql' ),
			$id
		) ) !== false;
	}

	/**
	 * The definition beneath a chronicle's copy of a block: the book's, replaced by the variant the chronicle chose to
	 * replace it with, then with each variant it chose to add folded in, in its order. The book's alone for the site or
	 * a chronicle that chose none.
	 *
	 * @param string $slug
	 * @param string $game_slug
	 * @return array<string,mixed>
	 */
	public static function under_for_game( string $slug, string $game_slug ): array {
		return self::under_with( $slug, self::chosen_variants( $slug, $game_slug ) );
	}

	/**
	 * The book's definition of a block with these variants applied: a replacing one first takes its place, then each
	 * adding one is folded in, in order.
	 *
	 * @param string                                                      $slug
	 * @param array<int,array{slug:string,id:string,label:string,mode:string}> $variants
	 * @return array<string,mixed>
	 */
	public static function under_with( string $slug, array $variants ): array {
		$table = Manager::table( 'schema_blocks' );
		$book  = Manager::get_row( "SELECT section_type, definition FROM {$table} WHERE slug = %s AND game_slug = ''", $slug );
		if ( ! $book ) {
			return [];
		}
		$definition = self::as_array( $book->definition );
		foreach ( $variants as $variant ) {
			$content = self::as_array( Manager::get_var( "SELECT definition FROM {$table} WHERE slug = %s AND game_slug = ''", $variant['slug'] ) );
			unset( $content['_variant'] );
			$definition = $variant['mode'] === 'replace'
				? $content
				: \BeyondElysium\Services\Catalog_Reader::merge_add_variant( (string) $book->section_type, $definition, $content );
		}
		return $definition;
	}

	/**
	 * Blocks' names with the creature type each belongs to, such as "Vampire Disciplines": the book creature type whose
	 * slug begins the block's, left off when the name already starts with it or no creature type's slug begins it.
	 *
	 * @param array<string,string> $names Names by block slug.
	 * @return array<string,string> By block slug.
	 */
	public static function names_with_creature( array $names ): array {
		$creatures = [];
		foreach ( Creature_Stack::all() as $stack ) {
			$creatures[ (string) $stack->slug ] = (string) $stack->name;
		}
		$labels = [];
		foreach ( $names as $slug => $name ) {
			$slug  = (string) $slug;
			$owner = '';
			foreach ( array_keys( $creatures ) as $creature ) {
				if ( str_starts_with( $slug, $creature . '-' ) && strlen( $creature ) > strlen( $owner ) ) {
					$owner = $creature;
				}
			}
			$labels[ $slug ] = $owner === '' || stripos( $name, $creatures[ $owner ] ) === 0
				? $name
				/* translators: 1: a creature type, such as Vampire, 2: one of its blocks, such as Disciplines */
				: sprintf( __( '%1$s %2$s', 'beyond-elysium' ), $creatures[ $owner ], $name );
		}
		return $labels;
	}

	/**
	 * A block's own families or items, by name: `powers` for a tiered_power block, `items` for a trait_list one, empty
	 * for anything else.
	 *
	 * @param object $definition
	 * @return string[]
	 */
	public static function entry_names( object $definition ): array {
		$entries = $definition->powers ?? $definition->items ?? [];
		$names   = [];
		foreach ( (array) $entries as $entry ) {
			$name = is_object( $entry ) ? ( $entry->name ?? null ) : ( $entry['name'] ?? null );
			if ( is_string( $name ) && $name !== '' ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * The variants a base block has, in the catalog's order.
	 *
	 * @param string $slug The base block's slug.
	 * @return array<int,array{slug:string,id:string,label:string,mode:string}>
	 */
	public static function variants_of( string $slug ): array {
		$variants = get_option( self::VARIANTS_OPTION, [] );
		return is_array( $variants ) && isset( $variants[ $slug ] ) && is_array( $variants[ $slug ] ) ? array_values( $variants[ $slug ] ) : [];
	}

	/**
	 * The variants a chronicle chose for a base block, the one replacing it first, then the ones adding to it in the
	 * chronicle's order; unknown ids are left out.
	 *
	 * @param string $slug      The base block's slug.
	 * @param string $game_slug
	 * @return array<int,array{slug:string,id:string,label:string,mode:string}>
	 */
	public static function chosen_variants( string $slug, string $game_slug ): array {
		if ( $game_slug === '' ) {
			return [];
		}
		$game = Game::find_by_slug( $game_slug );
		$ids  = $game ? (array) ( $game->settings->catalog_variants->$slug ?? [] ) : [];
		if ( $ids === [] ) {
			return [];
		}
		$by_id = [];
		foreach ( self::variants_of( $slug ) as $variant ) {
			$by_id[ $variant['id'] ] = $variant;
		}
		$chosen = [];
		foreach ( $ids as $id ) {
			if ( is_string( $id ) && isset( $by_id[ $id ] ) ) {
				$chosen[] = $by_id[ $id ];
			}
		}
		usort( $chosen, static fn( array $a, array $b ): int => ( $a['mode'] === 'replace' ? 0 : 1 ) <=> ( $b['mode'] === 'replace' ? 0 : 1 ) );
		return $chosen;
	}

	/**
	 * Rebuilds one chronicle's copy of a block from the definition beneath it as it now stands, keeping what the
	 * chronicle changed.
	 *
	 * @param string $slug
	 * @param string $game_slug
	 * @return bool False when the chronicle has no copy or it could not be written.
	 */
	public static function refresh_fork_for_game( string $slug, string $game_slug ): bool {
		$copy = Manager::get_row( 'SELECT id, definition, fork_changes FROM ' . Manager::table( 'schema_blocks' ) . ' WHERE slug = %s AND game_slug = %s', $slug, $game_slug );
		if ( ! $copy || $game_slug === '' ) {
			return false;
		}
		$under      = self::under_for_game( $slug, $game_slug );
		$definition = self::as_array( $copy->definition );
		$changes    = self::recorded_changes( $copy->fork_changes, $under, $definition );
		return self::store_copy( (int) $copy->id, Fork_Merge::merge( $under, $definition, $changes ), $changes );
	}

	/**
	 * A chronicle's copy of a block as stored, with its changes by path, or null when it has none.
	 *
	 * @param string $slug
	 * @param string $game_slug
	 * @return array{id:int,definition:array<string,mixed>,changes:array<string,mixed>}|null
	 */
	public static function copy_for_game( string $slug, string $game_slug ): ?array {
		$copy = Manager::get_row( 'SELECT id, definition, fork_changes FROM ' . Manager::table( 'schema_blocks' ) . ' WHERE slug = %s AND game_slug = %s', $slug, $game_slug );
		if ( ! $copy || $game_slug === '' ) {
			return null;
		}
		$definition = self::as_array( $copy->definition );
		return [
			'id'         => (int) $copy->id,
			'definition' => $definition,
			'changes'    => self::recorded_changes( $copy->fork_changes, self::under_for_game( $slug, $game_slug ), $definition ),
		];
	}

	/**
	 * A definition or change record as a plain array, whatever form it arrived in.
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
	 * Delete a schema block identified by slug and game_slug.
	 *
	 * @param string $slug
	 * @param string $game_slug
	 * @return bool
	 */
	public static function delete( string $slug, string $game_slug = '' ): bool {
		$block = self::decode_definition( Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'schema_blocks' ) . ' WHERE slug = %s AND game_slug = %s',
			$slug,
			$game_slug
		) );
		if ( ! $block ) {
			return false;
		}
		if ( ! empty( $block->is_system ) ) {
			return false;
		}
		$result = Manager::delete( 'schema_blocks', [ 'slug' => $slug, 'game_slug' => $game_slug ] );
		return $result !== false;
	}

	/**
	 * Return the list of section_type strings a schema block may declare.
	 *
	 * @return string[]
	 */
	public static function valid_section_types(): array {
		return self::$valid_section_types;
	}

	/**
	 * Return the slugs of every block that is Storyteller-only in one chronicle.
	 *
	 * @param string $game_slug The chronicle whose view this is; '' for the shared blocks alone.
	 * @return string[]
	 */
	public static function storyteller_only_slugs( string $game_slug ): array {
		global $wpdb;

		$table = Manager::table( 'schema_blocks' );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT slug, game_slug, storyteller_only FROM {$table} WHERE game_slug = '' OR game_slug = %s",
			$game_slug
		) ) ?: [];

		$shared = [];
		$own    = [];
		foreach ( $rows as $row ) {
			if ( $row->game_slug === '' ) {
				$shared[ (string) $row->slug ] = (int) $row->storyteller_only;
			} else {
				$own[ (string) $row->slug ] = (int) $row->storyteller_only;
			}
		}

		return array_map( 'strval', array_keys( array_filter( $own + $shared ) ) );
	}

	/**
	 * Decode a row's definition JSON field into an object in place, and cast its tinyint flags to real integers.
	 *
	 * @param object|null $row
	 * @return object|null
	 */
	private static function decode_definition( $row ) {
		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * decode_definition() for a row already known to exist.
	 *
	 * @param object $row
	 * @return object
	 */
	private static function decode_row( object $row ): object {
		if ( isset( $row->definition ) && is_string( $row->definition ) ) {
			$row->definition = json_decode( $row->definition );
		}
		if ( isset( $row->fork_changes ) && is_string( $row->fork_changes ) ) {
			$row->fork_changes = json_decode( $row->fork_changes );
		}
		foreach ( [ 'is_system', 'storyteller_only' ] as $flag ) {
			if ( isset( $row->$flag ) ) {
				$row->$flag = (bool) $row->$flag;
			}
		}

		// The single choke point every read of a block passes through.
		if ( isset( $row->section_type, $row->definition ) && is_object( $row->definition ) ) {
			Catalog_Translator::decorate( $row, get_locale() );
		}

		return $row;
	}
}
