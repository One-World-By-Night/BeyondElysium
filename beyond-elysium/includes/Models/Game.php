<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Core\Game_Slug_References;
use BeyondElysium\REST\Game_Stats_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for chronicles (games).
 *
 * Game is a Database\Manager CRUD model backed by the games table. Each row is
 * one chronicle - name, slug, game_type, a JSON settings blob, and ASC role
 * path/notification configuration. delete_with_content() additionally cascades
 * a deletion to everything stored under the chronicle, and nothing else creates,
 * renames, or deletes a chronicle in a way that leaves that content for another
 * chronicle to inherit (1.0.0-review F-036).
 */
class Game {

	/**
	 * Look up a single game by its slug. Returns the row with its settings
	 * field decoded into an object, or null when no game with that slug
	 * exists.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public static function find_by_slug( string $slug ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'games' ) . ' WHERE slug = %s',
			$slug
		);
		return $row ? self::decode_settings( $row ) : null;
	}

	/**
	 * Hold a chronicle's row until the surrounding transaction ends, so two
	 * writes that must not both pass the same check in one chronicle take
	 * turns. False when the lock itself failed - a lock wait that timed out -
	 * so the caller writes nothing unguarded. Must run inside a Transaction.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function lock( string $slug ): bool {
		global $wpdb;
		$wpdb->last_error = '';
		Manager::get_var( 'SELECT id FROM ' . Manager::table( 'games' ) . ' WHERE slug = %s FOR UPDATE', $slug );
		return $wpdb->last_error === '';
	}

	/**
	 * Same as lock(), by primary key rather than slug - for a caller whose
	 * own row is keyed on game_id specifically so a rename can't orphan it
	 * (Models/Submission.php, F-122).
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function lock_by_id( int $id ): bool {
		global $wpdb;
		$wpdb->last_error = '';
		Manager::get_var( 'SELECT id FROM ' . Manager::table( 'games' ) . ' WHERE id = %d FOR UPDATE', $id );
		return $wpdb->last_error === '';
	}

	/**
	 * Look up a single game by its primary key. Returns the row with its
	 * settings field decoded into an object, or null when no game with
	 * that ID exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'games' ) . ' WHERE id = %d',
			$id
		);
		return $row ? self::decode_settings( $row ) : null;
	}

	/**
	 * Return games matching optional filters. Supports filtering by game_type,
	 * plus pagination and sort order across name, slug, and creation/update
	 * dates.
	 *
	 * @param array $args Filters: game_type, orderby, order, per_page, offset.
	 * @return array
	 */
	public static function all( array $args = [] ): array {
		global $wpdb;
		$table = Manager::table( 'games' );
		$where = [];
		$values = [];

		if ( ! empty( $args['game_type'] ) ) {
			$where[] = 'game_type = %s';
			$values[] = $args['game_type'];
		}

		$sql = "SELECT * FROM {$table}";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		// The ?? default must apply in both branches or an unset orderby breaks the query.
		$orderby = in_array( $args['orderby'] ?? 'name', [ 'name', 'slug', 'created_at', 'updated_at' ], true )
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
		return array_map( [ self::class, 'decode_settings' ], $rows );
	}

	/**
	 * Count games matching the given filters. Accepts the same game_type filter
	 * as all(), without pagination, and returns a plain integer total rather
	 * than a result set.
	 *
	 * @param array $args Same filters as all().
	 * @return int
	 */
	public static function count( array $args = [] ): int {
		global $wpdb;
		$table = Manager::table( 'games' );
		$where = [];
		$values = [];

		if ( ! empty( $args['game_type'] ) ) {
			$where[] = 'game_type = %s';
			$values[] = $args['game_type'];
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
	 * Insert a new game. Generates a unique slug from the name when one is
	 * not supplied, JSON-encodes an array settings payload, and stamps
	 * created_at and updated_at.
	 *
	 * @param array $data Game data.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function create( array $data ) {
		$slug = ! empty( $data['slug'] )
			? sanitize_title( $data['slug'] )
			: self::unique_slug( $data['name'] );

		$insert = [
			'name'        => $data['name'],
			'slug'        => $slug,
			'game_type'   => $data['game_type'] ?? 'met',
			'description' => $data['description'] ?? '',
			'settings'    => is_array( $data['settings'] ?? null ) ? wp_json_encode( $data['settings'] ) : ( $data['settings'] ?? '{}' ),
			'created_by'  => get_current_user_id(),
			'created_at'  => current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
		];

		// Only set by Chronicle_Sync's create path and the correlation backfill - omitted
		// here (rather than defaulted to null) so every other caller's insert is unaffected.
		if ( array_key_exists( 'owbn_chronicle_post_id', $data ) ) {
			$insert['owbn_chronicle_post_id'] = $data['owbn_chronicle_post_id'];
		}

		return Manager::insert( 'games', $insert );
	}

	/**
	 * Update a game identified by slug. Writes only the fields present in
	 * $data, JSON-encodes an array settings payload, and stamps updated_at
	 * before writing the row. Deliberately cannot change the slug - rename()
	 * is the only path to that, since a slug change requires cascading to
	 * every table and reference that names it, which this plain update
	 * does not do.
	 *
	 * @param string $slug
	 * @param array  $data Fields to update.
	 * @return bool
	 */
	public static function update( string $slug, array $data ): bool {
		$allowed = [ 'name', 'game_type', 'description', 'settings', 'asc_role_path', 'notifications_enabled', 'owbn_chronicle_post_id' ];
		$update = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		if ( isset( $update['settings'] ) && is_array( $update['settings'] ) ) {
			$update['settings'] = wp_json_encode( $update['settings'] );
		}

		$update['updated_at'] = current_time( 'mysql' );

		$result = Manager::update( 'games', $update, [ 'slug' => $slug ] );
		return $result !== false;
	}

	/**
	 * Renames a chronicle: its own slug, every character's owner_slug, and
	 * every schema-block fork's game_slug, its verification codes, and this
	 * site's side of its transfers, atomically, keyed by numeric id rather
	 * than by the slug that is changing. This is the only path that changes a
	 * game's slug - update() cannot. Three conditions abort before anything
	 * is written: a slug collision with a different game, a schema-block fork
	 * already sitting at the destination slug (which would hit the forks
	 * table's own unique index), or any other content a deleted chronicle
	 * left at the destination, which this one would adopt. Page and Elementor
	 * widget references are repaired after commit, individually, since
	 * that repair calls wp_update_post() and must not run inside a
	 * transaction that might roll back. See
	 * BE_PROCESS/design/chronicle-rename-design.md §7.2 for the full reasoning.
	 *
	 * @param int    $game_id
	 * @param string $new_slug
	 * @return array{changed:bool,error?:string,message?:string,blocks?:string[],orphans?:array<string,int>,characters?:int,schema_blocks?:int,attestations?:int,transfers?:int,pages?:int,elementor?:int}
	 */
	public static function rename( int $game_id, string $new_slug ): array {
		global $wpdb;

		$new_slug = sanitize_title( $new_slug );
		$game     = self::find( $game_id );
		if ( ! $game ) {
			return [ 'changed' => false, 'error' => 'not_found' ];
		}

		$old_slug = $game->slug;
		if ( $old_slug === $new_slug ) {
			return [ 'changed' => false ];
		}

		$games_table       = Manager::table( 'games' );
		$char_table        = Manager::table( 'characters' );
		$block_table       = Manager::table( 'schema_blocks' );
		$attestation_table = Manager::table( 'character_attestations' );
		$transfer_table    = Manager::table( 'character_transfers' );

		$savepoint = Transaction::begin( 'be_game_rename' );

		$duplicate = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$games_table} WHERE slug = %s AND id <> %d",
				$new_slug,
				$game_id
			)
		);
		if ( $duplicate ) {
			Transaction::rollback( $savepoint );
			return [ 'changed' => false, 'error' => 'duplicate_slug' ];
		}

		$colliding_blocks = $wpdb->get_col(
			$wpdb->prepare( "SELECT slug FROM {$block_table} WHERE game_slug = %s", $new_slug )
		);
		if ( ! empty( $colliding_blocks ) ) {
			Transaction::rollback( $savepoint );
			return [ 'changed' => false, 'error' => 'fork_collision', 'blocks' => $colliding_blocks ];
		}

		// Characters, verification codes, or transfers a deleted chronicle left at the
		// destination would be silently adopted by this one.
		$orphans = self::orphaned_content_counts( $new_slug );
		if ( array_sum( $orphans ) > 0 ) {
			Transaction::rollback( $savepoint );
			return [ 'changed' => false, 'error' => 'orphan_collision', 'orphans' => $orphans ];
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$games_table} SET slug = %s, updated_at = %s WHERE id = %d",
				$new_slug,
				current_time( 'mysql' ),
				$game_id
			)
		);
		$characters = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$char_table} SET owner_slug = %s WHERE owner_type = 'chronicle' AND owner_slug = %s",
				$new_slug,
				$old_slug
			)
		);
		$schema_blocks = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$block_table} SET game_slug = %s WHERE game_slug = %s",
				$new_slug,
				$old_slug
			)
		);
		$attestations = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$attestation_table} SET game_slug = %s WHERE game_slug = %s",
				$new_slug,
				$old_slug
			)
		);
		// Only this site's side of a transfer names this chronicle: home_slug on an outbound
		// row, host_slug on an inbound one. The other slug belongs to the other site.
		$transfers = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$transfer_table} SET home_slug = %s WHERE direction = 'outbound' AND home_slug = %s",
				$new_slug,
				$old_slug
			)
		);
		$transfers += $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$transfer_table} SET host_slug = %s WHERE direction = 'inbound' AND host_slug = %s",
				$new_slug,
				$old_slug
			)
		);

		if ( $wpdb->last_error ) {
			Transaction::rollback( $savepoint );
			return [ 'changed' => false, 'error' => 'write_failed', 'message' => $wpdb->last_error ];
		}
		Transaction::commit( $savepoint );

		$reference_counts = Game_Slug_References::repair( $old_slug, $new_slug );
		Game_Stats_Controller::invalidate( $old_slug );
		Game_Stats_Controller::invalidate( $new_slug );

		return [
			'changed'       => true,
			'characters'    => (int) $characters,
			'schema_blocks' => (int) $schema_blocks,
			'attestations'  => (int) $attestations,
			'transfers'     => (int) $transfers,
			'pages'         => $reference_counts['pages'],
			'elementor'     => $reference_counts['elementor'],
		];
	}

	/**
	 * Delete a chronicle that holds no content. Refuses (returns false) while
	 * anything content_counts() counts is still stored under it: a row-only
	 * delete used to leave that content keyed by the slug, where the next
	 * chronicle created with the same name adopted it (1.0.0-review F-036).
	 * Memberships and recent searches go with the row.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function delete( string $slug ): bool {
		$game = self::find_by_slug( $slug );
		if ( ! $game || array_sum( self::content_counts( $game ) ) > 0 ) {
			return false;
		}
		return self::delete_with_content( $slug );
	}

	/**
	 * Delete a chronicle and everything stored under it: characters (with
	 * their changes, snapshots, sheet styles, and connections), plots, world
	 * objects, the chronicle's own templates and schema-block forks, saved
	 * queries, verification codes, this site's side of its transfers, and
	 * memberships. One transaction, so a failure partway through leaves
	 * nothing deleted. Site-wide templates and blocks are never touched.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function delete_with_content( string $slug ): bool {
		global $wpdb;

		$game = self::find_by_slug( $slug );
		if ( ! $game || $slug === '' ) {
			return false;
		}
		$game_id = (int) $game->id;

		$savepoint = Transaction::begin( 'be_game_delete_with_content' );
		$ok        = true;

		foreach ( Character::all_for_game( $slug ) as $character ) {
			$ok = Character::delete( (int) $character->id ) && $ok;
		}
		foreach ( Plot::for_game( $game_id ) as $plot ) {
			$ok = Plot::delete( (int) $plot->id ) && $ok;
		}
		foreach ( World_Object::for_game( $game_id ) as $object ) {
			$ok = World_Object::delete( (int) $object->id ) && $ok;
		}

		$transfer_table = Manager::table( 'character_transfers' );
		$deletes        = [
			// The chronicle's own template rows only: Template::for_game() merges in the
			// site-wide templates, and deleting through it removed every custom one.
			Manager::delete( 'templates', [ 'game_id' => $game_id ] ),
			Manager::delete( 'queries', [ 'game_id' => $game_id ] ),
			Manager::delete( 'schema_blocks', [ 'game_slug' => $slug ] ),
			Manager::delete( 'character_attestations', [ 'game_slug' => $slug ] ),
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$transfer_table} WHERE ( direction = 'outbound' AND home_slug = %s ) OR ( direction = 'inbound' AND host_slug = %s )",
				$slug,
				$slug
			) ),
			Manager::delete( 'connections', [ 'game_id' => $game_id ] ),
			Manager::delete( 'game_members', [ 'game_id' => $game_id ] ),
			Manager::delete( 'games', [ 'id' => $game_id ] ),
		];

		if ( ! $ok || in_array( false, $deletes, true ) ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		Game_Stats_Controller::invalidate( $slug );
		return true;
	}

	/**
	 * Counts everything stored under a chronicle that deleting its row alone
	 * would leave behind. Recent searches and memberships are not counted -
	 * nobody would call them the chronicle's content - but they are deleted
	 * with it.
	 *
	 * @param object $game A games row.
	 * @return array{characters:int,plots:int,world_objects:int,templates:int,schema_blocks:int,saved_queries:int,attestations:int,transfers:int}
	 */
	public static function content_counts( object $game ): array {
		$id   = (int) $game->id;
		$slug = (string) $game->slug;

		return [
			'characters'    => self::count_rows( 'characters', "owner_type = 'chronicle' AND owner_slug = %s", $slug ),
			'plots'         => self::count_rows( 'plots', 'game_id = %d', $id ),
			'world_objects' => self::count_rows( 'world_objects', 'game_id = %d', $id ),
			'templates'     => self::count_rows( 'templates', 'game_id = %d', $id ),
			'schema_blocks' => self::count_rows( 'schema_blocks', 'game_slug = %s', $slug ),
			'saved_queries' => self::count_rows( 'queries', 'game_id = %d AND is_recent_search = 0', $id ),
			'attestations'  => self::count_rows( 'character_attestations', 'game_slug = %s', $slug ),
			'transfers'     => self::count_rows( 'character_transfers', "( direction = 'outbound' AND home_slug = %s ) OR ( direction = 'inbound' AND host_slug = %s )", $slug, $slug ),
		];
	}

	/**
	 * Counts content stored under a slug that no chronicle holds - left by a
	 * row-only delete before 1.0.0. A chronicle created or renamed onto such a
	 * slug would adopt all of it, so neither is allowed to. Schema-block forks
	 * are reported separately by rename()'s own fork_collision check and
	 * counted here as well.
	 *
	 * @param string $slug
	 * @return array{characters:int,schema_blocks:int,attestations:int,transfers:int}
	 */
	public static function orphaned_content_counts( string $slug ): array {
		if ( $slug === '' || self::find_by_slug( $slug ) ) {
			return [ 'characters' => 0, 'schema_blocks' => 0, 'attestations' => 0, 'transfers' => 0 ];
		}

		return [
			'characters'    => self::count_rows( 'characters', "owner_type = 'chronicle' AND owner_slug = %s", $slug ),
			'schema_blocks' => self::count_rows( 'schema_blocks', 'game_slug = %s', $slug ),
			'attestations'  => self::count_rows( 'character_attestations', 'game_slug = %s', $slug ),
			'transfers'     => self::count_rows( 'character_transfers', "( direction = 'outbound' AND home_slug = %s ) OR ( direction = 'inbound' AND host_slug = %s )", $slug, $slug ),
		];
	}

	/**
	 * Counts rows in one plugin table matching a prepared WHERE clause.
	 *
	 * @param string           $table Short table name.
	 * @param string           $where
	 * @param int|string       ...$args
	 */
	private static function count_rows( string $table, string $where, ...$args ): int {
		return (int) Manager::get_var( 'SELECT COUNT(*) FROM ' . Manager::table( $table ) . " WHERE {$where}", ...$args );
	}

	/**
	 * Generate a slug guaranteed not to collide with an existing game, or with
	 * content a deleted chronicle left under a slug. Sanitizes the base string
	 * and appends an incrementing numeric suffix until the result is free.
	 *
	 * @param string $base
	 * @return string
	 */
	private static function unique_slug( string $base ): string {
		$slug = sanitize_title( $base );
		$original = $slug;
		$i = 2;
		while ( self::find_by_slug( $slug ) || array_sum( self::orphaned_content_counts( $slug ) ) > 0 ) {
			$slug = $original . '-' . $i;
			$i++;
		}
		return $slug;
	}

	/**
	 * Decode a row's settings JSON field into an object in place. Passes
	 * null rows through unchanged, and leaves a non-string settings value
	 * untouched rather than attempting to decode it.
	 *
	 * @param object|null $row Row from the database, or null when the query found nothing.
	 * @return object|null The same row, or null when null was passed in.
	 */
	private static function decode_settings( $row ) {
		if ( $row && isset( $row->settings ) && is_string( $row->settings ) ) {
			$row->settings = json_decode( $row->settings );
		}
		// Same "0"-is-truthy-in-JavaScript hazard as Schema_Block::decode_definition() (D53).
		if ( $row && isset( $row->notifications_enabled ) ) {
			$row->notifications_enabled = (int) $row->notifications_enabled;
		}
		return $row;
	}
}
