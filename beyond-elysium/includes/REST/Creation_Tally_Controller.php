<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Creation_Tally;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the creation tally: `POST /{game_slug}/creation-tally` for a draft build in progress, and
 * `GET /{game_slug}/characters/{id}/creation-tally` for a Storyteller reviewing a pending new character.
 */
class Creation_Tally_Controller extends Base_Controller {

	protected $rest_base = 'creation-tally';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/creation-tally', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'tally_draft' ],
				'permission_callback' => $this->permission_any( [ 'be_edit_own_characters', 'be_manage_characters' ] ),
			],
		] );
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/creation-tally', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'tally_character' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * Tallies a draft build: a creature type and a sheet in progress, neither saved yet.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function tally_draft( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$stack_slug = (string) $request->get_param( 'stack_slug' );
		if ( $stack_slug === '' ) {
			return $this->error( 'invalid_param', __( 'stack_slug is required.', 'beyond-elysium' ), 400 );
		}
		$sheet_data = $request->get_param( 'sheet_data' );
		if ( $sheet_data !== null && ! is_array( $sheet_data ) ) {
			return $this->error( 'invalid_param', __( 'sheet_data must be an object.', 'beyond-elysium' ), 400 );
		}

		$resolved = Creature_Stack::resolve( $stack_slug, (string) $game->slug );
		if ( $resolved === null ) {
			return $this->error( 'invalid_param', __( 'stack_slug does not resolve to a real creature stack.', 'beyond-elysium' ), 400 );
		}

		$tally = Creation_Tally::for_stack(
			$resolved['stack'],
			$resolved['blocks'],
			$sheet_data ?: [],
			(string) $game->slug,
			(int) ( $game->settings->starting_xp ?? 0 )
		);
		return $this->success( $tally );
	}

	/**
	 * Tallies a pending character's own saved sheet, for a Storyteller reviewing it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function tally_character( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = Character::find( (int) $request['id'] );
		if ( $character === null || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		$resolved = Creature_Stack::resolve( (string) $character->stack_slug, (string) $game->slug );
		if ( $resolved === null ) {
			return $this->error( 'creature_stack_not_found', __( 'This character\'s creature type no longer exists, so its build can\'t be tallied.', 'beyond-elysium' ), 404 );
		}

		$tally = Creation_Tally::for_stack(
			$resolved['stack'],
			$resolved['blocks'],
			is_array( $character->sheet_data ?? null ) ? $character->sheet_data : [],
			(string) $game->slug,
			(int) ( $game->settings->starting_xp ?? 0 )
		);
		return $this->success( $tally );
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
