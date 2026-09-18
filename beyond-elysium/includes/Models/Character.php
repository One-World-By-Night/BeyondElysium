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
	 * The full set of valid character statuses. Was `Characters_Controller::STATUSES`
	 * until Decision 102 promoted it here so `bulk_update_status()` and the controller's
	 * own single-character validation can't drift onto two different lists.
	 */
	const STATUSES = [ 'active', 'inactive', 'retired', 'dead', 'pending' ];

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
	 * Lock a character's row until the surrounding transaction ends. Every
	 * sheet write reads the whole sheet_data document and writes it back, so
	 * two writers must take turns or one silently erases the other's change
	 * (1.0.0-review F-015). Must run inside a Transaction.
	 *
	 * @param int $id
	 * @return void
	 */
	public static function lock( int $id ): void {
		Manager::get_var( 'SELECT id FROM ' . Manager::table( 'characters' ) . ' WHERE id = %d FOR UPDATE', $id );
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
		$table = Manager::table( 'characters' );
		[ $where, $values ] = self::build_where( $game_slug, $args );

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
		$table = Manager::table( 'characters' );
		[ $where, $values ] = self::build_where( $game_slug, $args );

		$sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode( ' AND ', $where );
		$sql = $wpdb->prepare( $sql, $values );
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * The shared WHERE-clause builder behind `all_for_game()` and `count_for_game()` -
	 * both accept the identical filter vocabulary (status, stack, NPC flag, owning user,
	 * name search) and had built it twice, byte-for-byte, until this was extracted
	 * (1.1.1 audit).
	 *
	 * @param string $game_slug
	 * @param array  $args
	 * @return array{0: string[], 1: array<int,mixed>} `[$where_clauses, $bind_values]`.
	 */
	private static function build_where( string $game_slug, array $args ): array {
		global $wpdb;
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

		return [ $where, $values ];
	}

	/**
	 * Count every character of one creature type, in any chronicle - player,
	 * NPC, active or not.
	 *
	 * @param string $stack_slug
	 * @return int
	 */
	public static function count_for_stack( string $stack_slug ): int {
		global $wpdb;
		$table = Manager::table( 'characters' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE stack_slug = %s", $stack_slug ) );
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
			'rp_notes', 'sheet_data', 'image_id', 'npc_detail',
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
		// Meaningful only on an NPC (1.1.0 §3.7); a PC simply never reads it.
		$insert['npc_detail'] = in_array( $insert['npc_detail'] ?? null, [ 'full', 'quick' ], true ) ? $insert['npc_detail'] : 'full';

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

		$unit = Transaction::begin( 'be_character_create' );
		$id   = Manager::insert( 'characters', $insert );
		if ( ! $id ) {
			Transaction::rollback( $unit );
			return 0;
		}

		// Auto-create initial snapshot.
		Snapshot::create( (int) $id, null );

		// A join request (`await_approval`) grants membership only when a Storyteller activates it (update_header()).
		if ( $insert['owner_type'] === 'chronicle' && ! empty( $insert['wp_user_id'] ) && empty( $data['await_approval'] ) ) {
			self::ensure_player_membership( (string) $insert['owner_slug'], (int) $insert['wp_user_id'] );
		}

		// Every character in a chronicle has its own plot, and one whose plot can't be written
		// isn't made either (owner, 2026-09-15).
		$in_chronicle = $insert['owner_type'] === 'chronicle' && Game::find_by_slug( (string) ( $insert['owner_slug'] ?? '' ) );
		if ( $in_chronicle && self::ensure_plot( (int) $id ) === null ) {
			Transaction::rollback( $unit );
			return 0;
		}

		Transaction::commit( $unit );
		return (int) $id;
	}

	/**
	 * The title a character's own plot is made with: `<name> [id] Plot`.
	 *
	 * @param string $name
	 * @param int    $id
	 * @return string
	 */
	public static function plot_title( string $name, int $id ): string {
		return sprintf( '%s [%d] Plot', $name, $id );
	}

	/**
	 * A character's own plot: linked to it as its actor - the link that keeps a
	 * plot from every other player - and with no game date, which sets it apart
	 * from the character's action rounds. Null when it has none.
	 *
	 * @param int $id
	 * @return int|null
	 */
	public static function plot_id( int $id ): ?int {
		// 'apr_actor' is Services\Action_Allocator::ACTOR_LABEL - Models don't depend on Services.
		$plot_id = Manager::get_var(
			'SELECT p.id FROM ' . Manager::table( 'plots' ) . ' p
			 INNER JOIN ' . Manager::table( 'connections' ) . " c ON c.source_type = 'plot' AND c.source_id = p.id
			 WHERE c.target_type = 'character' AND c.target_id = %d AND c.label = %s AND p.game_date IS NULL
			 ORDER BY p.id ASC LIMIT 1",
			$id,
			'apr_actor'
		);
		return $plot_id ? (int) $plot_id : null;
	}

	/**
	 * A character's own plot, made now if it has none. Every character, PC or
	 * NPC, has one (owner, 2026-09-15): its player and the chronicle's
	 * Storytellers see it, and the character's action rounds sit under it. Holds
	 * the character's row while it checks and writes, so two callers can't each
	 * make one.
	 *
	 * @param int $id
	 * @return int|null Null for a character outside any existing chronicle, or when the plot couldn't be written - nothing is kept then.
	 */
	public static function ensure_plot( int $id ): ?int {
		$unit = Transaction::begin( 'be_character_plot' );
		self::lock( $id );

		$existing = self::plot_id( $id );
		if ( $existing !== null ) {
			Transaction::commit( $unit );
			return $existing;
		}

		$character = Manager::get_row( 'SELECT name, owner_type, owner_slug FROM ' . Manager::table( 'characters' ) . ' WHERE id = %d', $id );
		$game      = $character && $character->owner_type === 'chronicle' ? Game::find_by_slug( (string) $character->owner_slug ) : null;
		if ( ! $character || ! $game ) {
			Transaction::rollback( $unit );
			return null;
		}

		// 'restricted', not the schema default 'everyone': this is the character's own private
		// action plot, and the 'apr_actor' connection written right below is what Audience finds
		// to make that one character its audience (Services\Audience::RESTRICTED, duplicated as a
		// literal per this codebase's Models-doesn't-depend-on-Services rule - see Plot::AUDIENCE_VALUES).
		$plot_id = Plot::create( [
			'game_id'      => (int) $game->id,
			'title'        => self::plot_title( (string) $character->name, $id ),
			'initiated_by' => 'st',
			'created_by'   => get_current_user_id(),
			'audience'     => 'restricted',
		] );
		$linked  = $plot_id && Connection::create( [
			'game_id'     => (int) $game->id,
			'source_type' => 'plot',
			'source_id'   => (int) $plot_id,
			'target_type' => 'character',
			'target_id'   => $id,
			'label'       => 'apr_actor',
			'created_by'  => get_current_user_id(),
		] );
		if ( ! $linked ) {
			Transaction::rollback( $unit );
			return null;
		}

		Transaction::commit( $unit );
		return (int) $plot_id;
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
	 * membership when wp_user_id is being set to a new owner or the character is
	 * set active - which is how a Storyteller approves a join request.
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
			'pending_player_email', 'assigned_to', 'npc_detail',
			'public_name', 'public_description', 'public_image_id', 'profile_audience', 'profile_audience_rules',
		];

		$update = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( array_key_exists( 'assigned_to', $update ) ) {
			$update['assigned_to'] = ! empty( $update['assigned_to'] ) ? (int) $update['assigned_to'] : null;
		}
		if ( array_key_exists( 'npc_detail', $update ) && ! in_array( $update['npc_detail'], [ 'full', 'quick' ], true ) ) {
			return false;
		}
		if ( array_key_exists( 'public_image_id', $update ) ) {
			$update['public_image_id'] = ! empty( $update['public_image_id'] ) ? (int) $update['public_image_id'] : null;
		}
		if ( array_key_exists( 'profile_audience_rules', $update ) ) {
			$update['profile_audience_rules'] = self::encode_json_field( $update['profile_audience_rules'] );
			if ( $update['profile_audience_rules'] === false ) {
				return false;
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		$old_name = array_key_exists( 'name', $update )
			? Manager::get_var( 'SELECT name FROM ' . Manager::table( 'characters' ) . ' WHERE id = %d', $id )
			: null;

		$update['updated_at'] = current_time( 'mysql' );
		$result               = Manager::update( 'characters', $update, [ 'id' => $id ] );

		// The character's own plot keeps the character's name, unless a Storyteller has retitled it.
		if ( $result !== false && $old_name !== null && (string) $old_name !== (string) $update['name'] ) {
			$plot_id = self::plot_id( $id );
			$plot    = $plot_id ? Plot::find( $plot_id ) : null;
			if ( $plot && $plot->title === self::plot_title( (string) $old_name, $id ) ) {
				Plot::update( (int) $plot_id, [ 'title' => self::plot_title( (string) $update['name'], $id ) ] );
			}
		}

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

		// Activating a character approves its player's join request, if it was one (F-033).
		if ( $result !== false && ( $update['status'] ?? null ) === 'active' ) {
			$owner = (array) Manager::get_row(
				'SELECT owner_slug, wp_user_id FROM ' . Manager::table( 'characters' ) . ' WHERE id = %d AND owner_type = %s',
				$id,
				'chronicle'
			);
			if ( ! empty( $owner['wp_user_id'] ) ) {
				self::ensure_player_membership( (string) $owner['owner_slug'], (int) $owner['wp_user_id'] );
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
	 * Resets one held resource pool's temporary rating back to its permanent one -
	 * the ordinary end-of-session "Willpower/Blood refills" maintenance action,
	 * bulk-operations-design.md's Item 1. Writes directly to sheet_data, never
	 * routed through Change_Engine/character_changes, matching this project's own
	 * convention that an ST-initiated direct correction is not an approvable
	 * "change" (`Characters_Controller::create_item()`'s starting-sheet write is the
	 * same principle). A character who doesn't hold the named pool at all, or whose
	 * pool is stored as a bare scalar (the older shape - a single number with no
	 * separate temporary to reset), is a no-op success, not a failure.
	 *
	 * @param int    $id
	 * @param string $block_slug
	 * @param string $pool_name
	 * @return bool False only when the character itself does not exist.
	 */
	public static function reset_pool_to_permanent( int $id, string $block_slug, string $pool_name ): bool {
		$character = self::find( $id );
		if ( ! $character ) {
			return false;
		}

		$sheet_data = $character->sheet_data;
		$pool       = $sheet_data[ $block_slug ][ $pool_name ] ?? null;

		if ( ! is_array( $pool ) || ! array_key_exists( 'permanent', $pool ) ) {
			return true;
		}

		$sheet_data[ $block_slug ][ $pool_name ]['temporary'] = $pool['permanent'];
		return self::update_sheet_data( $id, $sheet_data );
	}

	/**
	 * Sets the same status on a batch of characters at once - bulk-operations-design.md's
	 * Item 2. Every ID is checked against `$game_slug` before writing (the same
	 * ownership check `Characters_Controller::update_item()` already applies to a single
	 * character), so a bad or foreign ID in the list can never reach another
	 * chronicle's character. Collects a result per ID instead of an all-or-nothing
	 * transaction, matching `computeChanges.ts`'s own "collect every failure instead of
	 * abandoning on the first" precedent from the character editor's submit path.
	 *
	 * @param int[]  $ids
	 * @param string $status     Validated by the caller against `self::STATUSES`.
	 * @param string $game_slug
	 * @return array<int,array{id:int,success:bool,error?:string}>
	 */
	public static function bulk_update_status( array $ids, string $status, string $game_slug ): array {
		$results = [];
		foreach ( $ids as $id ) {
			$id        = (int) $id;
			$character = self::find( $id );
			if ( ! $character || $character->owner_slug !== $game_slug ) {
				$results[] = [ 'id' => $id, 'success' => false, 'error' => 'not_found' ];
				continue;
			}
			$results[] = [ 'id' => $id, 'success' => self::update_header( $id, [ 'status' => $status ] ) ];
		}
		return $results;
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
	 * Also removes the character's action allocation plots, and closes any
	 * transfer still in motion the way a Storyteller would: a pending offer is
	 * declined and its verification code revoked, a character abroad is
	 * released, and a visiting copy's visit ends (1.0.0-review F-014). The
	 * codes on printed and exported sheets stay, as the record that the
	 * chronicle issued those documents.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$savepoint = Transaction::begin( 'be_character_delete' );

		$character = self::find( $id );
		if ( $character ) {
			self::delete_allocation_plots( $id );
			self::close_transfers( $character );
		}

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
	 * Deletes every action allocation plot a character is the actor of. Only
	 * that link keeps the plot - titled with the character's name, its entries
	 * holding the character's Background ratings - hidden from other players,
	 * so it can't outlive the character.
	 *
	 * @param int $id
	 */
	private static function delete_allocation_plots( int $id ): void {
		global $wpdb;
		$connections = Manager::table( 'connections' );
		// 'apr_actor' is Services\Action_Allocator::ACTOR_LABEL - Models don't depend on Services.
		$plot_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT source_id FROM {$connections}
			 WHERE source_type = 'plot' AND target_type = 'character' AND target_id = %d AND label = %s",
			$id,
			'apr_actor'
		) );
		foreach ( array_unique( array_map( 'intval', $plot_ids ) ) as $plot_id ) {
			Plot::delete( $plot_id );
		}
	}

	/**
	 * Closes the transfers still in motion for a character being deleted.
	 *
	 * @param object $character
	 */
	private static function close_transfers( object $character ): void {
		$outbound = Transfer::find_open( (string) $character->uuid, 'outbound' );
		if ( $outbound !== null && $outbound->home_slug === $character->owner_slug ) {
			if ( $outbound->state === 'pending' ) {
				Transfer::transition( (int) $outbound->id, 'declined' );
				if ( ! empty( $outbound->attestation_id ) ) {
					Attestation::revoke( (int) $outbound->attestation_id );
				}
			} elseif ( $outbound->state === 'abroad' ) {
				Transfer::transition( (int) $outbound->id, 'released' );
			}
		}

		$inbound = Transfer::find_open( (string) $character->uuid, 'inbound' );
		if ( $inbound !== null && (int) $inbound->character_id === (int) $character->id && $inbound->state === 'visiting' ) {
			Transfer::transition( (int) $inbound->id, 'sent_home' );
		}
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
	 * Decode a row's sheet_data JSON field into an array in place, and cast
	 * is_npc to a real boolean. Passes null rows through unchanged, and
	 * normalizes an unparseable or absent sheet_data value to an empty array
	 * so callers never see a raw JSON string.
	 *
	 * is_npc is stored as tinyint(1) and $wpdb always returns column values as
	 * strings regardless of their SQL type - every consumer of this row was
	 * getting the literal string "0" for a non-NPC, which is truthy in both
	 * PHP and JavaScript. Cast once here, at the one place every row-returning
	 * method in this class already funnels through, rather than requiring
	 * every future reader to remember to coerce it correctly itself.
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
		if ( $row && isset( $row->is_npc ) ) {
			$row->is_npc = (bool) $row->is_npc;
		}
		// profile_audience_rules (1.1.0 §3.7) - null stays null; an unparseable value is logged
		// and treated as null rather than the row being dropped, matching Plot::decode_row()'s
		// own contract for its own audience_rules column.
		if ( $row && property_exists( $row, 'profile_audience_rules' ) && is_string( $row->profile_audience_rules ) ) {
			$decoded = json_decode( $row->profile_audience_rules, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( sprintf( 'Beyond Elysium: character id %d has corrupt profile_audience_rules JSON; treating as null.', (int) ( $row->id ?? 0 ) ) );
				$decoded = null;
			}
			$row->profile_audience_rules = $decoded;
		}
		return $row;
	}

	/**
	 * Encode a value for a JSON column, matching Plot::encode_json_field()'s exact contract:
	 * null stays null, an array or object is JSON-encoded, anything else is cast to string.
	 *
	 * @param mixed $value
	 * @return string|null|false False when wp_json_encode() itself cannot encode the value.
	 */
	private static function encode_json_field( $value ) {
		if ( $value === null ) {
			return null;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}
}
