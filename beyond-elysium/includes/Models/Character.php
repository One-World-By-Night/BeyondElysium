<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Utils\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for player and NPC characters.
 *
 * Character is a Database\Manager CRUD model backed by the characters table. It
 * stores each character's header fields (name, status, ownership, XP totals)
 * together with its sheet_data JSON blob, resolves lookup by ID, UUID, or name
 * within a game, and cascades deletion to the connections, changes, snapshots,
 * and sheet style rows that reference it.
 */
class Character {

	/**
	 * Look up a single character by its primary key. Returns the row with its
	 * sheet_data field decoded into an array, or null when no character with that
	 * ID exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'characters' ) . ' WHERE id = %d',
			$id
		);
		return $row ? self::decode_sheet( $row ) : null;
	}

	/**
	 * Find a character by its permanent UUID rather than the local auto-increment
	 * ID. The UUID survives transfers between WordPress installations, so this is
	 * the lookup external OWBN tools should use.
	 *
	 * @param string $uuid Canonical 36-character UUID.
	 * @return object|null
	 */
	public static function find_by_uuid( string $uuid ) {
		if ( ! Uuid::is_valid( $uuid ) ) {
			return null;
		}

		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'characters' ) . ' WHERE uuid = %s',
			strtolower( $uuid )
		);
		return $row ? self::decode_sheet( $row ) : null;
	}

	/**
	 * Return characters belonging to one game's chronicle. Supports filtering by
	 * status, stack, NPC flag, owning user, and name search, plus pagination and
	 * sort order across name, status, dates, XP, stack, and player columns.
	 *
	 * @param string $game_slug
	 * @param array  $args Filters: status, stack_slug, is_npc, wp_user_id, search, per_page, offset, orderby, order.
	 * @return array
	 */
	public static function all_for_game( string $game_slug, array $args = [] ): array {
		global $wpdb;
		$table  = Manager::table( 'characters' );
		$where  = [ "owner_type = 'chronicle'", 'owner_slug = %s' ];
		$values = [ $game_slug ];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['stack_slug'] ) ) {
			$where[]  = 'stack_slug = %s';
			$values[] = $args['stack_slug'];
		}

		if ( isset( $args['is_npc'] ) && $args['is_npc'] !== '' ) {
			$where[]  = 'is_npc = %d';
			$values[] = (int) $args['is_npc'];
		}

		if ( ! empty( $args['wp_user_id'] ) ) {
			$where[]  = 'wp_user_id = %d';
			$values[] = (int) $args['wp_user_id'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'name LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where );

		// Whitelisted sortable columns for the character list.
		$orderby = in_array( $args['orderby'] ?? 'name', [ 'name', 'status', 'created_at', 'updated_at', 'xp_earned', 'xp_unspent', 'stack_slug', 'player_name' ], true )
			? ( $args['orderby'] ?? 'name' )
			: 'name';
		$order   = strtoupper( $args['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
		$sql    .= " ORDER BY {$orderby} {$order}";

		if ( isset( $args['per_page'] ) ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['per_page'], (int) ( $args['offset'] ?? 0 ) );
		}

		$sql  = $wpdb->prepare( $sql, $values );
		$rows = $wpdb->get_results( $sql ) ?: [];
		return array_map( [ self::class, 'decode_sheet' ], $rows );
	}

	/**
	 * Count the characters belonging to one game's chronicle that match the given
	 * filters. Accepts the same status, stack, NPC, owner, and search filters as
	 * all_for_game(), without pagination, and returns a plain integer total.
	 *
	 * @param string $game_slug
	 * @param array  $args Same filters as all_for_game() (no pagination).
	 * @return int
	 */
	public static function count_for_game( string $game_slug, array $args = [] ): int {
		global $wpdb;
		$table  = Manager::table( 'characters' );
		$where  = [ "owner_type = 'chronicle'", 'owner_slug = %s' ];
		$values = [ $game_slug ];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['stack_slug'] ) ) {
			$where[]  = 'stack_slug = %s';
			$values[] = $args['stack_slug'];
		}

		if ( isset( $args['is_npc'] ) && $args['is_npc'] !== '' ) {
			$where[]  = 'is_npc = %d';
			$values[] = (int) $args['is_npc'];
		}

		if ( ! empty( $args['wp_user_id'] ) ) {
			$where[]  = 'wp_user_id = %d';
			$values[] = (int) $args['wp_user_id'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'name LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$sql  = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode( ' AND ', $where );
		$sql  = $wpdb->prepare( $sql, $values );
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Return character counts grouped by stack_slug for one game. Only stacks
	 * with at least one character are present in the result; a dashboard renders
	 * whichever keys show up rather than a fixed list of every known stack.
	 *
	 * @param string $game_slug
	 * @return array<string,int> stack_slug => count
	 */
	public static function counts_by_stack_for_game( string $game_slug ): array {
		global $wpdb;
		$table = Manager::table( 'characters' );
		$sql   = $wpdb->prepare(
			"SELECT stack_slug, COUNT(*) as total FROM {$table} WHERE owner_type = 'chronicle' AND owner_slug = %s GROUP BY stack_slug",
			$game_slug
		);
		$rows  = $wpdb->get_results( $sql ) ?: [];

		$counts = [];
		foreach ( $rows as $row ) {
			$counts[ $row->stack_slug ] = (int) $row->total;
		}
		return $counts;
	}

	/**
	 * Return character counts grouped by status for one game. Only statuses with
	 * at least one character are present in the result, keyed by status value
	 * rather than a fixed enumeration.
	 *
	 * @param string $game_slug
	 * @return array<string,int> status => count
	 */
	public static function counts_by_status_for_game( string $game_slug ): array {
		global $wpdb;
		$table = Manager::table( 'characters' );
		$sql   = $wpdb->prepare(
			"SELECT status, COUNT(*) as total FROM {$table} WHERE owner_type = 'chronicle' AND owner_slug = %s GROUP BY status",
			$game_slug
		);
		$rows  = $wpdb->get_results( $sql ) ?: [];

		$counts = [];
		foreach ( $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}
		return $counts;
	}

	/**
	 * Insert a new character row. Assigns a permanent UUID when the caller does
	 * not supply a valid one, JSON-encodes sheet_data, creates an initial snapshot
	 * of the new sheet, and ensures chronicle membership when an owning user is set.
	 *
	 * @param array $data Character data.
	 * @return int Insert ID, or 0 on failure.
	 */
	public static function create( array $data ): int {
		$allowed = [
			'name', 'stack_slug', 'owner_type', 'owner_slug',
			'wp_user_id', 'player_name', 'pending_player_email', 'status', 'is_npc',
			'narrator', 'start_date', 'biography', 'notes',
			'rp_notes', 'sheet_data', 'image_id',
		];

		$insert = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$insert[ $field ] = $data[ $field ];
			}
		}

		// Defaults.
		$insert['status']     = $insert['status'] ?? 'active';
		$insert['is_npc']     = isset( $insert['is_npc'] ) ? (int) $insert['is_npc'] : 0;
		$insert['owner_type'] = $insert['owner_type'] ?? 'chronicle';

		// Assigns a new UUID unless the caller supplied a valid one.
		if ( ! isset( $data['uuid'] ) || ! Uuid::is_valid( (string) $data['uuid'] ) ) {
			$insert['uuid'] = Uuid::v7();
		} else {
			$insert['uuid'] = strtolower( (string) $data['uuid'] );
		}

		// JSON-encode sheet_data if array.
		if ( isset( $insert['sheet_data'] ) && is_array( $insert['sheet_data'] ) ) {
			$insert['sheet_data'] = wp_json_encode( $insert['sheet_data'] );
		} elseif ( ! isset( $insert['sheet_data'] ) ) {
			$insert['sheet_data'] = '{}';
		}

		$insert['created_by'] = get_current_user_id();
		$insert['created_at'] = current_time( 'mysql' );
		$insert['updated_at'] = current_time( 'mysql' );

		$id = Manager::insert( 'characters', $insert );

		if ( $id ) {
			// Auto-create initial snapshot.
			Snapshot::create( (int) $id, null );

			if ( $insert['owner_type'] === 'chronicle' && ! empty( $insert['wp_user_id'] ) ) {
				self::ensure_player_membership( (string) $insert['owner_slug'], (int) $insert['wp_user_id'] );
			}
		}

		return (int) $id;
	}

	/**
	 * Grant a WordPress user at least player membership in the chronicle that owns
	 * a character. Looks up the game by slug and delegates to
	 * Game_Member::ensure_player(), which never downgrades an existing role.
	 *
	 * @param string $owner_slug
	 * @param int    $wp_user_id
	 * @return void
	 */
	private static function ensure_player_membership( string $owner_slug, int $wp_user_id ): void {
		$game = Game::find_by_slug( $owner_slug );
		if ( $game ) {
			Game_Member::ensure_player( (int) $game->id, $wp_user_id );
		}
	}

	/**
	 * Update a character's header fields - everything except sheet_data. Writes
	 * only the fields present in $data, stamps updated_at, and ensures chronicle
	 * membership when wp_user_id is being set to a new owner.
	 *
	 * @param int   $id
	 * @param array $data Fields to update.
	 * @return bool
	 */
	public static function update_header( int $id, array $data ): bool {
		// 'uuid' is not editable: it is assigned once at creation and never changes.
		$allowed = [
			'name', 'status', 'biography', 'notes', 'rp_notes',
			'narrator', 'player_name', 'start_date', 'is_npc', 'image_id', 'wp_user_id',
			'pending_player_email',
		];

		$update = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$result               = Manager::update( 'characters', $update, [ 'id' => $id ] );

		if ( $result !== false && ! empty( $update['wp_user_id'] ) ) {
			// Looks up just owner_slug rather than fetching the full character row.
			$owner_slug = Manager::get_var(
				'SELECT owner_slug FROM ' . Manager::table( 'characters' ) . ' WHERE id = %d AND owner_type = %s',
				$id,
				'chronicle'
			);
			if ( $owner_slug ) {
				self::ensure_player_membership( (string) $owner_slug, (int) $update['wp_user_id'] );
			}
		}

		return $result !== false;
	}

	/**
	 * Replace a character's sheet_data column with a new JSON-encoded payload.
	 * Leaves every other field - name, status, ownership, XP - untouched, and
	 * stamps updated_at.
	 *
	 * @param int   $id
	 * @param array $sheet_data
	 * @return bool
	 */
	public static function update_sheet_data( int $id, array $sheet_data ): bool {
		$update = [
			'sheet_data' => wp_json_encode( $sheet_data ),
			'updated_at' => current_time( 'mysql' ),
		];
		$result = Manager::update( 'characters', $update, [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Atomically adjust a character's XP counters. xp_earned is floored at zero;
	 * xp_unspent is allowed to go negative, which lets a storyteller record an
	 * approval that spends more than the character currently has unspent.
	 *
	 * @param int $id
	 * @param int $earned_delta  Delta for xp_earned (can be negative for adjustments).
	 * @param int $unspent_delta Delta for xp_unspent (can be negative when spending).
	 * @return bool
	 */
	public static function update_xp( int $id, int $earned_delta, int $unspent_delta ): bool {
		global $wpdb;
		$table  = Manager::table( 'characters' );
		// CAST allows negative deltas on UNSIGNED columns; only xp_earned floors at 0.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET xp_earned = GREATEST(0, CAST(xp_earned AS SIGNED) + %d), xp_unspent = CAST(xp_unspent AS SIGNED) + %d, updated_at = %s WHERE id = %d",
				$earned_delta,
				$unspent_delta,
				current_time( 'mysql' ),
				$id
			)
		);
		return $result !== false;
	}

	/**
	 * Delete a character by ID, cascading to everything that references it -
	 * connections, changes, snapshots, and sheet style rows. Runs inside a
	 * transaction so a failed delete leaves none of the cascade committed, even
	 * when called from within another already-open transaction.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$savepoint = Transaction::begin( 'be_character_delete' );

		Connection::delete_for_entity( 'character', $id );
		Change::delete_for_character( $id );
		Snapshot::delete_for_character( $id );
		Sheet_Style::delete_for_character( $id );
		$result = Manager::delete( 'characters', [ 'id' => $id ] );

		if ( $result === false ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * Find a character by exact name within one game. Matches on name only,
	 * scoped to the given chronicle, so a name shared with a character in a
	 * different game is not considered a match. When more than one character
	 * shares the name, returns the oldest row by ID rather than picking unpredictably.
	 *
	 * @param string $name
	 * @param string $game_slug
	 * @return object|null
	 */
	public static function find_by_name_in_game( string $name, string $game_slug ) {
		$row = Manager::get_row(
			"SELECT * FROM " . Manager::table( 'characters' ) . "
			 WHERE owner_type = 'chronicle' AND owner_slug = %s AND name = %s
			 ORDER BY id ASC LIMIT 1",
			$game_slug,
			$name
		);
		return $row ? self::decode_sheet( $row ) : null;
	}

	/**
	 * Return every character owned by a specific WordPress user within one game.
	 * Matches on wp_user_id and owner_slug, ordered alphabetically by name, with
	 * no pagination.
	 *
	 * @param int    $wp_user_id
	 * @param string $game_slug
	 * @return array
	 */
	public static function find_for_user( int $wp_user_id, string $game_slug ): array {
		global $wpdb;
		$table = Manager::table( 'characters' );
		$sql   = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE wp_user_id = %d AND owner_slug = %s ORDER BY name ASC",
			$wp_user_id,
			$game_slug
		);
		$rows  = $wpdb->get_results( $sql ) ?: [];
		return array_map( [ self::class, 'decode_sheet' ], $rows );
	}

	/**
	 * Decode a row's sheet_data JSON field into an array in place. Passes null
	 * rows through unchanged, and normalizes an unparseable or absent value to an
	 * empty array so callers never see a raw JSON string.
	 *
	 * @param object|null $row Row from the database, or null when the query found nothing.
	 * @return object|null The same row, or null when null was passed in.
	 */
	private static function decode_sheet( $row ) {
		if ( $row && isset( $row->sheet_data ) && is_string( $row->sheet_data ) ) {
			$row->sheet_data = json_decode( $row->sheet_data, true );
			if ( $row->sheet_data === null ) {
				$row->sheet_data = [];
			}
		}
		return $row;
	}
}
