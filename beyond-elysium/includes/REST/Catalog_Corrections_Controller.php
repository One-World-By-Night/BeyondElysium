<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Services\Catalog_Corrections;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a chronicle's book corrections: the list, and keeping or taking one.
 */
class Catalog_Corrections_Controller extends Base_Controller {

	protected $rest_base = 'catalog-corrections';

	/**
	 * Registers the book corrections routes.
	 */
	public function register_routes(): void {
		$base = '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base;

		register_rest_route( $this->namespace, $base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
		] );
		register_rest_route( $this->namespace, $base . '/keep', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'keep' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
		] );
		register_rest_route( $this->namespace, $base . '/take', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'take' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
		] );
	}

	/**
	 * Lists a chronicle's book corrections.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		return $this->success( self::listing( $game ) );
	}

	/**
	 * Keeps the chronicle's value at one correction, or at every one with `all`.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function keep( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		if ( $request->get_param( 'all' ) ) {
			Catalog_Corrections::keep_all( $game );
			return $this->success( self::listing( $game ) );
		}
		return $this->apply( $request, $game, 'keep' );
	}

	/**
	 * Takes the book's value at one correction.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function take( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		return $this->apply( $request, $game, 'take' );
	}

	/**
	 * Keeps or takes the correction a request names, answering with the list as it now stands.
	 *
	 * @param \WP_REST_Request $request
	 * @param object           $game
	 * @param string           $action `keep` or `take`.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function apply( $request, object $game, string $action ) {
		$kind   = (string) $request->get_param( 'kind' );
		$target = (string) $request->get_param( 'target' );
		$path   = self::path( $request->get_param( 'path' ) );
		if ( ! in_array( $kind, Catalog_Corrections::KINDS, true ) || $target === '' || $path === null ) {
			return $this->error( 'invalid_param', __( 'Name the correction by its kind, target and path.', 'beyond-elysium' ), 400 );
		}

		$saved = $action === 'keep'
			? Catalog_Corrections::keep( $game, $kind, $target, $path )
			: Catalog_Corrections::take( $game, $kind, $target, $path );
		if ( $saved === null ) {
			return $this->error( 'not_found', __( 'This chronicle has no such change.', 'beyond-elysium' ), 404 );
		}
		if ( ! $saved ) {
			return $this->error( 'save_failed', __( 'The change could not be saved.', 'beyond-elysium' ), 500 );
		}
		return $this->success( self::listing( $game ) );
	}

	/**
	 * The corrections a chronicle has, with how many.
	 *
	 * @return array{corrections:array<int,array<string,mixed>>,count:int}
	 */
	private static function listing( object $game ): array {
		$corrections = Catalog_Corrections::for_game( $game );
		return [ 'corrections' => $corrections, 'count' => count( $corrections ) ];
	}

	/**
	 * A path as a request sends it, checked: each step a map key or a one-item list naming an entry.
	 *
	 * @param mixed $raw
	 * @return array<int,mixed>|null
	 */
	private static function path( $raw ): ?array {
		if ( ! is_array( $raw ) || $raw === [] || ! array_is_list( $raw ) ) {
			return null;
		}
		foreach ( $raw as $step ) {
			if ( is_array( $step ) ) {
				if ( count( $step ) !== 1 || ! isset( $step[0] ) || ! is_scalar( $step[0] ) ) {
					return null;
				}
			} elseif ( ! is_string( $step ) && ! is_int( $step ) ) {
				return null;
			}
		}
		return $raw;
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
