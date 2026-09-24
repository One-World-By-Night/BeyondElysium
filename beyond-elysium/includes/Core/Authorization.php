<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Central permission-check bridge for Beyond Elysium.
 */
class Authorization {

	/**
	 * The accessSchema client ID for this plugin.
	 */
	private const CLIENT_ID = 'beyond_elysium';

	/**
	 * Per-request memo of resolved accessSchema role-path checks, keyed by "{email}|{role_path}" with a true/false value.
	 *
	 * @var array<string,bool>
	 */
	private static array $asc_memo = [];

	/**
	 * The plugin's own REST requests currently being served, innermost last.
	 *
	 * @var \WP_REST_Request[]
	 */
	private static array $request_stack = [];

	/**
	 * Tracks which REST request is being served, so can() answers for the chronicle in that request's URL.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'reset_request_stack' ] );
		add_filter( 'rest_request_before_callbacks', [ self::class, 'push_request' ], 6, 3 );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'pop_request' ], 10, 3 );
	}

	/**
	 * Forgets every tracked request.
	 */
	public static function reset_request_stack(): void {
		self::$request_stack = [];
	}

	/**
	 * Records this plugin's REST request as the one being served.
	 *
	 * @param mixed            $response
	 * @param array            $handler
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function push_request( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request && strpos( (string) $request->get_route(), '/be/v1/' ) === 0 ) {
			self::$request_stack[] = $request;
		}
		return $response;
	}

	/**
	 * Drops the request push_request() recorded, once its callbacks are done.
	 *
	 * @param mixed            $response
	 * @param array            $handler
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function pop_request( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request && end( self::$request_stack ) === $request ) {
			array_pop( self::$request_stack );
		}
		return $response;
	}

	/**
	 * Whether the current user holds `$capability` in the chronicle of the REST request being served.
	 */
	public static function can( string $capability ): bool {
		$request = end( self::$request_stack );
		if ( $request instanceof \WP_REST_Request ) {
			return self::check_request( $capability, $request );
		}
		return current_user_can( $capability );
	}

	/**
	 * Reports whether accessSchema-based authorization is enabled for this site.
	 */
	public static function asc_enabled(): bool {
		return (bool) get_option( 'be_asc_enabled', false );
	}

	/**
	 * Chronicle-scoped permission check for a REST request.
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
	 * Resolves which of the given capabilities the current user holds for one chronicle.
	 *
	 * @param string[]         $capabilities
	 * @param \WP_REST_Request $request      Must carry `game_slug` among its URL params.
	 * @return array<string,bool>
	 */
	public static function capabilities_for_request( array $capabilities, \WP_REST_Request $request ): array {
		$result = [];
		foreach ( $capabilities as $capability ) {
			$result[ $capability ] = self::check_request( $capability, $request );
		}
		return $result;
	}

	/**
	 * Checks one email against one accessSchema role path, memoizing the result for the rest of the request.
	 */
	private static function check_asc_role_path( string $email, string $role_path ): bool {
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
	 * Normalizes an accessSchema role path to accessSchema's own slug format.
	 *
	 * @param string $role_path
	 * @return string
	 */
	private static function normalize_role_path( string $role_path ): string {
		return implode( '/', array_map( 'sanitize_title', explode( '/', $role_path ) ) );
	}

	/**
	 * Returns the chronicle roles, in game-roles.php's map order, whose grant list includes the given capability.
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
	 * Whether a WordPress account can use everything a chronicle role grants through the membership table.
	 *
	 * @param \WP_User $user
	 * @param string   $role
	 * @return bool
	 */
	public static function role_usable_by( \WP_User $user, string $role ): bool {
		foreach ( self::role_grants( $role ) as $capability ) {
			if ( ! user_can( $user, $capability ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns the capabilities the given chronicle role grants, from game-roles.php's map.
	 *
	 * @return string[]
	 */
	private static function role_grants( string $role ): array {
		return self::game_role_map()[ $role ] ?? [];
	}

	/**
	 * Loads and caches game-roles.php's role-to-capabilities map for the life of the request.
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
	 * Checks whether the current user has the given capability.
	 *
	 * @param string      $capability  WordPress capability name (e.g. 'be_manage_games').
	 * @param string|null $role_path   Optional accessSchema role path (e.g. 'Chronicle/KONY/HST').
	 * @return bool
	 */
	public static function check( string $capability, ?string $role_path = null ): bool {
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
	 * Builds a standard 403 WP_Error for a permission-denied REST response, using the given message or a generic default
	 * when none is supplied.
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
