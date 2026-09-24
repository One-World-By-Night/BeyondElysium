<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Staff_Queue;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for My Queue: what a Storyteller or Narrator is personally on the hook for in one chronicle, plus
 * the staff roster an assignee picker offers.
 */
class Staff_Queue_Controller extends Base_Controller {

	protected $rest_base = 'my/queue';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/my/queue', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_queue' ],
				'permission_callback' => $this->permission_any( [ 'be_manage_plots', 'be_manage_characters' ] ),
			],
		] );

		// A narrow disclosure (name and id only).
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/staff', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_staff' ],
				'permission_callback' => $this->permission_any( [ 'be_manage_plots', 'be_manage_characters' ] ),
			],
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_queue( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$wp_user_id = get_current_user_id();

		return $this->success( [
			'downtime'   => Staff_Queue::downtime( $wp_user_id, (int) $game->id ),
			'plots'      => Staff_Queue::plots( $wp_user_id, (int) $game->id ),
			'castings'   => Staff_Queue::castings( $wp_user_id, (int) $game->id ),
			'unassigned' => Staff_Queue::unassigned( (int) $game->id ),
		] );
	}

	/**
	 * Every hst/ast/narrator member of this chronicle, name and id only.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_staff( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$staff = array_map( static function ( $member ) {
			$user = get_userdata( (int) $member->wp_user_id );
			return [
				'id'   => (int) $member->wp_user_id,
				'name' => $user ? $user->display_name : (string) $member->wp_user_id,
				'role' => (string) $member->role,
			];
		}, Game_Member::staff_for_game( (int) $game->id ) );

		return $this->success( array_values( $staff ) );
	}

	/**
	 * Looks up a game by its slug and returns the game object, or a WP_Error with a 404 status when no game matches.
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
