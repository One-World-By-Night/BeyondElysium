<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Bot_Audit;
use BeyondElysium\Models\Bot_Connection;
use BeyondElysium\Models\Bot_Nonce;
use BeyondElysium\Models\Discord_Link;
use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a bot's signed calls and, for one acting as a linked user, dispatches as that user.
 */
class Bot_Auth {

	/**
	 * A timestamp this far from the server's clock is refused.
	 */
	public const MAX_CLOCK_DRIFT_SECONDS = 300;

	/**
	 * Every route a signed call may reach, as a regex with a named `game_slug` group, its allowed method, and
	 * whether it is a service call (no acting user) or a user call.
	 *
	 * @var array<int,array{pattern:string,method:string,kind:string}>
	 */
	private const ALLOWLIST = [
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/bot/status$#', 'method' => 'GET', 'kind' => 'service' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/bot/links$#', 'method' => 'POST', 'kind' => 'service' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/bot/links/(?P<discord_user_id>[^/]+)$#', 'method' => 'GET', 'kind' => 'service' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/bot/disconnect$#', 'method' => 'POST', 'kind' => 'service' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/my/characters$#', 'method' => 'GET', 'kind' => 'user' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/my/capabilities$#', 'method' => 'GET', 'kind' => 'user' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/characters$#', 'method' => 'GET', 'kind' => 'user' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)$#', 'method' => 'GET', 'kind' => 'user' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/sheet-document$#', 'method' => 'GET', 'kind' => 'user' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/discord-links$#', 'method' => 'GET', 'kind' => 'user' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/discord-links/(?P<id>\d+)/approve$#', 'method' => 'POST', 'kind' => 'user' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/discord-links/(?P<id>\d+)/decline$#', 'method' => 'POST', 'kind' => 'user' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/discord-links/(?P<id>\d+)/revoke$#', 'method' => 'POST', 'kind' => 'user' ],
		[ 'pattern' => '#^/be/v1/(?P<game_slug>[a-z0-9\-]+)/discord-links/mine$#', 'method' => 'DELETE', 'kind' => 'user' ],
	];

	/**
	 * The validated connection for a request that passed as a service call, keyed by its object id. Read by a
	 * service route's own permission callback.
	 *
	 * @var array<int,object>
	 */
	private static array $service_context = [];

	/**
	 * What a successfully-dispatched user call writes to the audit table once the response is known, keyed by the
	 * request's object id.
	 *
	 * @var array<int,array{connection_id:int,discord_user_id:string,wp_user_id:int,method:string,route:string}>
	 */
	private static array $pending_audit = [];

	/**
	 * Hooks the request lifecycle.
	 */
	public static function register(): void {
		add_filter( 'rest_pre_dispatch', [ self::class, 'handle' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'audit' ], 10, 3 );
	}

	/**
	 * Verifies a signed call and, for one acting as a linked user, switches the current user before WordPress's own
	 * route matching and permission check run.
	 *
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function handle( $result, $server, $request ) {
		$connection_id = $request->get_header( 'X-BE-Bot-Connection' );
		if ( $connection_id === null || $connection_id === '' ) {
			return $result;
		}

		$timestamp  = (string) $request->get_header( 'X-BE-Bot-Timestamp' );
		$nonce      = (string) $request->get_header( 'X-BE-Bot-Nonce' );
		$signature  = (string) $request->get_header( 'X-BE-Bot-Signature' );
		$discord_user_id = (string) ( $request->get_header( 'X-BE-Bot-User' ) ?? '' );

		$connection = Bot_Connection::find( (int) $connection_id );
		if ( ! $connection || $connection->revoked_at !== null ) {
			return self::refused( 'bot_auth_failed' );
		}

		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_CLOCK_DRIFT_SECONDS ) {
			return self::refused( 'bot_auth_failed' );
		}

		if ( $nonce === '' || ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
			return self::refused( 'bot_auth_failed' );
		}

		$canonical_query = Bot_Signing::canonical_query( $request->get_query_params() );
		if ( $canonical_query === null ) {
			return self::refused( 'bot_auth_failed' );
		}

		$canonical = Bot_Signing::canonical_string(
			$request->get_method(),
			$request->get_route(),
			$canonical_query,
			$timestamp,
			$nonce,
			(string) $connection_id,
			$discord_user_id,
			(string) $request->get_body()
		);

		$secret = Bot_Connection::secret_plain( $connection );
		if ( $secret === null || ! Bot_Signing::verify( $canonical, $secret, $signature ) ) {
			return self::refused( 'bot_auth_failed' );
		}

		if ( ! Bot_Nonce::claim( (int) $connection->id, $nonce ) ) {
			return self::refused( 'bot_auth_failed' );
		}

		$route = self::match_route( $request->get_method(), $request->get_route() );
		if ( $route === null ) {
			return self::refused( 'bot_route_not_allowed' );
		}

		$game = Game::find( (int) $connection->game_id );
		if ( ! $game || ( $route['game_slug'] !== (string) $game->slug ) ) {
			return self::refused( 'bot_wrong_chronicle' );
		}

		$is_service_call = $discord_user_id === '';
		if ( $is_service_call !== ( $route['kind'] === 'service' ) ) {
			return self::refused( 'bot_route_not_allowed' );
		}

		Bot_Connection::touch_last_seen( (int) $connection->id );

		if ( $is_service_call ) {
			self::$service_context[ spl_object_id( $request ) ] = $connection;
			return $result;
		}

		$link = Discord_Link::active_for_discord_user( (int) $connection->game_id, $discord_user_id );
		if ( ! $link || $link->status !== Discord_Link::APPROVED || ! $link->wp_user_id ) {
			return self::refused( 'bot_user_not_linked' );
		}

		wp_set_current_user( (int) $link->wp_user_id );

		self::$pending_audit[ spl_object_id( $request ) ] = [
			'connection_id'    => (int) $connection->id,
			'discord_user_id'  => $discord_user_id,
			'wp_user_id'       => (int) $link->wp_user_id,
			'method'           => $request->get_method(),
			'route'            => $request->get_route(),
		];

		return $result;
	}

	/**
	 * Writes a completed user call to the audit table, when it was not a GET.
	 *
	 * @param mixed              $result
	 * @param array<string,mixed> $handler
	 * @param \WP_REST_Request   $request
	 * @return mixed
	 */
	public static function audit( $result, $handler, $request ) {
		$key    = spl_object_id( $request );
		$pending = self::$pending_audit[ $key ] ?? null;
		unset( self::$pending_audit[ $key ], self::$service_context[ $key ] );

		if ( $pending === null || $pending['method'] === 'GET' ) {
			return $result;
		}

		if ( $result instanceof \WP_REST_Response ) {
			$status = $result->get_status();
		} elseif ( $result instanceof \WP_Error ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		} else {
			$status = 0;
		}

		Bot_Audit::record(
			$pending['connection_id'],
			$pending['discord_user_id'],
			$pending['wp_user_id'],
			$pending['method'],
			$pending['route'],
			$status
		);

		return $result;
	}

	/**
	 * The validated connection for a request that passed the allowlist as a service call, or null.
	 *
	 * @param \WP_REST_Request $request
	 * @return object|null
	 */
	public static function service_context( $request ): ?object {
		return self::$service_context[ spl_object_id( $request ) ] ?? null;
	}

	/**
	 * @param string $method
	 * @param string $route
	 * @return array{game_slug:string,kind:string}|null
	 */
	private static function match_route( string $method, string $route ): ?array {
		foreach ( self::ALLOWLIST as $entry ) {
			if ( $entry['method'] !== $method ) {
				continue;
			}
			if ( preg_match( $entry['pattern'], $route, $matches ) ) {
				return [ 'game_slug' => $matches['game_slug'], 'kind' => $entry['kind'] ];
			}
		}
		return null;
	}

	/**
	 * @param string $code
	 * @return \WP_Error
	 */
	private static function refused( string $code ): \WP_Error {
		$messages = [
			'bot_auth_failed'        => __( 'This call could not be verified.', 'beyond-elysium' ),
			'bot_route_not_allowed'  => __( 'This route is not open to a bot.', 'beyond-elysium' ),
			'bot_wrong_chronicle'    => __( 'This connection belongs to a different chronicle.', 'beyond-elysium' ),
			'bot_user_not_linked'    => __( 'That Discord account is not linked and approved in this chronicle.', 'beyond-elysium' ),
		];
		$status = $code === 'bot_auth_failed' ? 401 : 403;
		return new \WP_Error( $code, $messages[ $code ] ?? $code, [ 'status' => $status ] );
	}
}
