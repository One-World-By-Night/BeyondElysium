<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Notifications;
use BeyondElysium\Core\User_Settings;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Notice;

defined( 'ABSPATH' ) || exit;

/**
 * A person's own notices in one chronicle, which the site shows in its header: read them, mark them read.
 */
class Notices_Controller extends Base_Controller {

	protected $rest_base = 'notices';

	/**
	 * The most notices one read returns.
	 */
	private const PAGE = 30;

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/notices', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/notices/settings', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_settings' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/notices/read', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'mark_read' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );
	}

	/**
	 * The caller's own unread count and newest notices.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$person = get_current_user_id();
		return $this->success( [
			'unread'   => Notice::unread_count( (int) $game->id, $person ),
			'items'    => Notice::for_person( (int) $game->id, $person, self::PAGE ),
			'settings' => $this->settings_for( $game, $person ),
		] );
	}

	/**
	 * A Storyteller's own choice of how often to hear of changes waiting, saved from the bell's panel. An empty choice
	 * clears it, and the chronicle's own way applies again.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_settings( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$person = get_current_user_id();
		if ( $this->settings_for( $game, $person ) === null ) {
			return $this->error( 'not_staff', __( 'Only a Storyteller of this chronicle chooses how often to hear of changes waiting.', 'beyond-elysium' ), 403 );
		}

		$choice = (string) $request->get_param( 'waiting_notify' );
		if ( $choice !== '' && ! in_array( $choice, User_Settings::WAITING_NOTIFY_VALUES, true ) ) {
			return $this->error( 'invalid_param', __( 'waiting_notify must be each, daily or never.', 'beyond-elysium' ), 400 );
		}

		if ( $choice === '' ) {
			delete_user_meta( $person, User_Settings::WAITING_NOTIFY_META );
		} else {
			update_user_meta( $person, User_Settings::WAITING_NOTIFY_META, $choice );
		}
		return $this->success( $this->settings_for( $game, $person ) );
	}

	/**
	 * What a Storyteller may choose about hearing of changes waiting, with the choice they have made and what applies
	 * when they have made none; null for anyone who is not an HST or AST here.
	 *
	 * @param object $game
	 * @return array{waiting_notify:string,effective:string,staff_address:bool}|null
	 */
	private function settings_for( $game, int $person ): ?array {
		$membership = Game_Member::find( (int) $game->id, $person );
		if ( ! $membership || ! in_array( $membership->role, [ 'hst', 'ast' ], true ) ) {
			return null;
		}
		$address = Notifications::staff_address_of( $game ) !== '';
		$chosen  = Notifications::waiting_preference( $person );
		return [
			'waiting_notify' => $chosen ?? '',
			'effective'      => $chosen ?? ( $address ? 'never' : 'each' ),
			'staff_address'  => $address,
		];
	}

	/**
	 * Marks the caller's own notices read: the ids named, or all of them.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mark_read( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$ids    = $request->get_param( 'ids' );
		$person = get_current_user_id();
		Notice::mark_read( (int) $game->id, $person, is_array( $ids ) ? $ids : null );

		return $this->success( [ 'unread' => Notice::unread_count( (int) $game->id, $person ) ] );
	}

	/**
	 * @return object|\WP_Error
	 */
	private function resolve_game( string $slug ) {
		$game = \BeyondElysium\Models\Game::find_by_slug( $slug );
		return $game ?: $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
	}
}
