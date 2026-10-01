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
	 * The most rows one apply request accepts.
	 */
	const MAX_APPLY_ROWS = 500;

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

		// POST /be/v1/{game_slug}/experience/apply.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/experience/apply', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'apply' ],
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

	/**
	 * Applies a different, Storyteller-chosen XP amount to each of several characters in one call. A positive amount
	 * awards; a negative amount takes XP back. Each row is refused or applied on its own; a malformed request is
	 * refused whole, before anything is written.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function apply( $request ) {
		$game = Game::find_by_slug( $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$reason = trim( (string) $request->get_param( 'reason' ) );
		if ( $reason === '' ) {
			return $this->error( 'invalid_param', __( 'reason is required.', 'beyond-elysium' ), 400 );
		}

		$awards = $request->get_param( 'awards' );
		if ( empty( $awards ) || ! is_array( $awards ) ) {
			return $this->error( 'invalid_param', __( 'awards must be a non-empty array.', 'beyond-elysium' ), 400 );
		}
		if ( count( $awards ) > self::MAX_APPLY_ROWS ) {
			/* translators: %d: the largest number of rows one request accepts */
			return $this->error( 'invalid_param', sprintf( __( 'A single request can apply at most %d rows.', 'beyond-elysium' ), self::MAX_APPLY_ROWS ), 400 );
		}

		$seen_ids = [];
		$rows     = [];
		foreach ( $awards as $row ) {
			if ( ! is_array( $row ) ) {
				return $this->error( 'invalid_param', __( 'Each award needs a character_id and amount.', 'beyond-elysium' ), 400 );
			}

			$character_id = isset( $row['character_id'] ) ? (int) $row['character_id'] : 0;
			if ( $character_id <= 0 ) {
				return $this->error( 'invalid_param', __( 'Each award needs a valid character_id.', 'beyond-elysium' ), 400 );
			}
			if ( isset( $seen_ids[ $character_id ] ) ) {
				return $this->error( 'invalid_param', __( 'The same character cannot appear twice in one request.', 'beyond-elysium' ), 400 );
			}
			$seen_ids[ $character_id ] = true;

			$amount_raw = $row['amount'] ?? null;
			if ( ! is_numeric( $amount_raw ) || (float) $amount_raw !== floor( (float) $amount_raw ) ) {
				return $this->error( 'invalid_param', __( 'amount must be a whole number.', 'beyond-elysium' ), 400 );
			}
			$amount = (int) $amount_raw;
			if ( $amount === 0 || $amount > self::MAX_AWARD || $amount < -self::MAX_AWARD ) {
				/* translators: %d: the largest award accepted, in either direction */
				return $this->error( 'invalid_param', sprintf( __( 'amount must be a whole number other than zero, no larger than %1$d and no smaller than -%1$d.', 'beyond-elysium' ), self::MAX_AWARD ), 400 );
			}

			$rows[] = [ 'character_id' => $character_id, 'amount' => $amount ];
		}

		$by      = get_current_user_id();
		$reason  = sanitize_text_field( $reason );
		$results = [];
		foreach ( $rows as $row ) {
			$character = Character::find( $row['character_id'] );
			if ( ! $character || $character->owner_type !== 'chronicle' || $character->owner_slug !== $game->slug ) {
				$results[] = [
					'character_id' => $row['character_id'],
					'applied'      => false,
					'code'         => 'not_in_chronicle',
					'message'      => __( "That character isn't in this chronicle.", 'beyond-elysium' ),
				];
				continue;
			}

			$results[] = array_merge(
				[ 'character_id' => $row['character_id'] ],
				Change_Engine::apply_xp( $row['character_id'], $row['amount'], $reason, $by )
			);
		}

		return $this->success( [ 'reason' => $reason, 'results' => $results ] );
	}
}
