<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Player_Home;
use BeyondElysium\Services\Storyteller_Home;

defined( 'ABSPATH' ) || exit;

/**
 * What a member's home in a chronicle shows, in one request each: the player's, and the Storyteller's.
 */
class Home_Controller extends Base_Controller {

	protected $rest_base = 'home';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/home/player', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_player' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/home/storyteller', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_storyteller' ],
				'permission_callback' => $this->permission_any( [ 'be_manage_characters', 'be_manage_plots', 'be_manage_sessions', 'be_manage_boons' ] ),
			],
		] );
	}

	/**
	 * The signed-in member's own home: their characters, what is due, what is new and what is next.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_player( $request ) {
		$game = Game::find_by_slug( (string) $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		return $this->success( Player_Home::build( $game, get_current_user_id(), Authorization::can( 'be_manage_plots' ) ) );
	}

	/**
	 * A Storyteller's home: what waits on them, the next game night and who has had no attention, each part only for
	 * someone who holds the capability behind it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_storyteller( $request ) {
		$game = Game::find_by_slug( (string) $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		return $this->success( Storyteller_Home::build(
			$game,
			get_current_user_id(),
			Authorization::can( 'be_manage_characters' ),
			Authorization::can( 'be_manage_plots' ),
			Authorization::can( 'be_manage_sessions' )
		) );
	}
}
