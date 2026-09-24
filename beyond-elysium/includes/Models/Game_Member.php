<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for chronicle role assignments.
 */
class Game_Member {

	/** @var string[] Valid `role` values - must match game-roles.php's keys exactly. */
	public const VALID_ROLES = [ 'hst', 'ast', 'narrator', 'boons', 'player' ];

	/**
	 * Return every membership row for one game, ordered oldest first.
	 *
	 * @param int $game_id
	 * @return array
	 */
	public static function for_game( int $game_id ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'game_members' ) . ' WHERE game_id = %d ORDER BY created_at ASC',
			$game_id
		);
	}

	/**
	 * @var string[] Roles eligible for staff assignment and the unassigned-post fallback.
	 */
	public const STAFF_ROLES = [ 'hst', 'ast', 'narrator' ];

	/**
	 * Every hst/ast/narrator member of one game.
	 *
	 * @param int $game_id
	 * @return array
	 */
	public static function staff_for_game( int $game_id ): array {
		return array_values( array_filter(
			self::for_game( $game_id ),
			static fn( $member ) => in_array( $member->role, self::STAFF_ROLES, true )
		) );
	}

	/**
	 * The wp_user_id of every hst/ast/narrator member of one game, deduplicated.
	 *
	 * @param int $game_id
	 * @return int[]
	 */
	public static function staff_ids_for_game( int $game_id ): array {
		return array_values( array_unique( array_map(
			static fn( $member ) => (int) $member->wp_user_id,
			self::staff_for_game( $game_id )
		) ) );
	}

	/**
	 * Return every membership row for one WordPress user across every game they belong to, ordered oldest first.
	 *
	 * @param int $wp_user_id
	 * @return array
	 */
	public static function for_user( int $wp_user_id ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'game_members' ) . ' WHERE wp_user_id = %d ORDER BY created_at ASC',
			$wp_user_id
		);
	}

	/**
	 * Look up one user's membership row in one game.
	 *
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return object|null
	 */
	public static function find( int $game_id, int $wp_user_id ) {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'game_members' ) . ' WHERE game_id = %d AND wp_user_id = %d',
			$game_id,
			$wp_user_id
		);
	}

	/**
	 * Add or change a user's role in a game.
	 *
	 * @param int    $game_id
	 * @param int    $wp_user_id
	 * @param string $role One of VALID_ROLES.
	 * @return bool False on an invalid role or a failed write.
	 */
	public static function set_role( int $game_id, int $wp_user_id, string $role ): bool {
		if ( ! in_array( $role, self::VALID_ROLES, true ) ) {
			return false;
		}

		$existing = self::find( $game_id, $wp_user_id );
		if ( $existing ) {
			$result = Manager::update( 'game_members', [ 'role' => $role ], [ 'id' => (int) $existing->id ] );
			return $result !== false;
		}

		$id = Manager::insert(
			'game_members',
			[
				'game_id'    => $game_id,
				'wp_user_id' => $wp_user_id,
				'role'       => $role,
				'created_at' => current_time( 'mysql' ),
			]
		);

		return $id !== false;
	}

	/**
	 * Grant `player` role if this user has no membership row in this game yet.
	 *
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return void
	 */
	public static function ensure_player( int $game_id, int $wp_user_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Manager::table( 'game_members' ) . " (game_id, wp_user_id, role, created_at) VALUES (%d, %d, 'player', %s)",
				$game_id,
				$wp_user_id,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Returns the `wp_user_id` of every `player`-role member of a game who holds zero `active` characters in it.
	 *
	 * @param int    $game_id
	 * @param string $game_slug
	 * @return int[]
	 */
	public static function ids_without_active_character( int $game_id, string $game_slug ): array {
		global $wpdb;
		$members_table    = Manager::table( 'game_members' );
		$characters_table = Manager::table( 'characters' );

		$sql = $wpdb->prepare(
			"SELECT gm.wp_user_id
			FROM {$members_table} gm
			LEFT JOIN {$characters_table} c
				ON c.wp_user_id = gm.wp_user_id
				AND c.owner_type = 'chronicle'
				AND c.owner_slug = %s
				AND c.status = 'active'
			WHERE gm.game_id = %d AND gm.role = 'player'
			GROUP BY gm.wp_user_id
			HAVING COUNT(c.id) = 0",
			$game_slug,
			$game_id
		);

		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	/**
	 * Remove a user's membership row for one game entirely, deleting whatever role.
	 *
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return bool
	 */
	public static function remove( int $game_id, int $wp_user_id ): bool {
		$result = Manager::delete( 'game_members', [ 'game_id' => $game_id, 'wp_user_id' => $wp_user_id ] );
		return $result !== false;
	}

	/**
	 * Remove every membership row for a user across every game.
	 *
	 * @param int $wp_user_id
	 * @return void
	 */
	public static function remove_user_everywhere( int $wp_user_id ): void {
		Manager::delete( 'game_members', [ 'wp_user_id' => $wp_user_id ] );
	}
}
