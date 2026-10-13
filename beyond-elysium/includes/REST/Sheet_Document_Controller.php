<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Sheet_Document;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a character's sheet as data: `GET /{game_slug}/characters/{id}/sheet-document`.
 */
class Sheet_Document_Controller extends Base_Controller {

	protected $rest_base = 'sheet-document';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/sheet-document', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character_id = (int) $request['id'];
		$character    = Character::find( $character_id );
		if ( ! $character ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		$can_manage = Authorization::check_request( 'be_manage_characters', $request );
		$problem    = Sheets_Controller::check_sheet_access( $character, (string) $request['game_slug'], $can_manage );
		if ( $problem !== null ) {
			return $this->error( $problem['code'], $problem['message'], $problem['status'] );
		}

		$documents = Sheet_Document::for_characters( [ $character_id ], (string) $request['game_slug'], [
			'can_manage' => $can_manage,
		] );

		return $this->success( $documents[0] ?? null );
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
