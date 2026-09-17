<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for who signed in at a game session (1.1.0 §3.1).
 *
 * A row names either a real, active character in this chronicle (`character_id`) or a visitor
 * recorded by name and home chronicle (`visitor_name`/`visitor_chronicle`) - never both, and
 * never neither. `session_character`'s UNIQUE key allows any number of visitor rows per
 * session (MySQL treats each NULL `character_id` as distinct) while still refusing the same
 * character twice at one session.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.1
 */
class Attendance {

	/**
	 * Every attendance row for a session, in the order they signed in.
	 *
	 * @param int $session_id
	 * @return object[]
	 */
	public static function for_session( int $session_id ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'attendance' ) . ' WHERE session_id = %d ORDER BY id ASC',
			$session_id
		);
	}

	/**
	 * The character ids who signed in at a session - visitor rows excluded, since they name no
	 * real character. Used by award-attendance-xp to resolve who actually gets the award.
	 *
	 * @param int $session_id
	 * @return int[]
	 */
	public static function character_ids_for_session( int $session_id ): array {
		$rows = Manager::get_results(
			'SELECT character_id FROM ' . Manager::table( 'attendance' )
				. ' WHERE session_id = %d AND character_id IS NOT NULL',
			$session_id
		);
		return array_map( 'intval', array_column( $rows, 'character_id' ) );
	}

	/**
	 * Records a sign-in: either a real character (character_id) or a visitor (visitor_name,
	 * optionally visitor_chronicle), never both. Returns the new row's id, or false when
	 * neither shape is present or the insert fails (including the same character signing in
	 * twice at this session - the caller checks character_ids_for_session() first for a clean
	 * 409 rather than relying on this alone).
	 *
	 * @param int   $session_id
	 * @param int   $game_id
	 * @param array $data
	 * @return int|false
	 */
	public static function record( int $session_id, int $game_id, array $data ) {
		$character_id = ! empty( $data['character_id'] ) ? (int) $data['character_id'] : null;
		$visitor_name = $data['visitor_name'] ?? null;

		if ( ! $character_id && ! $visitor_name ) {
			return false;
		}

		return Manager::insert( 'attendance', [
			'session_id'        => $session_id,
			'game_id'           => $game_id,
			'character_id'      => $character_id,
			'visitor_name'      => $character_id ? null : $visitor_name,
			'visitor_chronicle' => $character_id ? null : ( $data['visitor_chronicle'] ?? null ),
			'recorded_by'       => $data['recorded_by'] ?? get_current_user_id(),
			'created_at'        => current_time( 'mysql' ),
		] );
	}

	/**
	 * Removes one attendance row by id.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function remove( int $id ): bool {
		return (bool) Manager::delete( 'attendance', [ 'id' => $id ] );
	}

	/**
	 * Looks up a single attendance row by its primary key, or null when none exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'attendance' ) . ' WHERE id = %d',
			$id
		);
	}

	/**
	 * The most recent game_date a character was recorded present at, across every session in
	 * their chronicle, or null if they have never signed in. Used by the spotlight check
	 * (§3.14) to find who hasn't been seen in a while.
	 *
	 * @param int $character_id
	 * @return string|null Y-m-d, or null.
	 */
	public static function last_attended_date( int $character_id ): ?string {
		return Manager::get_var(
			'SELECT s.game_date FROM ' . Manager::table( 'attendance' ) . ' a'
				. ' INNER JOIN ' . Manager::table( 'game_sessions' ) . ' s ON s.id = a.session_id'
				. ' WHERE a.character_id = %d ORDER BY s.game_date DESC LIMIT 1',
			$character_id
		);
	}
}
