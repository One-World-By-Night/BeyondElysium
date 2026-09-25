<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Chronicle_Players;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a chronicle's players, for the chronicle's Storytellers: `GET`, `POST /{game_slug}/players` and
 * `DELETE /{game_slug}/players/{wp_user_id}`. Every route answers 404 for a chronicle not linked to accessSchema.
 */
class Chronicle_Players_Controller extends Base_Controller {

	protected $rest_base = 'players';

	/**
	 * Registers the chronicle player routes.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
				'args'                => [
					'wp_user_id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
				],
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/(?P<wp_user_id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * The chronicle's players, by display name, and whether the chronicle reads accessSchema.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->linked_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$players = [];
		foreach ( Game_Member::for_game( (int) $game->id ) as $member ) {
			if ( $member->role !== 'player' ) {
				continue;
			}
			$user      = get_userdata( (int) $member->wp_user_id );
			$players[] = [
				'wp_user_id'   => (int) $member->wp_user_id,
				'display_name' => $user ? $user->display_name : null,
				'since'        => (string) $member->created_at,
			];
		}
		usort( $players, static fn( $a, $b ) => strcasecmp( (string) $a['display_name'], (string) $b['display_name'] ) );

		return $this->success( [
			'players'        => $players,
			'asc_role_path'  => Authorization::asc_role_path( $game, 'player' ),
		] );
	}

	/**
	 * Makes an existing account a player in the chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->linked_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$result = Chronicle_Players::add( $game, (int) $request->get_param( 'wp_user_id' ) );
		if ( $result['status'] === 'no_account' ) {
			return $this->error( 'no_account', __( 'That account does not exist. They need to sign in through OWbN once first.', 'beyond-elysium' ), 404 );
		}

		return $this->success( $result, $result['status'] === 'added' ? 201 : 200 );
	}

	/**
	 * Takes a player out of the chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$game = $this->linked_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$result = Chronicle_Players::remove( $game, (int) $request['wp_user_id'] );
		if ( $result['status'] === 'no_account' ) {
			return $this->error( 'no_account', __( 'That account does not exist.', 'beyond-elysium' ), 404 );
		}
		if ( $result['status'] === 'staff' ) {
			return $this->error( 'staff_member', __( 'Only players are removed here. A staff role is changed on Chronicle Access.', 'beyond-elysium' ), 409 );
		}

		return $this->success( $result );
	}

	/**
	 * Resolves a game by its slug when it is linked to accessSchema: the site reads it and the chronicle names its role
	 * path. Any other chronicle answers 404.
	 *
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	protected function linked_game( string $game_slug ) {
		$game = $this->resolve_game( $game_slug );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		if ( Authorization::asc_role_path( $game, 'player' ) === null ) {
			return $this->error( 'players_unavailable', __( 'This chronicle is not linked to OWbN accessSchema, so its players are not managed here.', 'beyond-elysium' ), 404 );
		}
		return $game;
	}

	/**
	 * Resolves a game by its slug.
	 *
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
