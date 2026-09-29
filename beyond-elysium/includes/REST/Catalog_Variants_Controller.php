<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Services\Catalog_Variants;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the book variants a chronicle chooses: the list, the held entries a choice would leave
 * unmatched, and choosing.
 */
class Catalog_Variants_Controller extends Base_Controller {

	protected $rest_base = 'catalog-variants';

	/**
	 * Registers the book variants routes.
	 */
	public function register_routes(): void {
		$base = '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base;

		register_rest_route( $this->namespace, $base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'choose' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
		] );
		register_rest_route( $this->namespace, $base . '/preview', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'preview' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
		] );
	}

	/**
	 * Lists every base block with variants and the ones this chronicle chose.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		return $this->success( [ 'bases' => Catalog_Variants::for_game( $game ) ] );
	}

	/**
	 * The held entries a choice of variants for a base block would leave unmatched.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$choice = $this->choice( $request );
		if ( is_wp_error( $choice ) ) {
			return $choice;
		}
		return $this->success( [ 'unmatched' => Catalog_Variants::unmatched( $game, $choice[0], $choice[1] ) ] );
	}

	/**
	 * Chooses a base block's variants for this chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function choose( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$choice = $this->choice( $request );
		if ( is_wp_error( $choice ) ) {
			return $choice;
		}
		if ( ! Catalog_Variants::choose( $game, $choice[0], $choice[1] ) ) {
			return $this->error( 'save_failed', __( 'The variants could not be saved.', 'beyond-elysium' ), 500 );
		}
		$fresh = Game::find_by_slug( (string) $game->slug );
		return $this->success( [ 'bases' => Catalog_Variants::for_game( $fresh ?? $game ) ] );
	}

	/**
	 * The base block and variant ids a request names, checked.
	 *
	 * @param \WP_REST_Request $request
	 * @return array{0:string,1:array<int,string>}|\WP_Error
	 */
	private function choice( $request ) {
		$base = (string) $request->get_param( 'base' );
		$ids  = $request->get_param( 'variants' );
		if ( $base === '' || ! is_array( $ids ) || ! array_is_list( $ids ) ) {
			return $this->error( 'invalid_param', __( 'Name the base block and the variants chosen for it.', 'beyond-elysium' ), 400 );
		}
		$ids = array_values( array_unique( array_map( 'strval', $ids ) ) );

		switch ( Catalog_Variants::refusal( $base, $ids ) ) {
			case 'unknown_base':
				return $this->error( 'unknown_base', __( 'That block has no variants.', 'beyond-elysium' ), 400 );
			case 'unknown_variant':
				return $this->error( 'unknown_variant', __( 'That is not one of the block\'s variants.', 'beyond-elysium' ), 400 );
			case 'one_replacement':
				return $this->error( 'one_replacement', __( 'Only one variant can replace a block.', 'beyond-elysium' ), 400 );
		}
		return [ $base, $ids ];
	}

	/**
	 * Looks up a game by its slug, or a 404 when none has it.
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
