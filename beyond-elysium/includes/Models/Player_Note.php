<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * A chronicle's private note about one of its players, for its Storytellers alone. It belongs to the person and the
 * chronicle, not to a membership, so it outlives a player who leaves.
 */
class Player_Note {

	/**
	 * The most characters a note holds.
	 */
	public const MAX_LENGTH = 10000;

	/**
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return object|null
	 */
	public static function find( int $game_id, int $wp_user_id ): ?object {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'player_notes' ) . ' WHERE game_id = %d AND wp_user_id = %d',
			$game_id,
			$wp_user_id
		);
	}

	/**
	 * Saves a note, replacing the one there; an empty note removes it.
	 *
	 * @param int    $game_id
	 * @param int    $wp_user_id
	 * @param string $notes
	 * @param int    $updated_by
	 * @return bool
	 */
	public static function save( int $game_id, int $wp_user_id, string $notes, int $updated_by ): bool {
		if ( trim( $notes ) === '' ) {
			return self::delete( $game_id, $wp_user_id );
		}
		$now = current_time( 'mysql' );
		if ( self::find( $game_id, $wp_user_id ) ) {
			return Manager::update(
				'player_notes',
				[ 'notes' => $notes, 'updated_by' => $updated_by, 'updated_at' => $now ],
				[ 'game_id' => $game_id, 'wp_user_id' => $wp_user_id ]
			) !== false;
		}
		return (bool) Manager::insert( 'player_notes', [
			'game_id'    => $game_id,
			'wp_user_id' => $wp_user_id,
			'notes'      => $notes,
			'updated_by' => $updated_by,
			'updated_at' => $now,
		] );
	}

	/**
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return bool
	 */
	public static function delete( int $game_id, int $wp_user_id ): bool {
		return Manager::delete( 'player_notes', [ 'game_id' => $game_id, 'wp_user_id' => $wp_user_id ] ) !== false;
	}

	/**
	 * The people a chronicle holds a note about.
	 *
	 * @param int $game_id
	 * @return int[]
	 */
	public static function user_ids_for_game( int $game_id ): array {
		$rows = Manager::get_results( 'SELECT wp_user_id FROM ' . Manager::table( 'player_notes' ) . ' WHERE game_id = %d', $game_id );
		return array_map( 'intval', array_column( $rows, 'wp_user_id' ) );
	}

	/**
	 * Every note a person has, in every chronicle: removed when their account is.
	 *
	 * @param int $wp_user_id
	 * @return void
	 */
	public static function remove_user_everywhere( int $wp_user_id ): void {
		Manager::delete( 'player_notes', [ 'wp_user_id' => $wp_user_id ] );
	}
}
