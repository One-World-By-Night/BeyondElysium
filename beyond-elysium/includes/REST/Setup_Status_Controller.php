<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Setup_Status;

defined( 'ABSPATH' ) || exit;

/**
 * The Chronicle Setup checklist's status endpoint: `GET /{game_slug}/setup-status`.
 */
class Setup_Status_Controller extends Base_Controller {

	protected $rest_base = 'setup-status';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/setup-status', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_status( $request ) {
		$game = Game::find_by_slug( $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$rows = Setup_Status::rows( $game );
		foreach ( $rows as &$row ) {
			$row['actionable'] = Authorization::can( $row['fix']['capability'] );
		}
		unset( $row );

		return $this->success( [ 'items' => $rows, 'summary' => Setup_Status::summarise( $rows ) ] );
	}
}
