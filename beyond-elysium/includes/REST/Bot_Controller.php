<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Bot_Auth;
use BeyondElysium\Models\Bot_Connect_Code;
use BeyondElysium\Models\Bot_Connection;
use BeyondElysium\Models\Discord_Link;
use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for connecting a chronicle's Discord server, and the bot's own service calls.
 */
class Bot_Controller extends Base_Controller {

	protected $rest_base = 'bot';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/bot/connect', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'connect' ],
				'permission_callback' => '__return_true',
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/bot/connect-codes', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'generate_code' ],
				'permission_callback' => $this->permission( 'be_manage_chronicle_setup' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/bot/connection', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_connection' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'disconnect_from_site' ],
				'permission_callback' => $this->permission( 'be_manage_chronicle_setup' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/bot/status', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => $this->permission_bot_service(),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/bot/links', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'start_link' ],
				'permission_callback' => $this->permission_bot_service(),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/bot/links/(?P<discord_user_id>[^/]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'lookup_link' ],
				'permission_callback' => $this->permission_bot_service(),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/bot/disconnect', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'disconnect_from_bot' ],
				'permission_callback' => $this->permission_bot_service(),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/bot/notices', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_notices' ],
				'permission_callback' => $this->permission_bot_service(),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/bot/notices/ack', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'ack_notices' ],
				'permission_callback' => $this->permission_bot_service(),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/bot/approved-links', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_approved_links' ],
				'permission_callback' => $this->permission_bot_service(),
			],
		] );
	}

	/**
	 * Connects a server to the game named by a live code. Not game-scoped and needs no authentication: the code
	 * names the game.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function connect( $request ) {
		$code       = (string) $request->get_param( 'code' );
		$guild_id   = (string) $request->get_param( 'guild_id' );
		$guild_name = (string) $request->get_param( 'guild_name' );

		if ( $code === '' || $guild_id === '' ) {
			return $this->invalid_code_error();
		}

		$resolved = Bot_Connect_Code::consume( $code );
		if ( $resolved === null ) {
			return $this->invalid_code_error();
		}

		$game = Game::find( $resolved['game_id'] );
		if ( ! $game ) {
			return $this->invalid_code_error();
		}

		if ( Bot_Connection::active_for_game( $resolved['game_id'] ) ) {
			return $this->error(
				'already_connected',
				__( "This chronicle already has a connected Discord server. Its HST can disconnect it in Chronicle Setup first.", 'beyond-elysium' ),
				409
			);
		}

		$connected = Bot_Connection::connect( $resolved['game_id'], $guild_id, $guild_name, $resolved['created_by'] );
		if ( ! $connected ) {
			return $this->error( 'connect_failed', __( 'Could not connect. Try again.', 'beyond-elysium' ), 500 );
		}

		return $this->success( [
			'connection_id' => $connected['id'],
			'secret'        => $connected['secret'],
			'rest_root'     => rest_url(),
			'game'          => [ 'slug' => $game->slug, 'name' => $game->name ],
			'be_version'    => defined( 'BE_VERSION' ) ? BE_VERSION : '',
		], 201 );
	}

	/**
	 * @return \WP_Error
	 */
	private function invalid_code_error(): \WP_Error {
		return $this->error( 'invalid_code', __( "That code didn't work. Codes last 15 minutes and work once; make a new one in Chronicle Setup.", 'beyond-elysium' ), 400 );
	}

	/**
	 * Generates a connect code for a chronicle, replacing any live code already there.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_code( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$code = Bot_Connect_Code::generate( (int) $game->id, get_current_user_id() );
		if ( $code === null ) {
			return $this->error( 'generate_failed', __( 'Could not generate a code. Try again.', 'beyond-elysium' ), 500 );
		}

		return $this->success( [
			'code'       => self::formatted_code( $code ),
			'expires_in' => Bot_Connect_Code::TTL_SECONDS,
			'connect_command' => sprintf( '/connect site:%s code:%s', home_url( '/' ), $code ),
			'invite_url' => get_site_option( 'be_discord_invite_url', '' ),
		], 201 );
	}

	/**
	 * @param string $code
	 * @return string
	 */
	private static function formatted_code( string $code ): string {
		return implode( '-', str_split( $code, 4 ) );
	}

	/**
	 * A chronicle's connection status.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_connection( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$connection = Bot_Connection::active_for_game( (int) $game->id );

		return $this->success( [
			'connected'     => $connection !== null,
			'guild_name'    => $connection->guild_name ?? null,
			'connected_at'  => $connection->created_at ?? null,
			'last_seen_at'  => $connection->last_seen_at ?? null,
		] );
	}

	/**
	 * Disconnects a chronicle's Discord server, from the site.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function disconnect_from_site( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$connection = Bot_Connection::active_for_game( (int) $game->id );
		if ( ! $connection ) {
			return $this->success( [ 'connected' => false ] );
		}

		Bot_Connection::disconnect( (int) $connection->id, get_current_user_id() );
		return $this->success( [ 'connected' => false ] );
	}

	/**
	 * A chronicle's version and feature set, read by the bot after connecting.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_status( $request ) {
		$connection = Bot_Auth::service_context( $request );
		if ( ! $connection ) {
			return $this->error( 'bot_auth_failed', __( 'This call could not be verified.', 'beyond-elysium' ), 401 );
		}
		$game = Game::find( (int) $connection->game_id );

		return $this->success( [
			'be_version' => defined( 'BE_VERSION' ) ? BE_VERSION : '',
			'features'   => [ 'links', 'sheet_document' ],
			'game'       => $game ? [ 'slug' => $game->slug, 'name' => $game->name ] : null,
			'connection' => [
				'id'         => (int) $connection->id,
				'guild_id'   => $connection->guild_id,
				'guild_name' => $connection->guild_name,
				'created_at' => $connection->created_at,
			],
		] );
	}

	/**
	 * Starts, or reports, a Discord link request.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_link( $request ) {
		$connection = Bot_Auth::service_context( $request );
		if ( ! $connection ) {
			return $this->error( 'bot_auth_failed', __( 'This call could not be verified.', 'beyond-elysium' ), 401 );
		}

		$discord_user_id  = (string) $request->get_param( 'discord_user_id' );
		$discord_username = (string) $request->get_param( 'discord_username' );
		if ( $discord_user_id === '' ) {
			return $this->error( 'invalid_request', __( 'discord_user_id is required.', 'beyond-elysium' ), 400 );
		}

		$result = Discord_Link::start_or_renew( (int) $connection->game_id, $discord_user_id, $discord_username );

		if ( $result['status'] === Discord_Link::REQUESTED ) {
			$game = Game::find( (int) $connection->game_id );
			if ( $game ) {
				$result['confirm_url'] = \BeyondElysium\Services\Chronicle_Players::discord_confirm_link( $game, $result['token'] );
			}
			unset( $result['token'] );
		}

		return $this->success( $result, 201 );
	}

	/**
	 * Looks up a Discord user's link in a chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup_link( $request ) {
		$connection = Bot_Auth::service_context( $request );
		if ( ! $connection ) {
			return $this->error( 'bot_auth_failed', __( 'This call could not be verified.', 'beyond-elysium' ), 401 );
		}

		$discord_user_id = (string) $request['discord_user_id'];
		$link = Discord_Link::active_for_discord_user( (int) $connection->game_id, $discord_user_id );

		if ( ! $link ) {
			return $this->success( [ 'status' => 'none', 'link_id' => null, 'wp_user' => null, 'role' => null, 'staff' => false, 'characters' => [] ] );
		}

		$wp_user = $link->wp_user_id ? get_userdata( (int) $link->wp_user_id ) : false;
		$game    = Game::find( (int) $connection->game_id );
		$member  = ( $wp_user && $game ) ? \BeyondElysium\Models\Game_Member::find( (int) $game->id, (int) $link->wp_user_id ) : null;
		$characters = array_map(
			static fn( $c ) => [ 'id' => (int) $c->id, 'name' => $c->name, 'stack_slug' => $c->stack_slug, 'status' => $c->status ],
			Discord_Link::live_characters( $link )
		);

		return $this->success( [
			'status'     => $link->status,
			'link_id'    => (int) $link->id,
			'wp_user'    => $wp_user ? [ 'id' => (int) $wp_user->ID, 'display_name' => $wp_user->display_name ] : null,
			'role'       => $member ? $member->role : null,
			'staff'      => $link->wp_user_id ? Discord_Link::is_staff( (int) $connection->game_id, (int) $link->wp_user_id ) : false,
			'characters' => $characters,
		] );
	}

	/**
	 * Disconnects a chronicle's Discord server, from the bot.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function disconnect_from_bot( $request ) {
		$connection = Bot_Auth::service_context( $request );
		if ( ! $connection ) {
			return $this->error( 'bot_auth_failed', __( 'This call could not be verified.', 'beyond-elysium' ), 401 );
		}
		Bot_Connection::disconnect( (int) $connection->id, null );
		return $this->success( [ 'connected' => false ] );
	}

	/**
	 * The chronicle's own undelivered notices, oldest first.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_notices( $request ) {
		$connection = Bot_Auth::service_context( $request );
		if ( ! $connection ) {
			return $this->error( 'bot_auth_failed', __( 'This call could not be verified.', 'beyond-elysium' ), 401 );
		}

		$notices = \BeyondElysium\Models\Bot_Notice::undelivered_for_game( (int) $connection->game_id );

		return $this->success( array_map(
			static fn( $n ) => [
				'id'         => (int) $n->id,
				'wp_user_id' => $n->wp_user_id,
				'kind'       => $n->kind,
				'payload'    => $n->payload,
				'created_at' => $n->created_at,
			],
			$notices
		) );
	}

	/**
	 * Marks the given notice ids delivered, scoped to this connection's own chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ack_notices( $request ) {
		$connection = Bot_Auth::service_context( $request );
		if ( ! $connection ) {
			return $this->error( 'bot_auth_failed', __( 'This call could not be verified.', 'beyond-elysium' ), 401 );
		}

		$ids = array_map( 'intval', (array) $request->get_param( 'ids' ) );
		$marked = \BeyondElysium\Models\Bot_Notice::mark_delivered( (int) $connection->game_id, $ids );

		return $this->success( [ 'marked' => $marked ] );
	}

	/**
	 * Every approved Discord link in this chronicle, for the bot's own routing - no acting user, since no single
	 * Storyteller is "logged in" when the bot itself decides where a notice should go.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_approved_links( $request ) {
		$connection = Bot_Auth::service_context( $request );
		if ( ! $connection ) {
			return $this->error( 'bot_auth_failed', __( 'This call could not be verified.', 'beyond-elysium' ), 401 );
		}

		$links = Discord_Link::for_game( (int) $connection->game_id, Discord_Link::APPROVED );

		return $this->success( array_map(
			static fn( $link ) => [
				'wp_user_id'       => (int) $link->wp_user_id,
				'discord_user_id'  => $link->discord_user_id,
				'room_channel_id'  => $link->room_channel_id,
			],
			$links
		) );
	}

	/**
	 * Builds a permission callback that passes only for a request Bot_Auth has already validated as a service call.
	 *
	 * @return callable
	 */
	private function permission_bot_service(): callable {
		return function ( \WP_REST_Request $request ) {
			return Bot_Auth::service_context( $request ) !== null
				? true
				: new \WP_Error( 'bot_auth_failed', __( 'This call could not be verified.', 'beyond-elysium' ), [ 'status' => 401 ] );
		};
	}

	/**
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	protected function resolve_game( string $game_slug ) {
		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		return $game;
	}
}
