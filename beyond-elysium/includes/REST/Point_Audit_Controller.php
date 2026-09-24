<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Services\Point_Audit;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the point audit: `GET /{game_slug}/characters/{id}/point-audit`.
 */
class Point_Audit_Controller extends Base_Controller {

	protected $rest_base = 'point-audit';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/point-audit', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$character_id = (int) $request['id'];
		$character    = Character::find( $character_id );

		if ( $character === null || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		// The character is here; what's missing is its creature type.
		$report = Point_Audit::for_character( $character_id );
		if ( $report === null ) {
			return $this->error( 'creature_stack_not_found', __( 'This character\'s creature type no longer exists, so its points can\'t be audited.', 'beyond-elysium' ), 404 );
		}

		return $this->success( $report );
	}
}
