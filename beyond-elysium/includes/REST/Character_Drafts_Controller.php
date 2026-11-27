<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Character_Draft;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Join_Request;
use BeyondElysium\Services\Join_Gate;

defined( 'ABSPATH' ) || exit;

/**
 * The caller's own unfinished new character in one chronicle: read, saved and discarded by its owner alone, never shown
 * to staff and never part of a bot route.
 */
class Character_Drafts_Controller extends Base_Controller {

	protected $rest_base = 'my/character-draft';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/my/character-draft', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_draft' ],
				'permission_callback' => $this->permission_any( [ 'be_edit_own_characters', 'be_manage_characters' ], true ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'save_draft' ],
				'permission_callback' => $this->permission_any( [ 'be_edit_own_characters', 'be_manage_characters' ], true ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_draft' ],
				'permission_callback' => $this->permission_any( [ 'be_edit_own_characters', 'be_manage_characters' ], true ),
			],
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_draft( $request ) {
		$game = $this->resolve_game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$refusal = $this->join_refusal( $game );
		if ( $refusal !== null ) {
			return $refusal;
		}
		return $this->success( $this->shape( (int) $game->id ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_draft( $request ) {
		$game = $this->resolve_game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$refusal = $this->join_refusal( $game );
		if ( $refusal !== null ) {
			return $refusal;
		}

		$draft = $request->get_param( 'draft' );
		if ( ! is_array( $draft ) ) {
			return $this->error( 'invalid_param', __( 'draft must be an object.', 'beyond-elysium' ), 400 );
		}
		$draft = Character_Draft::clean( $draft );
		if ( isset( $draft['sheet_data'] ) && ! is_array( $draft['sheet_data'] ) ) {
			return $this->error( 'invalid_param', __( 'sheet_data must be an object.', 'beyond-elysium' ), 400 );
		}

		$stack_slug = (string) ( $draft['stack_slug'] ?? '' );
		if ( $stack_slug !== '' ) {
			$allowed = array_column( Creature_Stack::all_for_game( (string) $game->slug, [], false, Authorization::can( 'be_manage_characters' ) ), 'slug' );
			if ( ! in_array( $stack_slug, $allowed, true ) ) {
				return $this->error( 'invalid_param', __( 'That creature type is not one this chronicle allows.', 'beyond-elysium' ), 400 );
			}
		}

		$draft['name']       = sanitize_text_field( (string) ( $draft['name'] ?? '' ) );
		$draft['step']       = sanitize_key( (string) ( $draft['step'] ?? '' ) );
		$draft['biography']  = wp_kses_post( (string) ( $draft['biography'] ?? '' ) );
		$draft['stack_slug'] = $stack_slug;

		$encoded = wp_json_encode( $draft );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > Character_Draft::MAX_BYTES ) {
			return $this->error( 'draft_too_large', __( 'This build is too large to keep as a draft.', 'beyond-elysium' ), 413 );
		}

		if ( ! Character_Draft::save( (int) $game->id, get_current_user_id(), $draft ) ) {
			return $this->error( 'save_failed', __( 'The draft could not be saved.', 'beyond-elysium' ), 500 );
		}
		return $this->success( $this->shape( (int) $game->id ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_draft( $request ) {
		$game = $this->resolve_game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		Character_Draft::delete( (int) $game->id, get_current_user_id() );
		return $this->success( $this->shape( (int) $game->id ) );
	}

	/**
	 * The caller's draft as the screens read it: the draft and when it was saved, both null when there is none.
	 *
	 * @return array{draft:array<string,mixed>|null,updated_at:string|null}
	 */
	private function shape( int $game_id ): array {
		$found = Character_Draft::find( $game_id, get_current_user_id() );
		$draft = $found['draft'] ?? null;
		if ( is_array( $draft ) && ( $draft['sheet_data'] ?? null ) === [] ) {
			// An empty sheet reads back as an object, as a held sheet does.
			$draft['sheet_data'] = new \stdClass();
		}
		return [
			'draft'      => $draft,
			'updated_at' => $found['updated_at'] ?? null,
		];
	}

	/**
	 * Someone with no membership yet keeps a draft only where they could start a character: the same two refusals the
	 * create route gives.
	 */
	private function join_refusal( object $game ): ?\WP_Error {
		$joining = ! Authorization::can( 'be_manage_characters' ) && ! Authorization::can( 'be_edit_own_characters' );
		if ( ! $joining ) {
			return null;
		}
		$request = Join_Request::find_waiting( (int) $game->id, get_current_user_id() );
		$gate    = Join_Gate::refusal( $game, $request, get_current_user_id() );
		return $gate === null ? null : $this->error( $gate['code'], $gate['message'], $gate['status'] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function resolve_game( $request ) {
		$game = Game::find_by_slug( (string) $request['game_slug'] );
		return $game ?: $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
	}
}
