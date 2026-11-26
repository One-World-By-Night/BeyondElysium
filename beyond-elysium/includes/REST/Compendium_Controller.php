<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Compendium;

defined( 'ABSPATH' ) || exit;

/**
 * The chronicle's catalog for its members to read: creature types, a type's sections, a section's entries and a search
 * of a type's names.
 */
class Compendium_Controller extends Base_Controller {

	protected $rest_base = 'compendium';

	public function register_routes(): void {
		$base = '/(?P<game_slug>[a-z0-9\-]+)/compendium';

		register_rest_route( $this->namespace, $base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_types' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );
		register_rest_route( $this->namespace, $base . '/search', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'search' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );
		register_rest_route( $this->namespace, $base . '/(?P<stack>[a-z0-9\-_]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_sections' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );
		register_rest_route( $this->namespace, $base . '/(?P<stack>[a-z0-9\-_]+)/(?P<block>[a-z0-9\-_]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_entries' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_types( $request ) {
		$game = $this->game( $request );
		return is_wp_error( $game ) ? $game : $this->success( Compendium::types( $game, Authorization::can( 'be_manage_characters' ) ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_sections( $request ) {
		$game = $this->game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$result = Compendium::sections( $game, (string) $request['stack'], Authorization::can( 'be_manage_characters' ) );
		return $result === null ? $this->not_found() : $this->success( $result );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_entries( $request ) {
		$game = $this->game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$result = Compendium::entries( $game, (string) $request['stack'], (string) $request['block'], Authorization::can( 'be_manage_characters' ) );
		return $result === null ? $this->not_found() : $this->success( $result );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search( $request ) {
		$game = $this->game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$result = Compendium::search( $game, (string) $request->get_param( 'stack' ), (string) $request->get_param( 'q' ), Authorization::can( 'be_manage_characters' ) );
		return $result === null ? $this->not_found() : $this->success( $result );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function game( $request ) {
		$game = Game::find_by_slug( (string) $request['game_slug'] );
		return $game ?: $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
	}

	/**
	 * @return \WP_Error
	 */
	private function not_found() {
		return $this->error( 'not_found', __( 'That is not in this chronicle\'s catalog.', 'beyond-elysium' ), 404 );
	}
}
