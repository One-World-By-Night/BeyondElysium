<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * A signed-in account asking to join a chronicle, open until a Storyteller approves or refuses it, or the account
 * withdraws it.
 */
class Join_Request {

	const STATUSES = [ 'waiting', 'approved', 'refused', 'withdrawn' ];

	/**
	 * Whether a chronicle takes join requests - absent reads as on.
	 *
	 * @param object $game
	 * @return bool
	 */
	public static function open_on( object $game ): bool {
		return ! isset( $game->settings->join_requests ) || (bool) $game->settings->join_requests;
	}

	/**
	 * Look up a single request by its primary key, or null when no row with that id exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return Manager::get_row( 'SELECT * FROM ' . Manager::table( 'join_requests' ) . ' WHERE id = %d', $id );
	}

	/**
	 * This account's waiting request on this chronicle, or null when it has none.
	 *
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return object|null
	 */
	public static function find_waiting( int $game_id, int $wp_user_id ): ?object {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'join_requests' ) . " WHERE game_id = %d AND wp_user_id = %d AND status = 'waiting'",
			$game_id,
			$wp_user_id
		);
	}

	/**
	 * Every request on a chronicle, newest first.
	 *
	 * @param int         $game_id
	 * @param string|null $status Narrows to one status; null for every status.
	 * @return object[]
	 */
	public static function for_game( int $game_id, ?string $status = null ): array {
		if ( $status !== null ) {
			return Manager::get_results(
				'SELECT * FROM ' . Manager::table( 'join_requests' ) . ' WHERE game_id = %d AND status = %s ORDER BY created_at DESC',
				$game_id,
				$status
			);
		}
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'join_requests' ) . ' WHERE game_id = %d ORDER BY created_at DESC',
			$game_id
		);
	}

	/**
	 * Opens a new waiting request. Refused with `false` when this account already has one waiting on this chronicle.
	 *
	 * @param int    $game_id
	 * @param int    $wp_user_id
	 * @param string $message
	 * @return int|false New row id, or false when one is already waiting or the write failed.
	 */
	public static function open( int $game_id, int $wp_user_id, string $message ) {
		if ( self::find_waiting( $game_id, $wp_user_id ) !== null ) {
			return false;
		}
		return Manager::insert( 'join_requests', [
			'game_id'    => $game_id,
			'wp_user_id' => $wp_user_id,
			'message'    => $message,
			'status'     => 'waiting',
			'created_at' => current_time( 'mysql' ),
		] );
	}

	/**
	 * How many requests one account opened on one chronicle within the last day, whatever became of them.
	 *
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return int
	 */
	public static function count_opened_today( int $game_id, int $wp_user_id ): int {
		return (int) Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'join_requests' ) . ' WHERE game_id = %d AND wp_user_id = %d AND created_at >= %s',
			$game_id,
			$wp_user_id,
			wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		);
	}

	/**
	 * Ties a request to the character started for it.
	 *
	 * @param int $id
	 * @param int $character_id
	 * @return bool
	 */
	public static function tie_character( int $id, int $character_id ): bool {
		return Manager::update( 'join_requests', [ 'character_id' => $character_id ], [ 'id' => $id ] ) !== false;
	}

	/**
	 * Ties a request to the Grapevine file submitted for it.
	 *
	 * @param int $id
	 * @param int $submission_id
	 * @return bool
	 */
	public static function tie_submission( int $id, int $submission_id ): bool {
		return Manager::update( 'join_requests', [ 'submission_id' => $submission_id ], [ 'id' => $id ] ) !== false;
	}

	/**
	 * Marks a waiting request approved.
	 *
	 * @param int $id
	 * @param int $reviewed_by
	 * @return bool
	 */
	public static function approve( int $id, int $reviewed_by ): bool {
		return Manager::update( 'join_requests', [
			'status'      => 'approved',
			'reviewed_by' => $reviewed_by,
			'reviewed_at' => current_time( 'mysql' ),
		], [ 'id' => $id ] ) !== false;
	}

	/**
	 * Marks a waiting request refused, with an optional note explaining why.
	 *
	 * @param int         $id
	 * @param int         $reviewed_by
	 * @param string|null $note
	 * @return bool
	 */
	public static function refuse( int $id, int $reviewed_by, ?string $note = null ): bool {
		return Manager::update( 'join_requests', [
			'status'      => 'refused',
			'note'        => $note,
			'reviewed_by' => $reviewed_by,
			'reviewed_at' => current_time( 'mysql' ),
		], [ 'id' => $id ] ) !== false;
	}

	/**
	 * Marks a waiting request withdrawn by the applicant.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function withdraw( int $id ): bool {
		return Manager::update( 'join_requests', [ 'status' => 'withdrawn' ], [ 'id' => $id ] ) !== false;
	}
}
