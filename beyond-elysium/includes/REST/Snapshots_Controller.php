<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for character sheet snapshots. Exposes endpoints to list
 * and retrieve point-in-time captures of a character's sheet_data, and to
 * manually trigger a new capture. Snapshots are scoped to a game and a
 * character within that game.
 */
class Snapshots_Controller extends Base_Controller {

	protected $rest_base = 'snapshots';

	/**
	 * Registers the REST routes for a character's snapshot collection and
	 * for a single snapshot by id. Both route groups require the
	 * be_manage_characters capability.
	 */
	public function register_routes(): void {
		// Collection: GET/POST /be/v1/{game_slug}/characters/{character_id}/snapshots.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<character_id>\d+)/snapshots', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
				'args'                => $this->get_collection_params(),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		// Single: GET /be/v1/{game_slug}/characters/{character_id}/snapshots/{id}.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<character_id>\d+)/snapshots/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * Returns a paginated list of snapshots for one character, most recent
	 * first by default. Confirms the game and character exist before
	 * querying.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = $this->resolve_character( (int) $request['character_id'], $request['game_slug'] );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		$pagination = $this->get_pagination( $request );
		$args       = [
			'order'    => $request->get_param( 'order' ) ?: 'DESC',
			'per_page' => $pagination['per_page'],
			'offset'   => $pagination['offset'],
		];

		$items    = Snapshot::for_character( (int) $request['character_id'], $args );
		$total    = Snapshot::count_for_character( (int) $request['character_id'] );
		$response = $this->success( $items );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * Returns a single snapshot by id after confirming the game and
	 * character exist and that the snapshot belongs to the requested
	 * character. Returns a 404 error otherwise.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = $this->resolve_character( (int) $request['character_id'], $request['game_slug'] );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		$snapshot = Snapshot::find( (int) $request['id'] );
		if ( ! $snapshot ) {
			return $this->error( 'not_found', __( 'Snapshot not found.', 'beyond-elysium' ), 404 );
		}

		// Verify snapshot belongs to this character.
		if ( (int) $snapshot->character_id !== (int) $request['character_id'] ) {
			return $this->error( 'not_found', __( 'Snapshot not found for this character.', 'beyond-elysium' ), 404 );
		}

		return $this->success( $snapshot );
	}

	/**
	 * Creates a new snapshot capturing the character's current sheet_data.
	 * Confirms the game and character exist before capturing, and returns
	 * the created snapshot on success.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = $this->resolve_character( (int) $request['character_id'], $request['game_slug'] );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		$id = Snapshot::create( (int) $request['character_id'], null );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create snapshot.', 'beyond-elysium' ), 500 );
		}

		$snapshot = Snapshot::find( $id );
		return $this->success( $snapshot, 201 );
	}

	/**
	 * Looks up a game by its slug and returns the game object, or a WP_Error
	 * with a 404 status when no game matches. Used by route callbacks to
	 * resolve the game_slug URL parameter before performing further work.
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

	/**
	 * Looks up a character by id and confirms it belongs to the given game,
	 * returning a WP_Error with a 404 status when the character does not
	 * exist or belongs to a different game.
	 *
	 * @param int    $character_id
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	protected function resolve_character( int $character_id, string $game_slug ) {
		$character = Character::find( $character_id );
		if ( ! $character ) {
			return $this->error( 'character_not_found', __( 'Character not found.', 'beyond-elysium' ), 404 );
		}
		if ( $character->owner_slug !== $game_slug ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}
		return $character;
	}

	/**
	 * Defines the query parameters accepted by the snapshot collection
	 * endpoint: a sort order plus page/per_page pagination, each with its
	 * allowed values and defaults.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return [
			'order'    => [
				'type'    => 'string',
				'default' => 'DESC',
				'enum'    => [ 'ASC', 'DESC' ],
			],
			'page'     => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page' => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
		];
	}
}
