<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for NPC casting.
 */
class Npc_Casting {

	/**
	 * Looks up a single casting by its primary key, or null when no row with that id exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'npc_castings' ) . ' WHERE id = %d',
			$id
		);
	}

	/**
	 * Every casting for one session, in the order they were made.
	 *
	 * @param int $session_id
	 * @return object[]
	 */
	public static function for_session( int $session_id ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'npc_castings' ) . ' WHERE session_id = %d ORDER BY id ASC',
			$session_id
		);
	}

	/**
	 * Whether this NPC is already cast at this session.
	 *
	 * @param int $session_id
	 * @param int $character_id
	 * @return bool
	 */
	public static function already_cast( int $session_id, int $character_id ): bool {
		return (bool) Manager::get_var(
			'SELECT id FROM ' . Manager::table( 'npc_castings' ) . ' WHERE session_id = %d AND character_id = %d',
			$session_id,
			$character_id
		);
	}

	/**
	 * A chronicle member's own castings for sessions today or later, joined with the session's game_date and the NPC's
	 * name.
	 *
	 * @param int    $wp_user_id
	 * @param int    $game_id
	 * @param string $today Y-m-d, the caller's own "today" so tests can pin it.
	 * @return object[]
	 */
	public static function upcoming_for_user( int $wp_user_id, int $game_id, string $today ): array {
		return Manager::get_results(
			'SELECT c.id AS casting_id, c.character_id, c.session_id, ch.name AS character_name,'
				. ' s.game_date, s.start_time, s.place'
				. ' FROM ' . Manager::table( 'npc_castings' ) . ' c'
				. ' INNER JOIN ' . Manager::table( 'game_sessions' ) . ' s ON s.id = c.session_id'
				. ' INNER JOIN ' . Manager::table( 'characters' ) . ' ch ON ch.id = c.character_id'
				. ' WHERE c.wp_user_id = %d AND c.game_id = %d AND s.game_date >= %s'
				. ' ORDER BY s.game_date ASC',
			$wp_user_id,
			$game_id,
			$today
		);
	}

	/**
	 * Creates a casting.
	 *
	 * @param array $data
	 * @return int|false
	 */
	public static function create( array $data ) {
		if ( empty( $data['session_id'] ) || empty( $data['character_id'] ) || empty( $data['wp_user_id'] ) ) {
			return false;
		}

		return Manager::insert( 'npc_castings', [
			'game_id'      => (int) $data['game_id'],
			'session_id'   => (int) $data['session_id'],
			'character_id' => (int) $data['character_id'],
			'wp_user_id'   => (int) $data['wp_user_id'],
			'brief'        => $data['brief'] ?? null,
			'created_by'   => $data['created_by'] ?? get_current_user_id(),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		] );
	}

	/**
	 * Updates a casting's wp_user_id and/or brief.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$update = [];
		if ( array_key_exists( 'wp_user_id', $data ) ) {
			$update['wp_user_id'] = (int) $data['wp_user_id'];
		}
		if ( array_key_exists( 'brief', $data ) ) {
			$update['brief'] = $data['brief'];
		}

		if ( empty( $update ) ) {
			return true;
		}
		$update['updated_at'] = current_time( 'mysql' );

		return (bool) Manager::update( 'npc_castings', $update, [ 'id' => $id ] );
	}

	/**
	 * Deletes a casting by id.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return (bool) Manager::delete( 'npc_castings', [ 'id' => $id ] );
	}

	/**
	 * Whether any casting exists for a session at all.
	 *
	 * @param int $session_id
	 * @return bool
	 */
	public static function exists_for_session( int $session_id ): bool {
		return count( self::for_session( $session_id ) ) > 0;
	}

	/**
	 * The one casting a chronicle member may read the brief for right now, or null when `$casting_id` doesn't belong to
	 * `$wp_user_id` or the access window has closed.
	 *
	 * @param int $wp_user_id
	 * @param int $casting_id
	 * @return object|null The casting row, with the session's game_date joined on as
	 *                      `game_date`, or null.
	 */
	public static function active_for( int $wp_user_id, int $casting_id ): ?object {
		$row = Manager::get_row(
			'SELECT c.*, s.game_date, s.start_time, s.place FROM ' . Manager::table( 'npc_castings' ) . ' c'
				. ' INNER JOIN ' . Manager::table( 'game_sessions' ) . ' s ON s.id = c.session_id'
				. ' WHERE c.id = %d AND c.wp_user_id = %d',
			$casting_id,
			$wp_user_id
		);
		if ( ! $row ) {
			return null;
		}

		$window_closes = strtotime( $row->game_date . ' +1 day 23:59:59' );
		if ( $window_closes === false || current_time( 'timestamp' ) > $window_closes ) {
			return null;
		}

		return $row;
	}
}
