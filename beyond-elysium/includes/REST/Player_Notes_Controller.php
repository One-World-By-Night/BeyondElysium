<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Player_Note;

defined( 'ABSPATH' ) || exit;

/**
 * A chronicle's private notes about its players: read and written by its HST and AST only, never by the player, and
 * never part of a print, an export or a Discord route.
 */
class Player_Notes_Controller extends Base_Controller {

	protected $rest_base = 'players';

	public function register_routes(): void {
		$base = '/(?P<game_slug>[a-z0-9\-]+)/players';

		register_rest_route( $this->namespace, $base . '/former-notes', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_former' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
		register_rest_route( $this->namespace, $base . '/(?P<wp_user_id>\d+)/notes', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_note' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'save_note' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_note' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_note( $request ) {
		$game = $this->game( $request );
		return is_wp_error( $game ) ? $game : $this->success( $this->shape( (int) $game->id, (int) $request['wp_user_id'] ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_note( $request ) {
		$game = $this->game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$person = (int) $request['wp_user_id'];
		if ( ! get_userdata( $person ) ) {
			return $this->error( 'no_account', __( 'That account does not exist.', 'beyond-elysium' ), 404 );
		}

		$notes = (string) $request->get_param( 'notes' );
		if ( mb_strlen( $notes ) > Player_Note::MAX_LENGTH ) {
			return $this->error(
				'too_long',
				sprintf(
					/* translators: %s: the most characters a note holds */
					__( 'A note holds at most %s characters.', 'beyond-elysium' ),
					number_format_i18n( Player_Note::MAX_LENGTH )
				),
				400
			);
		}

		Player_Note::save( (int) $game->id, $person, sanitize_textarea_field( $notes ), get_current_user_id() );
		return $this->success( $this->shape( (int) $game->id, $person ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_note( $request ) {
		$game = $this->game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		Player_Note::delete( (int) $game->id, (int) $request['wp_user_id'] );
		return $this->success( $this->shape( (int) $game->id, (int) $request['wp_user_id'] ) );
	}

	/**
	 * The notes about people who are no longer in the chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_former( $request ) {
		$game = $this->game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$members = array_map( static fn( $member ) => (int) $member->wp_user_id, Game_Member::for_game( (int) $game->id ) );
		$former  = [];
		foreach ( Player_Note::user_ids_for_game( (int) $game->id ) as $person ) {
			if ( in_array( $person, $members, true ) ) {
				continue;
			}
			$user     = get_userdata( $person );
			$former[] = [ 'wp_user_id' => $person, 'display_name' => $user ? (string) $user->display_name : null ];
		}
		usort( $former, static fn( $a, $b ) => strcasecmp( (string) $a['display_name'], (string) $b['display_name'] ) );

		return $this->success( $former );
	}

	/**
	 * One person's note as the screens read it.
	 *
	 * @return array{notes:string,updated_by:?string,updated_at:?string}
	 */
	private function shape( int $game_id, int $person ): array {
		$note = Player_Note::find( $game_id, $person );
		if ( ! $note ) {
			return [ 'notes' => '', 'updated_by' => null, 'updated_at' => null ];
		}
		$editor = get_userdata( (int) $note->updated_by );
		return [
			'notes'      => (string) $note->notes,
			'updated_by' => $editor ? (string) $editor->display_name : null,
			'updated_at' => (string) $note->updated_at,
		];
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function game( $request ) {
		$game = Game::find_by_slug( (string) $request['game_slug'] );
		return $game ?: $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
	}
}
