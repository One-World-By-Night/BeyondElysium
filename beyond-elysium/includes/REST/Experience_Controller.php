<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Change_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for bulk experience awards.
 */
class Experience_Controller extends Base_Controller {

	protected $rest_base = 'experience';

	/**
	 * The largest single award accepted.
	 */
	const MAX_AWARD = 10000;

	/**
	 * Registers the experience routes.
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
		if ( $amount <= 0 || $amount > self::MAX_AWARD ) {
			/* translators: %d: the largest award accepted */
			return $this->error( 'invalid_param', sprintf( __( 'amount must be a positive integer no larger than %d.', 'beyond-elysium' ), self::MAX_AWARD ), 400 );
		}

		$reason = $request->get_param( 'reason' );
		if ( empty( $reason ) ) {
			return $this->error( 'invalid_param', __( 'reason is required.', 'beyond-elysium' ), 400 );
		}

		$in_chronicle = [];
		$skipped      = [];
		foreach ( array_unique( array_map( 'intval', $character_ids ) ) as $character_id ) {
			$character = Character::find( $character_id );
			if ( $character && $character->owner_type === 'chronicle' && $character->owner_slug === $game->slug ) {
				$in_chronicle[] = $character_id;
			} else {
				$skipped[] = $character_id;
			}
		}

		$count = Change_Engine::bulk_award_xp(
			$in_chronicle,
			$amount,
			sanitize_text_field( $reason ),
			get_current_user_id()
		);

		return $this->success( [
			'awarded' => $count,
			'amount'  => $amount,
			'reason'  => $reason,
			'skipped' => $skipped,
		] );
	}
}
