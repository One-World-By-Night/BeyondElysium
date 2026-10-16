<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Discord_Link;
use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for Discord link requests: a player's own confirm, and a Storyteller's approval.
 */
class Discord_Links_Controller extends Base_Controller {

	protected $rest_base = 'discord-links';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/discord-links/preview', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'preview' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/discord-links/confirm', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'confirm' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/discord-links/mine', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'revoke_mine' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/discord-links', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/discord-links/(?P<id>\d+)/approve', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'approve' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/discord-links/(?P<id>\d+)/decline', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'decline' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/discord-links/(?P<id>\d+)/revoke', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'revoke' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/discord-links/(?P<id>\d+)/room', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'set_room' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * Reads a link request by its token, without confirming it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$link = $this->find_live_token_for_game( (string) $request->get_param( 'token' ), $game );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		$is_staff = Authorization::check_request( 'be_manage_characters', $request );

		$characters = Character::all_for_game( (string) $game->slug, [
			'status'     => 'active',
			'is_npc'     => 0,
			'wp_user_id' => get_current_user_id(),
		] );

		return $this->success( [
			'discord_username' => $link->discord_username,
			'expires_at'       => $link->token_expires_at,
			'staff'            => $is_staff,
			'characters'       => array_map(
				static fn( $c ) => [ 'id' => (int) $c->id, 'name' => $c->name, 'stack_slug' => $c->stack_slug ],
				$characters
			),
		] );
	}

	/**
	 * Confirms a link request with the signed-in user's own characters.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function confirm( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$link = $this->find_live_token_for_game( (string) $request->get_param( 'token' ), $game );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		$is_staff = Authorization::check_request( 'be_manage_characters', $request );
		$character_ids = array_values( array_unique( array_filter( array_map(
			'intval',
			(array) $request->get_param( 'character_ids' )
		) ) ) );

		if ( ! $is_staff && ! $character_ids ) {
			return $this->error( 'invalid_request', __( 'Pick at least one character.', 'beyond-elysium' ), 400 );
		}

		$user_id = get_current_user_id();
		foreach ( $character_ids as $character_id ) {
			$character = Character::find( $character_id );
			if ( ! $character
				|| (int) $character->wp_user_id !== $user_id
				|| $character->owner_slug !== $game->slug
				|| $character->is_npc
			) {
				return $this->error( 'invalid_character', __( 'One of those characters is not yours in this chronicle.', 'beyond-elysium' ), 400 );
			}
		}

		$updated = Discord_Link::confirm( $link, $user_id, $character_ids, $is_staff );

		return $this->success( [ 'status' => $updated->status ] );
	}

	/**
	 * A player revokes their own link.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke_mine( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		Discord_Link::revoke_own( (int) $game->id, get_current_user_id() );
		return $this->success( [ 'revoked' => true ] );
	}

	/**
	 * Lists a chronicle's links, optionally narrowed to one status.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$status = (string) $request->get_param( 'status' );
		$links  = Discord_Link::for_game( (int) $game->id, $status );

		return $this->success( array_map( [ self::class, 'link_shape' ], $links ) );
	}

	/**
	 * @param object $link
	 * @return array<string,mixed>
	 */
	private static function link_shape( object $link ): array {
		$wp_user = $link->wp_user_id ? get_userdata( (int) $link->wp_user_id ) : false;
		return [
			'id'                => (int) $link->id,
			'discord_user_id'   => $link->discord_user_id,
			'discord_username'  => $link->discord_username,
			'status'            => $link->status,
			'wp_user'           => $wp_user ? [ 'id' => (int) $wp_user->ID, 'display_name' => $wp_user->display_name ] : null,
			'characters'        => array_map(
				static fn( $c ) => [ 'id' => (int) $c->id, 'name' => $c->name ],
				Discord_Link::live_characters( $link )
			),
			'requested_at'      => $link->requested_at,
			'confirmed_at'      => $link->confirmed_at,
			'decided_at'        => $link->decided_at,
			'revoked_at'        => $link->revoked_at,
			'room_channel_id'   => $link->room_channel_id,
		];
	}

	/**
	 * A Storyteller approves a confirmed link.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function approve( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$link = $this->find_link_for_game( (int) $request['id'], $game );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		if ( ! Discord_Link::approve( (int) $link->id, get_current_user_id() ) ) {
			return $this->error( 'not_confirmed', __( 'That link is not waiting for approval.', 'beyond-elysium' ), 400 );
		}
		return $this->success( [ 'status' => Discord_Link::APPROVED ] );
	}

	/**
	 * A Storyteller declines a confirmed link.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function decline( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$link = $this->find_link_for_game( (int) $request['id'], $game );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		if ( ! Discord_Link::decline( (int) $link->id, get_current_user_id() ) ) {
			return $this->error( 'not_confirmed', __( 'That link is not waiting for approval.', 'beyond-elysium' ), 400 );
		}
		return $this->success( [ 'status' => Discord_Link::DECLINED ] );
	}

	/**
	 * A Storyteller revokes an approved link.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$link = $this->find_link_for_game( (int) $request['id'], $game );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		if ( ! Discord_Link::revoke_by_staff( (int) $link->id, get_current_user_id() ) ) {
			return $this->error( 'not_approved', __( 'That link is not currently approved.', 'beyond-elysium' ), 400 );
		}
		return $this->success( [ 'status' => Discord_Link::REVOKED ] );
	}

	/**
	 * A Storyteller points a linked player's room at a Discord channel, or clears it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_room( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$link = $this->find_link_for_game( (int) $request['id'], $game );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		if ( $link->status !== Discord_Link::APPROVED ) {
			return $this->error( 'not_approved', __( 'That link is not currently approved.', 'beyond-elysium' ), 400 );
		}

		$channel_id = $request->get_param( 'channel_id' );
		$channel_id = $channel_id === null || $channel_id === '' ? null : (string) $channel_id;

		Discord_Link::set_room( (int) $link->id, $channel_id );

		return $this->success( [ 'room_channel_id' => $channel_id ] );
	}

	/**
	 * @param string $token
	 * @param object $game
	 * @return object|\WP_Error
	 */
	private function find_live_token_for_game( string $token, object $game ) {
		if ( $token === '' ) {
			return $this->error( 'invalid_token', __( 'That link has expired. Ask to link again from Discord.', 'beyond-elysium' ), 400 );
		}
		$link = \BeyondElysium\Models\Discord_Link::find_by_live_token( $token );
		if ( ! $link || (int) $link->game_id !== (int) $game->id ) {
			return $this->error( 'invalid_token', __( 'That link has expired. Ask to link again from Discord.', 'beyond-elysium' ), 400 );
		}
		return $link;
	}

	/**
	 * @param int    $id
	 * @param object $game
	 * @return object|\WP_Error
	 */
	private function find_link_for_game( int $id, object $game ) {
		$link = Discord_Link::find( $id );
		if ( ! $link || (int) $link->game_id !== (int) $game->id ) {
			return $this->error( 'link_not_found', __( 'Link not found in this game.', 'beyond-elysium' ), 404 );
		}
		return $link;
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
