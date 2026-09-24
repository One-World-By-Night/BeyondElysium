<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Utils\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for player and NPC characters.
 */
class Character {

	/**
	 * The full set of valid character statuses.
	 */
	const STATUSES = [ 'active', 'inactive', 'retired', 'dead', 'pending' ];

	/**
	 * Look up a single character by its primary key.
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
	 * Lock a character's row until the surrounding transaction ends.
	 *
	 * @param int $id
	 * @return void
	 */
	public static function lock( int $id ): void {
		Manager::get_var( 'SELECT id FROM ' . Manager::table( 'characters' ) . ' WHERE id = %d FOR UPDATE', $id );
	}

	/**
	 * Find a character by its permanent UUID.
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
	 * Return characters belonging to one game's chronicle.
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
	 * Count the characters belonging to one game's chronicle that match the given filters.
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
	 * The shared WHERE-clause builder behind `all_for_game()` and `count_for_game()`.
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
	 * Count every character of one creature type, in any chronicle.
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
	 * Return character counts grouped by stack_slug for one game.
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
	 * Return character counts grouped by status for one game.
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
	 * Insert a new character row.
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
		// Meaningful only on an NPC.
		$insert['npc_detail'] = in_array( $insert['npc_detail'] ?? null, [ 'full', 'quick' ], true ) ? $insert['npc_detail'] : 'full';

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
	 * A character's own plot: linked to it as its actor.
	 *
	 * @param int $id
	 * @return int|null
	 */
	public static function plot_id( int $id ): ?int {
		// 'apr_actor' is Services\Action_Allocator::ACTOR_LABEL.
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
	 * A character's own plot, made now if it has none.
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
	 * Grant a WordPress user at least player membership in the chronicle that owns a character.
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
	 * Update a character's header fields.
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

		if ( $result !== false && $old_name !== null && (string) $old_name !== (string) $update['name'] ) {
			$plot_id = self::plot_id( $id );
			$plot    = $plot_id ? Plot::find( $plot_id ) : null;
			if ( $plot && $plot->title === self::plot_title( (string) $old_name, $id ) ) {
				Plot::update( (int) $plot_id, [ 'title' => self::plot_title( (string) $update['name'], $id ) ] );
			}
		}

		if ( $result !== false && ! empty( $update['wp_user_id'] ) ) {
			// Looks up just owner_slug.
			$owner_slug = Manager::get_var(
				'SELECT owner_slug FROM ' . Manager::table( 'characters' ) . ' WHERE id = %d AND owner_type = %s',
				$id,
				'chronicle'
			);
			if ( $owner_slug ) {
				self::ensure_player_membership( (string) $owner_slug, (int) $update['wp_user_id'] );
			}
		}

		// Activating a character approves its player's join request, if it was one.
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
	 * Resets one held resource pool's temporary rating back to its permanent one.
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
	 * Sets the same status on a batch of characters at once.
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
	 * Atomically adjust a character's XP counters. xp_earned is floored at zero.
	 *
	 * @param int $id
	 * @param int $earned_delta  Delta for xp_earned (can be negative for adjustments).
	 * @param int $unspent_delta Delta for xp_unspent (can be negative when spending).
	 * @return bool
	 */
	public static function update_xp( int $id, int $earned_delta, int $unspent_delta ): bool {
		global $wpdb;
		$table  = Manager::table( 'characters' );
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
	 * Delete a character by ID, cascading to everything that references it.
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
	 * Deletes every action allocation plot a character is the actor of.
	 *
	 * @param int $id
	 */
	private static function delete_allocation_plots( int $id ): void {
		global $wpdb;
		$connections = Manager::table( 'connections' );
		// 'apr_actor' is Services\Action_Allocator::ACTOR_LABEL.
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
	 * Find a character by exact name within one game.
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
	 * Decode a row's sheet_data JSON field into an array in place, and cast is_npc to a real boolean.
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
	 * Encode a value for a JSON column, matching Plot::encode_json_field()'s exact contract: null stays null, an array or
	 * object is JSON-encoded, anything else is cast to string.
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
