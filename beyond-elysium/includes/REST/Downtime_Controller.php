<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Services\Downtime_Window;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the Storyteller downtime queue: one row per action plot for a game date, unanswered first.
 */
class Downtime_Controller extends Base_Controller {

	protected $rest_base = 'downtime';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/downtime/queue', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_queue' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
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

		$game_date = (string) $request->get_param( 'game_date' );
		if ( $game_date === '' ) {
			return $this->error( 'invalid_param', __( 'game_date is required.', 'beyond-elysium' ), 400 );
		}

		return $this->success( Downtime_Window::queue_for_date( (int) $game->id, $game_date ) );
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
