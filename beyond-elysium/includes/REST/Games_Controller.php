<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the games resource. Exposes endpoints to list, create,
 * retrieve, update, and delete games, which are the top-level container each
 * chronicle's characters, plots, and other data belong to. Games are identified
 * by a unique slug in addition to their numeric id.
 */
class Games_Controller extends Base_Controller {

	protected $rest_base = 'games';

	/**
	 * Registers the REST routes for the games collection and for a single game
	 * addressed by slug. Wires up GET/POST on the collection endpoint and
	 * GET/PUT/DELETE on the single-game endpoint, each gated by the appropriate
	 * capability.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
				'args'                => $this->get_collection_params(),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
				'args'                => $this->get_create_params(),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<slug>[a-z0-9\-]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
				'args'                => $this->get_update_params(),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );
	}

	/**
	 * Returns a paginated list of games, optionally filtered by game_type and
	 * ordered by the requested column and direction. Available to any user
	 * who can view characters.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$pagination = $this->get_pagination( $request );
		$args = [
			'game_type' => $request->get_param( 'game_type' ),
			'orderby'   => $request->get_param( 'orderby' ) ?: 'name',
			'order'     => $request->get_param( 'order' ) ?: 'ASC',
			'per_page'  => $pagination['per_page'],
			'offset'    => $pagination['offset'],
		];

		$items = Game::all( $args );
		$total = Game::count( $args );

		$response = $this->success( $items );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * Returns a single game identified by its slug, or a 404 error when no
	 * game with that slug exists. Available to any user who can view
	 * characters.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$game = Game::find_by_slug( $request['slug'] );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		return $this->success( $game );
	}

	/**
	 * Creates a new game from the request's name, slug, game_type, description,
	 * and settings fields. Generates a slug from the name when none is given,
	 * or validates that an explicitly supplied slug is not already in use.
	 * Triggers the be_after_upgrade action so provisioning that depends on a
	 * game existing can run.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$name = $request->get_param( 'name' );
		if ( empty( $name ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: name.', 'beyond-elysium' ), 400 );
		}

		$slug = $request->get_param( 'slug' );

		// An explicitly requested slug that already exists returns a duplicate_slug error.
		if ( ! empty( $slug ) && Game::find_by_slug( sanitize_title( $slug ) ) ) {
			return $this->error( 'duplicate_slug', __( 'A game with this slug already exists.', 'beyond-elysium' ), 409 );
		}

		$data = [
			'name'        => $name,
			'slug'        => $slug,
			'game_type'   => $request->get_param( 'game_type' ) ?: 'met',
			'description' => $request->get_param( 'description' ) ?: '',
			'settings'    => $request->get_param( 'settings' ),
		];

		$id = Game::create( $data );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create game.', 'beyond-elysium' ), 500 );
		}

		// Runs upgrade-time provisioning now that a game exists for it to act on.
		do_action( 'be_after_upgrade' );

		$game = Game::find( $id );
		return $this->success( $game, 201 );
	}

	/**
	 * Updates an existing game identified by slug with any of the recognized
	 * fields present in the request body. Validates a changed slug against
	 * existing games before writing, coerces notifications_enabled to 0/1,
	 * and treats a request with no recognized fields as a no-op rather than
	 * a failure.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		// Reads the target game's slug from the URL params only, not the merged request body.
		$current_slug = $request->get_url_params()['slug'] ?? '';

		$game = Game::find_by_slug( $current_slug );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$data = [];
		// Only fields present in the request are included in the update.
		foreach ( [ 'name', 'slug', 'game_type', 'description', 'settings', 'asc_role_path', 'notifications_enabled' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = $value;
			}
		}

		// Normalizes a boolean notifications_enabled value to 0/1 for the tinyint column.
		if ( isset( $data['notifications_enabled'] ) ) {
			$data['notifications_enabled'] = $data['notifications_enabled'] ? 1 : 0;
		}

		// A changed slug that collides with a different existing game is rejected up front.
		if ( isset( $data['slug'] ) && $data['slug'] !== $game->slug ) {
			$existing = Game::find_by_slug( $data['slug'] );
			if ( $existing && $existing->id !== $game->id ) {
				return $this->error( 'duplicate_slug', __( 'A game with this slug already exists.', 'beyond-elysium' ), 409 );
			}
		}

		// An empty $data set is treated as a no-op rather than a failure.
		if ( ! empty( $data ) ) {
			$ok = Game::update( $current_slug, $data );
			if ( ! $ok ) {
				return $this->error( 'update_failed', __( 'Failed to update game.', 'beyond-elysium' ), 500 );
			}
		}

		$updated_slug = $data['slug'] ?? $current_slug;
		$updated = Game::find_by_slug( $updated_slug );
		return $this->success( $updated );
	}

	/**
	 * Deletes a game identified by slug after confirming it exists. Returns
	 * a 404 error when no matching game is found, otherwise removes the
	 * game and responds with an empty 204.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$game = Game::find_by_slug( $request['slug'] );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		Game::delete( $request['slug'] );
		return $this->success( null, 204 );
	}

	/**
	 * Defines the query parameters accepted by the games collection endpoint:
	 * a game_type filter, orderby/order sort controls, and page/per_page
	 * pagination, each with its allowed values and defaults.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return [
			'game_type' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'orderby' => [
				'type'    => 'string',
				'default' => 'name',
				'enum'    => [ 'name', 'slug', 'created_at', 'updated_at' ],
			],
			'order' => [
				'type'    => 'string',
				'default' => 'ASC',
				'enum'    => [ 'ASC', 'DESC' ],
			],
			'page' => [
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

	/**
	 * Defines the request parameters accepted when creating a game: the
	 * required name, an optional slug and game_type, a free-text description,
	 * and an arbitrary settings object, each with its sanitization rule.
	 *
	 * @return array
	 */
	private function get_create_params(): array {
		return [
			'name' => [
				'type'     => 'string',
				'required' => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'slug' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			],
			'game_type' => [
				'type'    => 'string',
				'default' => 'met',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'description' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'settings' => [
				'type' => 'object',
			],
		];
	}

	/**
	 * Defines the request parameters accepted when updating a game: the same
	 * fields as create, each optional since a PUT only changes the fields it
	 * includes. Malformed values are rejected by the REST parameter schema
	 * before reaching the model layer.
	 *
	 * @return array
	 */
	private function get_update_params(): array {
		return [
			'name' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'slug' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			],
			'game_type' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'description' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'settings' => [
				'type' => 'object',
			],
		];
	}
}
