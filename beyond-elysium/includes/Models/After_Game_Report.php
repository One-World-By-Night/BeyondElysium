<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for a player's own after-game report on one character at one
 * session (1.1.0 §3.14, A1) - what their character did, what they want next, and anything
 * for staff. A Storyteller reads and marks one read; they never edit a player's own words.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.14
 */
class After_Game_Report {

	/**
	 * Look up a single report by its primary key, or null when no row with that id exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'after_game_reports' ) . ' WHERE id = %d',
			$id
		);
	}

	/**
	 * The report a character already has for a session, or null when none exists yet - the
	 * unique key create()/update() both key off.
	 *
	 * @param int $session_id
	 * @param int $character_id
	 * @return object|null
	 */
	public static function find_for_session( int $session_id, int $character_id ): ?object {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'after_game_reports' ) . ' WHERE session_id = %d AND character_id = %d',
			$session_id,
			$character_id
		);
	}

	/**
	 * Every report for a session, oldest first.
	 *
	 * @param int $session_id
	 * @return object[]
	 */
	public static function for_session( int $session_id ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'after_game_reports' ) . ' WHERE session_id = %d ORDER BY created_at ASC, id ASC',
			$session_id
		);
	}

	/**
	 * The most recent session a character has ever filed a report for, or null if none.
	 *
	 * @param int $character_id
	 * @return string|null Y-m-d.
	 */
	public static function last_report_date( int $character_id ): ?string {
		return Manager::get_var(
			'SELECT s.game_date FROM ' . Manager::table( 'after_game_reports' ) . ' r'
				. ' INNER JOIN ' . Manager::table( 'game_sessions' ) . ' s ON s.id = r.session_id'
				. ' WHERE r.character_id = %d ORDER BY s.game_date DESC LIMIT 1',
			$character_id
		);
	}

	/**
	 * Whether a session has any report at all - `Game_Session::is_in_use()`'s own check.
	 *
	 * @param int $session_id
	 * @return bool
	 */
	public static function exists_for_session( int $session_id ): bool {
		return count( self::for_session( $session_id ) ) > 0;
	}

	/**
	 * The character ids with a report already filed for a session - `award_report_xp()`'s own
	 * recipient list.
	 *
	 * @param int $session_id
	 * @return int[]
	 */
	public static function character_ids_for_session( int $session_id ): array {
		return array_map( 'intval', array_column( self::for_session( $session_id ), 'character_id' ) );
	}

	/**
	 * Creates a new report. Returns the new row's id, or false when one already exists for
	 * this exact session and character (the caller checks `find_for_session()` first for a
	 * clean 409 rather than relying on this alone).
	 *
	 * @param array $data
	 * @return int|false
	 */
	public static function create( array $data ) {
		return Manager::insert( 'after_game_reports', [
			'game_id'      => (int) $data['game_id'],
			'session_id'   => (int) $data['session_id'],
			'character_id' => (int) $data['character_id'],
			'wp_user_id'   => (int) $data['wp_user_id'],
			'did'          => $data['did'] ?? null,
			'wants'        => $data['wants'] ?? null,
			'to_staff'     => $data['to_staff'] ?? null,
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		] );
	}

	/**
	 * Updates a report's own three text fields. Writes only the fields present in $data.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$allowed = [ 'did', 'wants', 'to_staff' ];
		$update  = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}
		if ( empty( $update ) ) {
			return true;
		}
		$update['updated_at'] = current_time( 'mysql' );
		return (bool) Manager::update( 'after_game_reports', $update, [ 'id' => $id ] );
	}

	/**
	 * Marks a report read by a Storyteller. Idempotent - re-marking an already-read report
	 * simply overwrites who/when, never errors.
	 *
	 * @param int $id
	 * @param int $read_by
	 * @return bool
	 */
	public static function mark_read( int $id, int $read_by ): bool {
		return (bool) Manager::update( 'after_game_reports', [
			'read_at' => current_time( 'mysql' ),
			'read_by' => $read_by,
		], [ 'id' => $id ] );
	}
}
