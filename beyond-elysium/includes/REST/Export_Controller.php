<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Services\Character_Exporter;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for exporting a character to a Grapevine `.gex` XML
 * document (GX-4). The writer itself (`GEX_Xml_Writer`) and the block-to-list
 * routing (`Character_Exporter`) do the real work; this controller is the
 * thin permission/lookup/response layer every other controller in this
 * project already follows.
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-3, GX-4
 */
class Export_Controller extends Base_Controller {

	protected $rest_base = 'export';

	/**
	 * Registers the single-character export route. A chronicle manager may
	 * export any character; a player may export only their own - the same
	 * "manage or own" split every other per-character route in this plugin
	 * already uses (e.g. `Characters_Controller::get_item()`).
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
	 * Exports one character. Confirms the game and character exist and
	 * belong to each other, enforces the manage-or-own split, then delegates
	 * to `Character_Exporter::export()` and returns its `{xml, warnings,
	 * transliterations}` shape unchanged.
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

		if ( ! current_user_can( 'be_manage_characters' ) && (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You do not have permission to export this character.', 'beyond-elysium' ), 403 );
		}

		$result = Character_Exporter::export( (int) $character->id, [
			'hide_st'     => (bool) $request->get_param( 'hide_st' ),
			'as_transfer' => (bool) $request->get_param( 'as_transfer' ),
			'verify'      => (bool) $request->get_param( 'verify' ),
		] );

		return $this->success( $result );
	}

	/**
	 * Looks up the game record for the given slug and returns a 404 error
	 * when no game matches it. Matches every other controller's own copy
	 * of this helper (`Characters_Controller::resolve_game()` and others) -
	 * not shared via `Base_Controller`, so duplicated here the same way.
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
