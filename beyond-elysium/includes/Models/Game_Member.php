<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for chronicle role assignments.
 *
 * Game_Member is a Database\Manager CRUD model backed by the game_members
 * table. Each row assigns one WordPress user a single role - hst, ast,
 * narrator, or player - within one game. This model only stores the
 * assignment; what a role actually grants is defined separately in
 * includes/Database/game-roles.php and enforced by Authorization::check_request().
 */
class Game_Member {

	/** @var string[] Valid `role` values - must match game-roles.php's keys exactly. */
	public const VALID_ROLES = [ 'hst', 'ast', 'narrator', 'boons', 'player' ];

	/**
	 * Return every membership row for one game, ordered oldest first.
	 * Includes every role - hst, ast, narrator, and player - with no
	 * filtering and no pagination.
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

	/** @var string[] Roles eligible for staff assignment (1.1.0 §3.6) and S5's unassigned-post fallback. */
	public const STAFF_ROLES = [ 'hst', 'ast', 'narrator' ];

	/**
	 * Every hst/ast/narrator member of one game - who a plot or NPC may be assigned to
	 * (§3.6), and who an unassigned player post falls back to notifying (§3.5).
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
	 * Return every membership row for one WordPress user across every game they
	 * belong to, ordered oldest first. One row per game the user has any role
	 * in, regardless of which role it is.
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
	 * Look up one user's membership row in one game. Returns the row holding
	 * their assigned role, or null when they hold no membership in that game
	 * at all.
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
	 * Add or change a user's role in a game. Updates the existing membership row
	 * in place when one already exists, preserving its id and created_at rather
	 * than deleting and recreating it; inserts a new row otherwise.
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
	 * Grant `player` role if this user has no membership row in this game yet; a
	 * no-op otherwise. Never overwrites an existing row, so a user who already
	 * holds a higher role such as hst is never downgraded by this call.
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
	 * Returns the `wp_user_id` of every `player`-role member of a game who holds
	 * zero `active` characters in it - "no active character" and "only character
	 * has gone inactive" are the same condition, not two (queryable-player-
	 * inventory-design.md). A player with no characters at all and a player whose
	 * only character is retired/dead/pending both count: neither has an `active`
	 * one. `owner_type = 'chronicle'` matches every other per-game character
	 * count in this codebase (`Character::counts_by_stack_for_game()`, etc.).
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
	 * Remove a user's membership row for one game entirely, deleting whatever
	 * role - hst, ast, narrator, or player - they held. Does not affect their
	 * membership in any other game.
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
	 * Remove every membership row for a user across every game. Wired to
	 * WordPress's `deleted_user` action, which fires for both
	 * `wp_delete_user()` and `wpmu_delete_user()`, so a deleted account leaves
	 * no orphaned membership rows behind.
	 *
	 * @param int $wp_user_id
	 * @return void
	 */
	public static function remove_user_everywhere( int $wp_user_id ): void {
		Manager::delete( 'game_members', [ 'wp_user_id' => $wp_user_id ] );
	}
}
