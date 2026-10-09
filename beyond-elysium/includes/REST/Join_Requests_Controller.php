<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Notifications;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Join_Request;
use BeyondElysium\Services\Chronicle_Players;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the applicant's own side of asking to join a chronicle: the joinable list, opening a request,
 * checking it, and withdrawing it.
 */
class Join_Requests_Controller extends Base_Controller {

	/**
	 * Requests one account may open on one chronicle within a day.
	 */
	const MAX_ASKS_PER_DAY = 3;

	protected $rest_base = 'join';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/joinable', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'joinable' ],
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base, [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'args'                => [
					'message' => [ 'type' => 'string', 'required' => true ],
				],
			],
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'withdraw' ],
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			],
		] );
	}

	/**
	 * Every chronicle on this site taking join requests that the caller isn't already a member of.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function joinable( $request ) {
		$wp_user_id = get_current_user_id();
		$joinable   = [];
		foreach ( Game::all() as $game ) {
			if ( ! Join_Request::open_on( $game ) ) {
				continue;
			}
			if ( Game_Member::find( (int) $game->id, $wp_user_id ) !== null ) {
				continue;
			}
			$joinable[] = [
				'slug' => $game->slug,
				'name' => $game->name,
			];
		}
		return $this->success( $joinable );
	}

	/**
	 * Opens a join request on one chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = Game::find_by_slug( $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		if ( ! Join_Request::open_on( $game ) ) {
			return $this->error( 'join_requests_off', __( 'This chronicle is not taking join requests right now.', 'beyond-elysium' ), 403 );
		}

		$wp_user_id = get_current_user_id();
		if ( Game_Member::find( (int) $game->id, $wp_user_id ) !== null ) {
			return $this->error( 'already_a_member', __( 'You are already a member of this chronicle.', 'beyond-elysium' ), 409 );
		}

		if ( Join_Request::count_opened_today( (int) $game->id, $wp_user_id ) >= self::MAX_ASKS_PER_DAY ) {
			return $this->error( 'too_many_requests', __( 'You have asked to join this chronicle several times today. Try again tomorrow.', 'beyond-elysium' ), 429 );
		}

		$message = trim( sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
		if ( $message === '' ) {
			return $this->error( 'invalid_param', __( 'A short message is required.', 'beyond-elysium' ), 400 );
		}
		if ( strlen( $message ) > 1000 ) {
			return $this->error( 'invalid_param', __( 'The message is limited to 1,000 characters.', 'beyond-elysium' ), 400 );
		}

		$id = Join_Request::open( (int) $game->id, $wp_user_id, $message );
		if ( $id === false ) {
			return $this->error( 'join_already_requested', __( 'You already have a request waiting for this chronicle.', 'beyond-elysium' ), 409 );
		}

		Chronicle_Players::ensure_site_membership( $wp_user_id );
		Notifications::join_requested( $game, wp_get_current_user(), $message );

		return $this->success( Join_Request::find( (int) $id ), 201 );
	}

	/**
	 * The caller's own waiting request on one chronicle, or null.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$game = Game::find_by_slug( $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		$waiting = Join_Request::find_waiting( (int) $game->id, get_current_user_id() );
		if ( $waiting ) {
			\BeyondElysium\Services\St_Visibility::filter_join_request( $waiting, $game, false );
		}
		return $this->success( $waiting );
	}

	/**
	 * Withdraws the caller's own waiting request, and any pending character started for it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function withdraw( $request ) {
		$game = Game::find_by_slug( $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		$waiting = Join_Request::find_waiting( (int) $game->id, get_current_user_id() );
		if ( ! $waiting ) {
			return $this->error( 'not_found', __( 'No waiting request to withdraw.', 'beyond-elysium' ), 404 );
		}

		if ( ! empty( $waiting->character_id ) ) {
			$character = Character::find( (int) $waiting->character_id );
			if ( $character && $character->status === 'pending' ) {
				Character::delete( (int) $character->id );
			}
		}

		Join_Request::withdraw( (int) $waiting->id );
		return $this->success( null, 204 );
	}
}
