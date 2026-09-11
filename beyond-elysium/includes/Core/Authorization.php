<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Central permission-check bridge for Beyond Elysium. Verifies a
 * capability against accessSchema's chronicle-scoped roles first, when
 * enabled for the site and the request names a game, falling back to
 * WordPress's own current_user_can() otherwise. Used by every REST
 * controller's permission callback and by any other code that needs to
 * gate an action.
 */
class Authorization {

	/**
	 * The accessSchema client ID for this plugin.
	 */
	private const CLIENT_ID = 'beyond_elysium';

	/**
	 * Per-request memo of resolved accessSchema role-path checks, keyed by
	 * "{email}|{role_path}" with a true/false value. Cleared at the start
	 * of each PHP request; not a persistent cache.
	 *
	 * @var array<string,bool>
	 */
	private static array $asc_memo = [];

	/**
	 * Reports whether accessSchema-based authorization is enabled for this
	 * site. Reads the be_asc_enabled option, defaulting to false when the
	 * option has never been set.
	 */
	public static function asc_enabled(): bool {
		return (bool) get_option( 'be_asc_enabled', false );
	}

	/**
	 * Chronicle-scoped permission check for a REST request. Resolution
	 * order:
	 *
	 *  1. Not logged in -> deny.
	 *  2. Holds `be_manage_games` -> allow, regardless of route.
	 *  3. No `game_slug` in the request's URL params -> plain
	 *     `current_user_can( $capability )`.
	 *  4. Game resolved, accessSchema enabled, and the game has an
	 *     `asc_role_path` -> for each chronicle role that grants
	 *     `$capability`, check "{asc_role_path}/{ROLE}" against
	 *     accessSchema; any true result allows.
	 *  5. `current_user_can( $capability )` false -> deny.
	 *  6. `$allow_bootstrap` true and the game resolved -> allow without
	 *     requiring a membership row.
	 *  7. Otherwise -> allow only if a `be_game_members` row exists for
	 *     this user and game whose role grants `$capability`; a
	 *     `game_slug` that does not resolve to a real game can never have
	 *     one, so this always denies in that case.
	 *
	 * `game_slug` is read only from `$request->get_url_params()`, never
	 * from `$request['game_slug']` or `get_param()`.
	 *
	 * @param string           $capability WordPress capability being checked.
	 * @param \WP_REST_Request $request
	 * @param bool             $allow_bootstrap Waive the membership-row requirement (not
	 *                                          the capability check) when the game is real
	 *                                          but the user has no membership row yet.
	 * @return bool
	 */
	public static function check_request( string $capability, \WP_REST_Request $request, bool $allow_bootstrap = false ): bool {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return false;
		}

		if ( current_user_can( 'be_manage_games' ) ) {
			return true;
		}

		$url_params = $request->get_url_params();
		$game_slug  = $url_params['game_slug'] ?? null;

		if ( ! $game_slug ) {
			return current_user_can( $capability );
		}

		$game    = \BeyondElysium\Models\Game::find_by_slug( (string) $game_slug );
		$game_id = $game ? (int) $game->id : 0;

		if ( self::asc_enabled() && $game && ! empty( $game->asc_role_path ) && function_exists( 'owc_asc_check_access' ) ) {
			foreach ( self::roles_granting( $capability ) as $role ) {
				$role_path = self::normalize_role_path( rtrim( (string) $game->asc_role_path, '/' ) . '/' . $role );
				if ( self::check_asc_role_path( $user->user_email, $role_path ) ) {
					return true;
				}
			}
		}

		if ( ! current_user_can( $capability ) ) {
			return false;
		}

		if ( $allow_bootstrap && $game ) {
			return true;
		}

		$member = $game_id ? \BeyondElysium\Models\Game_Member::find( $game_id, $user->ID ) : null;
		if ( ! $member ) {
			return false;
		}

		return in_array( $capability, self::role_grants( $member->role ), true );
	}

	/**
	 * Checks one email against one accessSchema role path, memoizing the
	 * result for the rest of the request. Returns false immediately if the
	 * accessSchema integration is not available.
	 */
	private static function check_asc_role_path( string $email, string $role_path ): bool {
		// Guards against direct calls that skip the caller's own function_exists() check.
		if ( ! function_exists( 'owc_asc_check_access' ) ) {
			return false;
		}

		$memo_key = $email . '|' . $role_path;

		if ( ! array_key_exists( $memo_key, self::$asc_memo ) ) {
			$result = owc_asc_check_access( self::CLIENT_ID, $email, $role_path, true );
			self::$asc_memo[ $memo_key ] = ( $result === true );
		}

		return self::$asc_memo[ $memo_key ];
	}

	/**
	 * Normalizes an accessSchema role path to match accessSchema's own
	 * slug format: each `/`-separated segment run through the same
	 * `sanitize_title()` call accessSchema itself uses when a role path is
	 * registered, regardless of how the stored path is cased or
	 * punctuated.
	 *
	 * @param string $role_path
	 * @return string
	 */
	private static function normalize_role_path( string $role_path ): string {
		return implode( '/', array_map( 'sanitize_title', explode( '/', $role_path ) ) );
	}

	/**
	 * Returns the chronicle roles, in game-roles.php's map order, whose
	 * grant list includes the given capability. Used to build the list of
	 * accessSchema role paths to check for one capability.
	 *
	 * @return string[]
	 */
	private static function roles_granting( string $capability ): array {
		$roles = [];
		foreach ( self::game_role_map() as $role => $grants ) {
			if ( in_array( $capability, $grants, true ) ) {
				$roles[] = $role;
			}
		}
		return $roles;
	}

	/**
	 * Returns the capabilities the given chronicle role grants, from
	 * game-roles.php's map. An unrecognized role returns an empty array,
	 * granting nothing.
	 *
	 * @return string[]
	 */
	private static function role_grants( string $role ): array {
		return self::game_role_map()[ $role ] ?? [];
	}

	/**
	 * Loads and caches game-roles.php's role-to-capabilities map for the
	 * life of the request. Reads the file from disk only on the first
	 * call; every later call returns the same cached array.
	 *
	 * @return array<string,string[]>
	 */
	private static function game_role_map(): array {
		static $map = null;
		if ( $map === null ) {
			$map = require dirname( __DIR__ ) . '/Database/game-roles.php';
		}
		return $map;
	}

	/**
	 * Checks whether the current user has the given capability. When a
	 * role path is supplied and accessSchema is available, checks that
	 * role path first and returns its result if it resolves to a boolean;
	 * otherwise falls back to WordPress's current_user_can().
	 *
	 * @param string      $capability  WordPress capability name (e.g. 'be_manage_games').
	 * @param string|null $role_path   Optional accessSchema role path (e.g. 'Chronicle/KONY/HST').
	 * @return bool
	 */
	public static function check( string $capability, ?string $role_path = null ): bool {
		// A logged-out visitor is a WP_User whose exists() is false, never null.
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return false;
		}

		// Checks the accessSchema role path first, if one is provided.
		if ( $role_path && function_exists( 'owc_asc_check_access' ) ) {
			$result = owc_asc_check_access(
				self::CLIENT_ID,
				$user->user_email,
				$role_path,
				true
			);

			if ( is_bool( $result ) ) {
				return $result;
			}
		}

		// Falls back to the WordPress capability check.
		return current_user_can( $capability );
	}

	/**
	 * Builds a standard 403 WP_Error for a permission-denied REST
	 * response, using the given message or a generic default when none is
	 * supplied.
	 *
	 * @param string $message Optional custom message.
	 * @return \WP_Error
	 */
	public static function denied( string $message = 'You do not have permission to perform this action.' ): \WP_Error {
		return new \WP_Error(
			'rest_forbidden',
			$message,
			[ 'status' => 403 ]
		);
	}
}
