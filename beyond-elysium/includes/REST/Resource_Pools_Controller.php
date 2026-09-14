<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for bulk resource-pool maintenance.
 *
 * Exposes a single endpoint that resets one named pool's temporary rating
 * back to its permanent one across a batch of characters at once -
 * bulk-operations-design.md's Item 1, the ordinary end-of-session
 * "everyone's Willpower/Blood refills" action, done today one character at
 * a time.
 */
class Resource_Pools_Controller extends Base_Controller {

	protected $rest_base = 'resource-pools';

	/**
	 * Registers the resource-pools routes.
	 *
	 * Adds a single POST route that bulk-resets a named pool to its
	 * permanent value across multiple characters at once, gated by
	 * `be_manage_characters`.
	 */
	public function register_routes(): void {
		// POST /be/v1/{game_slug}/resource-pools/bulk-reset.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/resource-pools/bulk-reset', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'bulk_reset' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * Bulk-resets one resource pool's temporary rating to its permanent one
	 * across multiple characters.
	 *
	 * Validates the character ID list, block slug, and pool name, then
	 * resets the pool for each character that belongs to this game and
	 * actually holds it - a character outside this game, or one who
	 * doesn't hold the pool, is silently skipped rather than treated as an
	 * error, matching `Change_Engine::bulk_award_xp()`'s own "count only
	 * what actually applied" precedent.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_reset( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character_ids = $request->get_param( 'character_ids' );
		if ( empty( $character_ids ) || ! is_array( $character_ids ) ) {
			return $this->error( 'invalid_param', __( 'character_ids must be a non-empty array of integers.', 'beyond-elysium' ), 400 );
		}

		$block_slug = $request->get_param( 'block_slug' );
		if ( empty( $block_slug ) ) {
			return $this->error( 'invalid_param', __( 'block_slug is required.', 'beyond-elysium' ), 400 );
		}

		$pool_name = $request->get_param( 'pool_name' );
		if ( empty( $pool_name ) ) {
			return $this->error( 'invalid_param', __( 'pool_name is required.', 'beyond-elysium' ), 400 );
		}

		$count = 0;
		foreach ( array_map( 'intval', $character_ids ) as $id ) {
			$character = Character::find( $id );
			if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
				continue;
			}
			if ( Character::reset_pool_to_permanent( $id, sanitize_text_field( $block_slug ), sanitize_text_field( $pool_name ) ) ) {
				$count++;
			}
		}

		return $this->success( [
			'reset'      => $count,
			'block_slug' => $block_slug,
			'pool_name'  => $pool_name,
		] );
	}

	/**
	 * Resolves a game by slug, returning a WP_Error if not found.
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
