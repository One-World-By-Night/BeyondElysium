<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Services\Change_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for bulk experience awards.
 *
 * Exposes a single endpoint that awards the same amount of XP, with the same
 * reason, to a batch of characters at once, delegating the actual award and
 * history recording to `Change_Engine`.
 */
class Experience_Controller extends Base_Controller {

	protected $rest_base = 'experience';

	/**
	 * Registers the experience routes.
	 *
	 * Adds a single POST route that bulk-awards XP to multiple characters at
	 * once, gated by `be_manage_characters`.
	 */
	public function register_routes(): void {
		// POST /be/v1/{game_slug}/experience/bulk-award.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/experience/bulk-award', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'bulk_award' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * Bulk-awards XP to multiple characters.
	 *
	 * Validates the character ID list, amount, and reason, then delegates to
	 * `Change_Engine::bulk_award_xp()` to apply the award to each character
	 * and record it in their change history.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_award( $request ) {
		$game = Game::find_by_slug( $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$character_ids = $request->get_param( 'character_ids' );
		if ( empty( $character_ids ) || ! is_array( $character_ids ) ) {
			return $this->error( 'invalid_param', __( 'character_ids must be a non-empty array of integers.', 'beyond-elysium' ), 400 );
		}

		$amount = (int) $request->get_param( 'amount' );
		if ( $amount <= 0 ) {
			return $this->error( 'invalid_param', __( 'amount must be a positive integer.', 'beyond-elysium' ), 400 );
		}

		$reason = $request->get_param( 'reason' );
		if ( empty( $reason ) ) {
			return $this->error( 'invalid_param', __( 'reason is required.', 'beyond-elysium' ), 400 );
		}

		$count = Change_Engine::bulk_award_xp(
			array_map( 'intval', $character_ids ),
			$amount,
			sanitize_text_field( $reason ),
			get_current_user_id()
		);

		return $this->success( [
			'awarded' => $count,
			'amount'  => $amount,
			'reason'  => $reason,
		] );
	}
}
