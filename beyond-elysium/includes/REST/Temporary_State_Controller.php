<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Change_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a character's temporary state: resource pool spend/regain, wounds by type, and the night's
 * losses - every one of them logged, none of them needing a Storyteller's approval.
 */
class Temporary_State_Controller extends Base_Controller {

	protected $rest_base = 'temporary-state';

	/**
	 * The damage types a wound or a heal can name.
	 */
	const WOUND_TYPES = [ 'bashing', 'lethal', 'aggravated' ];

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/temporary/pool', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'adjust_pool' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/wound', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'wound' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/heal', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'heal' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/night-losses', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_night_loss' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'clear_night_losses' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );
	}

	/**
	 * Spends or regains a point of one held resource pool's temporary rating.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function adjust_pool( $request ) {
		$character = $this->resolve_character( $request );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		$block_slug = (string) $request->get_param( 'block_slug' );
		$pool_name  = (string) $request->get_param( 'pool_name' );
		$delta_raw  = $request->get_param( 'delta' );
		if ( $block_slug === '' || $pool_name === '' || ! is_numeric( $delta_raw ) || (int) $delta_raw === 0 ) {
			return $this->error( 'invalid_param', __( 'block_slug, pool_name and a non-zero whole-number delta are required.', 'beyond-elysium' ), 400 );
		}

		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );

		$result = Change_Engine::adjust_pool_temporary(
			(int) $character->id,
			$block_slug,
			$pool_name,
			(int) $delta_raw,
			$reason,
			get_current_user_id()
		);

		if ( ! $result['applied'] ) {
			$status = ( $result['code'] ?? '' ) === 'not_found' ? 404 : 400;
			return $this->error( (string) ( $result['code'] ?? 'spend_failed' ), (string) ( $result['message'] ?? __( 'The change could not be applied.', 'beyond-elysium' ) ), $status );
		}

		return $this->success( [
			'permanent' => $result['permanent'],
			'temporary' => $result['temporary'],
		] );
	}

	/**
	 * Takes damage of one type, with the hit's armor amount folded into the logged reason.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function wound( $request ) {
		return $this->apply_wound( $request, 1 );
	}

	/**
	 * Heals damage of one type.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function heal( $request ) {
		return $this->apply_wound( $request, -1 );
	}

	/**
	 * Shared body for wound() and heal(): both take a type and an amount, and differ only in the sign the amount is
	 * applied with.
	 *
	 * @param \WP_REST_Request $request
	 * @param int              $sign 1 for a wound, -1 for a heal.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function apply_wound( $request, int $sign ) {
		$character = $this->resolve_character( $request );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		$type       = (string) $request->get_param( 'type' );
		$amount_raw = $request->get_param( 'amount' );
		if ( ! in_array( $type, self::WOUND_TYPES, true ) || ! is_numeric( $amount_raw ) || (int) $amount_raw <= 0 ) {
			return $this->error( 'invalid_param', sprintf(
				/* translators: %s: the three valid wound types, comma-separated */
				__( 'type must be one of %s, and amount must be a positive whole number.', 'beyond-elysium' ),
				implode( ', ', self::WOUND_TYPES )
			), 400 );
		}

		$armor  = $request->get_param( 'armor' );
		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		if ( $sign > 0 && is_numeric( $armor ) && (int) $armor > 0 ) {
			$reason = trim( sprintf(
				/* translators: 1: the amount absorbed by armor, 2: the character's own stated reason, if any */
				__( '%1$d to armor. %2$s', 'beyond-elysium' ),
				(int) $armor,
				$reason
			) );
		}

		$result = Change_Engine::adjust_wound( (int) $character->id, $type, $sign * (int) $amount_raw, $reason, get_current_user_id() );

		if ( ! $result['applied'] ) {
			$status = ( $result['code'] ?? '' ) === 'not_found' ? 404 : 400;
			return $this->error( (string) ( $result['code'] ?? 'wound_failed' ), (string) ( $result['message'] ?? __( 'The change could not be applied.', 'beyond-elysium' ) ), $status );
		}

		return $this->success( [ 'wounds' => $result['wounds'] ] );
	}

	/**
	 * Appends one entry to a character's night losses.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_night_loss( $request ) {
		$character = $this->resolve_character( $request );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		$kind       = (string) $request->get_param( 'kind' );
		$block_slug = (string) $request->get_param( 'block_slug' );
		$name       = (string) $request->get_param( 'name' );
		if ( ! in_array( $kind, [ 'trait', 'ability' ], true ) || $block_slug === '' || $name === '' ) {
			return $this->error( 'invalid_param', __( 'kind (trait or ability), block_slug and name are required.', 'beyond-elysium' ), 400 );
		}

		$entry = [
			'block_slug' => $block_slug,
			'name'       => sanitize_text_field( $name ),
		];
		if ( $kind === 'ability' ) {
			$entry['levels'] = max( 1, (int) $request->get_param( 'levels' ) ?: 1 );
		}

		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		$result = Change_Engine::add_night_loss( (int) $character->id, $kind, $entry, $reason, get_current_user_id() );

		if ( ! $result['applied'] ) {
			$status = ( $result['code'] ?? '' ) === 'not_found' ? 404 : 400;
			return $this->error( (string) ( $result['code'] ?? 'write_failed' ), (string) ( $result['message'] ?? __( 'The loss could not be recorded.', 'beyond-elysium' ) ), $status );
		}

		return $this->success( $result['losses'], 201 );
	}

	/**
	 * Clears a character's night losses entirely.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function clear_night_losses( $request ) {
		$character = $this->resolve_character( $request );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		$result = Change_Engine::clear_night_losses( (int) $character->id, $reason, get_current_user_id() );

		if ( ! $result['applied'] ) {
			$status = ( $result['code'] ?? '' ) === 'not_found' ? 404 : 400;
			return $this->error( (string) ( $result['code'] ?? 'write_failed' ), (string) ( $result['message'] ?? __( 'The losses could not be cleared.', 'beyond-elysium' ) ), $status );
		}

		return $this->success( $result['losses'] );
	}

	/**
	 * Resolves the character named in the route, refusing a viewer who is neither its own player nor a manager.
	 *
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function resolve_character( $request ) {
		$game = Game::find_by_slug( (string) $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$character = Character::find( (int) $request['id'] );
		if ( ! $character ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		$can_manage = Authorization::check_request( 'be_manage_characters', $request );
		$problem    = Sheets_Controller::check_sheet_access( $character, (string) $request['game_slug'], $can_manage );
		if ( $problem !== null ) {
			return $this->error( $problem['code'], $problem['message'], $problem['status'] );
		}

		return $character;
	}
}
