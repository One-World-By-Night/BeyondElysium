<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Services\Character_Exporter;
use BeyondElysium\Services\Not_Exportable_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for exporting a character to a Grapevine `.gex` XML document.
 */
class Export_Controller extends Base_Controller {

	protected $rest_base = 'export';

	/**
	 * Registers the single-character export route.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/export', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'export_character' ],
				'permission_callback' => $this->permission_any( [ 'be_manage_characters', 'be_edit_own_characters' ] ),
				'args'                => [
					'format'      => [ 'type' => 'string', 'enum' => [ 'gex-xml' ], 'default' => 'gex-xml' ],
					'hide_st'     => [ 'type' => 'boolean', 'default' => false ],
					'as_transfer' => [ 'type' => 'boolean', 'default' => false ],
					'verify'      => [ 'type' => 'boolean', 'default' => false ],
				],
			],
		] );
	}

	/**
	 * Exports one character.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export_character( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = Character::find( (int) $request['id'] );
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		$can_manage = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );
		if ( ! $can_manage && (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You do not have permission to export this character.', 'beyond-elysium' ), 403 );
		}

		// A transfer document is issued only where a transfer is recorded.
		if ( $request->get_param( 'as_transfer' ) ) {
			return $this->error( 'use_transfer_route', __( 'Start a transfer from the character\'s Transfer panel, not from an export.', 'beyond-elysium' ), 400 );
		}

		try {
			$result = Character_Exporter::export( (int) $character->id, [
				'hide_st' => ! $can_manage || (bool) $request->get_param( 'hide_st' ),
				'verify'  => (bool) $request->get_param( 'verify' ),
			] );
		} catch ( Not_Exportable_Exception $e ) {
			return $this->error( 'not_exportable', $e->getMessage(), 422 );
		}
		unset( $result['attestation_id'] );

		return $this->success( $result );
	}

	/**
	 * Looks up the game record for the given slug and returns a 404 error when no game matches it.
	 *
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	protected function resolve_game( string $game_slug ) {
		$game = \BeyondElysium\Models\Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		return $game;
	}
}
