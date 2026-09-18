<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Fork_Merge;
use BeyondElysium\Database\Manager;
use BeyondElysium\Services\Catalog_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for reusable character sheet section definitions.
 *
 * Schema_Block is a Database\Manager CRUD model backed by the schema_blocks
 * table. Each row defines one reusable sheet section - a trait list, tiered
 * power, resource pool, or identity field - identified by slug and typed by
 * section_type. A block can exist as a global/system definition (game_slug =
 * '') or as one chronicle's own forked customization of a global block,
 * identified by the same slug plus a non-empty game_slug.
 */
class Schema_Block {

	/** @var string[] Valid section types. */
	private static $valid_section_types = [ 'trait_list', 'tiered_power', 'resource_pool', 'identity_field' ];

	/**
	 * Find a schema block by slug, always the global/system definition
	 * (game_slug = ''), regardless of whether any chronicle has its own forked
	 * customization of the same slug. Use find_for_game() instead when a
	 * specific chronicle's fork should be preferred.
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
	 * Whether any row uses this slug - the global catalog or any chronicle's
	 * own block or fork.
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
	 * Find a schema block for a specific game, preferring that chronicle's own
	 * fork over the global/system block of the same slug when one exists. An
	 * empty $game_slug behaves exactly like find_by_slug(), always returning
	 * the global row.
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
	 * Return this game's own fork of a schema block identified by $slug,
	 * creating it first as a copy of the current global definition
	 * (is_system = 0) if this chronicle has never customized this block
	 * before. Never mutates the global (slug, '') row itself.
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
			// $global came from find_by_slug(), which decorates (B4) - a fresh fork must not
			// be born with _pt keys baked into its very first row, the same hazard §5.5
			// describes for an admin's own PUT, reached here by a different path (T4's own
			// scope named create()/update() only; this third site was found executing this
			// box, not in the design doc's own text).
			'definition'   => wp_json_encode( Catalog_Translator::strip( $global->definition ) ),
			'is_system'    => 0,
			// A copy of a Storyteller-only block starts Storyteller-only; the copy decides for
			// its chronicle from here on (F-062).
			'storyteller_only' => (int) ( $global->storyteller_only ?? 0 ),
			// A fresh copy has changed nothing yet; its saves record what they change (F-034).
			'fork_changes' => wp_json_encode( Fork_Merge::NO_CHANGES ),
			'version'      => 1,
			'created_by'   => get_current_user_id(),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		] );

		// Never the catalog block in the copy's place: a save onto it would land nowhere
		// (1.0.0-review F-109). An insert that lost to another request making the same copy
		// finds that copy.
		$fork = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'schema_blocks' ) . ' WHERE slug = %s AND game_slug = %s',
			$slug,
			$game_slug
		);
		return $fork ? self::decode_definition( $fork ) : null;
	}

	/**
	 * Look up a single schema block by its primary key, whether it is a
	 * global definition or a chronicle's fork. Returns the row with its
	 * definition field decoded, or null when no block with that ID exists.
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
	 * Return the global/system schema block catalog matching optional
	 * filters. Supports filtering by section_type, system flag, and name
	 * search, plus pagination and sort order. Never includes a chronicle's
	 * own forks; see all_for_game() for that.
	 *
	 * @param array $args Filters: section_type, is_system, search, orderby, order, per_page, offset.
	 * @return array
	 */
	public static function all( array $args = [] ): array {
		global $wpdb;
		$table = Manager::table( 'schema_blocks' );
		// Global/system catalog only; a chronicle's own forks are listed via all_for_game() instead.
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

		// $where can never be empty now - it always has at least the game_slug filter.
		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where );

		// The ?? default must apply in both branches or an unset orderby breaks the query.
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
	 * Count global/system schema blocks matching the given filters. Accepts
	 * the same section_type, is_system, and search filters as all(), without
	 * pagination, and returns a plain integer total.
	 *
	 * @param array $args Same filters as all().
	 * @return int
	 */
	public static function count( array $args = [] ): int {
		global $wpdb;
		$table = Manager::table( 'schema_blocks' );
		// Same "global catalog only" default as all() above.
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

		// $where can never be empty now - it always has at least the game_slug filter.
		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );

		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Return the global catalog with any of this chronicle's own forks
	 * substituted in place of the global block of the same slug. A block this
	 * chronicle has never forked still shows its global version, unchanged.
	 * Accepts the same filters as all().
	 *
	 * @param array  $args
	 * @param string $game_slug
	 * @return array
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
	 * Same merge behavior as all_for_game(), for one or more section types
	 * at once, in no particular order.
	 *
	 * A handful of real system blocks (the Fera/Werewolf gift catalogs,
	 * `mage-rotes` since the Enlightened Grimoire expansion) run hundreds of
	 * KB each. Any ORDER BY here - even by the primary key -
	 * measurably fails with "Out of sort memory" once section_type is also
	 * filtered: EXPLAIN shows MySQL choosing the section_type index as its
	 * access path, which does not return primary-key order, so an explicit
	 * sort step is still needed and overflows a real (measured, not
	 * assumed) 262144-byte sort_buffer_size against these row widths.
	 * Omitting ORDER BY avoids the sort step entirely - confirmed via
	 * EXPLAIN losing "Using filesort" once removed. Safe for a caller (such
	 * as an approval-rules listing) that does not need blocks in a
	 * particular order.
	 *
	 * @param string[] $section_types
	 * @param string   $game_slug
	 * @return array
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
	 * Find multiple schema blocks by a list of slugs, always the
	 * global/system definitions regardless of any chronicle forks. Returns
	 * an empty array immediately when $slugs is empty.
	 *
	 * @param array $slugs
	 * @return array Keyed by slug.
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
	 * Batched version of find_for_game() for multiple slugs at once: one
	 * query for the global rows, one for the requesting game's own forks,
	 * with the fork winning per slug wherever both exist. Keyed by slug, one
	 * entry per slug asked for.
	 *
	 * @param array  $slugs
	 * @param string $game_slug
	 * @return array Keyed by slug.
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
	 * Insert a new schema block. Validates section_type against the known
	 * list, sanitizes the slug, JSON-encodes the definition, and defaults
	 * game_slug to '' (the global catalog) when not supplied.
	 *
	 * @param array $data Block data.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function create( array $data ) {
		if ( ! in_array( $data['section_type'] ?? '', self::$valid_section_types, true ) ) {
			return false;
		}

		// §5.5's strip() guards against a caller echoing a DECORATED read back as a write -
		// found live, an earlier version of this method applied it unconditionally and
		// silently erased Seeder's own CSV-sourced name_pt on every reseed, which would have
		// destroyed the pre-1.2.0 Portuguese localization work (Decision 106/107) the moment
		// a production install's maybe_upgrade() next ran. B9 retired that CSV-sourced write
		// path entirely, so strip() applies unconditionally here now - there is no longer a
		// legitimate writer for strip() to guard against.
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
	 * Update a schema block identified by slug and game_slug, incrementing
	 * its version counter. $game_slug defaults to '' (the global block);
	 * since slug alone is not unique, omitting it would otherwise risk
	 * matching more than one chronicle's fork of the same slug. A new
	 * definition for a chronicle's copy also records what it changes, so a
	 * later catalog update keeps it (1.0.0-review F-034).
	 *
	 * @param string $slug
	 * @param array  $data      Fields to update.
	 * @param string $game_slug
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

		// strip() before anything else touches $update['definition'] - both the fork-diff
		// computation just below and the final encode must see the same clean definition, or
		// a _pt key a caller echoed back could survive into fork_changes even if the
		// definition column itself were later cleaned (§5.5, T4).
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
	 * What a chronicle's copy will have changed once this definition is saved:
	 * what it had recorded - or, for a copy made before copies recorded their
	 * changes, how it differs from the catalog - plus what this save changes.
	 * Null when there's no such copy.
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

		$stored   = self::as_array( $row->definition );
		$incoming = self::as_array( $incoming );
		$changes  = $row->fork_changes !== null ? self::as_array( $row->fork_changes ) : null;
		if ( $changes === null ) {
			$shared  = Manager::get_var( "SELECT definition FROM {$table} WHERE slug = %s AND game_slug = ''", $slug );
			$changes = Fork_Merge::changes_against( self::as_array( $shared ), $stored );
		}

		return Fork_Merge::stamp( $stored, $incoming, $changes );
	}

	/**
	 * Rebuilds every chronicle's copy of a catalog block from the catalog
	 * block as it now stands, keeping what each chronicle changed (1.0.0-review
	 * F-034). Run after the catalog block changes - a plugin update's reseed,
	 * or an administrator's save - so a catalog fix reaches every chronicle.
	 * Records nothing: none of it is a chronicle's own change.
	 *
	 * @param string $slug
	 * @return int How many copies were rebuilt.
	 */
	public static function refresh_forks( string $slug ): int {
		global $wpdb;
		$table  = Manager::table( 'schema_blocks' );
		$shared = Manager::get_var( "SELECT definition FROM {$table} WHERE slug = %s AND game_slug = ''", $slug );
		if ( $shared === null ) {
			return 0;
		}
		$catalog = self::as_array( $shared );

		$rebuilt = 0;
		$copies  = Manager::get_results( "SELECT id, definition, fork_changes FROM {$table} WHERE slug = %s AND game_slug <> ''", $slug );
		foreach ( $copies as $copy ) {
			$definition = self::as_array( $copy->definition );
			$changes    = $copy->fork_changes !== null ? self::as_array( $copy->fork_changes ) : Fork_Merge::changes_against( $catalog, $definition );
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
	 * Delete a schema block identified by slug and game_slug. Refuses to
	 * delete a block flagged is_system, returning false rather than removing
	 * a seeded default. $game_slug defaults to '' (the global block).
	 *
	 * @param string $slug
	 * @param string $game_slug
	 * @return bool
	 */
	public static function delete( string $slug, string $game_slug = '' ): bool {
		// Exact match only - not find_for_game()'s fallback - describes exactly the row being deleted.
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
	 * Used by callers that need to validate or render these values without
	 * duplicating the list.
	 *
	 * @return string[]
	 */
	public static function valid_section_types(): array {
		return self::$valid_section_types;
	}

	/**
	 * Return the slugs of every block that is Storyteller-only in one
	 * chronicle. Callers use this to strip Storyteller-only content from a
	 * response bound for a viewer without `be_manage_characters`.
	 *
	 * Resolved the way `find_for_game()` resolves a block: the chronicle's own
	 * copy decides for that chronicle, the shared block decides where it has
	 * none, and no other chronicle's copy counts. Asked for the whole install,
	 * one chronicle hiding its own copy hid that block from every chronicle's
	 * players (1.0.0-review F-062).
	 *
	 * Deliberately not memoized: a static cache latches the first result for
	 * the whole PHP process, which is wrong the moment a block's flag
	 * changes and is invisible until a full-suite test run catches it.
	 * A caller looping over many characters hoists this call out of the loop
	 * instead.
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
	 * Decode a row's definition JSON field into an object in place, and cast
	 * its tinyint flags to real integers. Passes null rows through unchanged,
	 * and leaves a non-string definition value untouched.
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
		// $wpdb returns "0"/"1" strings, and "0" is truthy in JavaScript - uncast, the Schema
		// Blocks editor saved every block it opened as storyteller_only (D53, D51's class).
		// Cast to a real bool, not 0/1: a JSON boolean cannot be a truthy "0", so the whole
		// bug class stops being expressible (owner ruling 2026-09-16, 1.0.1-workflow.md B1).
		foreach ( [ 'is_system', 'storyteller_only' ] as $flag ) {
			if ( isset( $row->$flag ) ) {
				$row->$flag = (bool) $row->$flag;
			}
		}

		// The single choke point every read of a block passes through - find_by_slug(),
		// find_by_slugs(), find_by_slugs_for_game() all route here, so this is the one place
		// that needs to add display translations for the sheet, the admin editors, reports
		// and the PDF writer all to get them without a second wiring point (1.2.0
		// releases/1.2.0-design-workflow.md §5.2). One install, one language (Decision 106) -
		// get_locale() needs no request-context threading, matching how Plugin.php and
		// Admin_Menu.php already surface the site locale to the client.
		if ( isset( $row->section_type, $row->definition ) && is_object( $row->definition ) ) {
			Catalog_Translator::decorate( $row, get_locale() );
		}

		return $row;
	}
}
