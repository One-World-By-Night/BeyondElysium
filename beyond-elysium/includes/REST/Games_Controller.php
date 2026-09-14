<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Ai_Assist;

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
		// The current user's own real chronicle memberships - page-consolidation-design.md's
		// chronicle switcher (unlike GET /games, which lists every chronicle on the install
		// to any logged-in user) reads this, never the full collection, so a player can never
		// see a chronicle they hold no membership in.
		register_rest_route( $this->namespace, '/my/games', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_my_games' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		// What the current user can actually do in one specific chronicle - answers
		// with every flag false for a user with no real relationship to this chronicle
		// rather than a 403, so a switcher can render "no access here" instead of failing
		// outright; open to any logged-in user rather than gated by a capability this
		// route's own job is to determine.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/my/capabilities', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_my_capabilities' ],
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			],
		] );

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
		foreach ( $items as $item ) {
			if ( isset( $item->settings ) && $item->settings instanceof \stdClass ) {
				Ai_Assist::redact_settings_read( $item->settings );
			}
		}
		$total = Game::count( $args );

		$response = $this->success( $items );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * Returns every chronicle the current user actually holds a membership row
	 * in, each with the role they hold there - the real data source for an
	 * in-page chronicle switcher (page-consolidation-design.md), as opposed to
	 * get_items()'s full collection, which lists every chronicle on the install
	 * to any logged-in user and must never back a front-end picker.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_my_games( $request ) {
		$memberships = Game_Member::for_user( get_current_user_id() );

		$games = [];
		foreach ( $memberships as $membership ) {
			$game = Game::find( (int) $membership->game_id );
			if ( ! $game ) {
				// A membership row surviving a game's own row-only delete (Game::delete()'s
				// documented "row only, content becomes unreachable" behavior) - skipped
				// rather than surfaced as a broken entry in the switcher.
				continue;
			}
			$games[] = [
				'slug' => $game->slug,
				'name' => $game->name,
				'role' => $membership->role,
			];
		}

		return $this->success( $games );
	}

	/**
	 * Returns what the current user can actually do in one specific chronicle -
	 * the same five flags `Plugin::enqueue_frontend()` localizes site-wide, but
	 * resolved per chronicle through `Authorization::check_request()` instead
	 * of a single `current_user_can()` snapshot computed before any chronicle
	 * is known. A game slug that doesn't resolve still returns every flag
	 * false rather than a 404 or 403, so a switcher can render "no access
	 * here" instead of a hard failure.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_my_capabilities( $request ) {
		$capability_list = [
			'be_manage_characters',
			'be_manage_plots',
			'be_manage_schemas',
			'be_manage_connections',
			'be_manage_boons',
		];

		if ( ! Game::find_by_slug( $request['game_slug'] ) ) {
			return $this->success( [ 'capabilities' => array_fill_keys( $capability_list, false ) ] );
		}

		return $this->success( [ 'capabilities' => Authorization::capabilities_for_request( $capability_list, $request ) ] );
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
		if ( isset( $game->settings ) && $game->settings instanceof \stdClass ) {
			Ai_Assist::redact_settings_read( $game->settings );
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

		$settings = $request->get_param( 'settings' );
		if ( is_array( $settings ) ) {
			// A create request is very unlikely to carry an AI key, but if one ever does,
			// it must never be stored in plaintext - same encrypt/clear rule as an update.
			$settings = Ai_Assist::merge_settings_write( $settings, [] );
		}

		$data = [
			'name'        => $name,
			'slug'        => $slug,
			'game_type'   => $request->get_param( 'game_type' ) ?: 'met',
			'description' => $request->get_param( 'description' ) ?: '',
			'settings'    => $settings,
		];

		$id = Game::create( $data );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create game.', 'beyond-elysium' ), 500 );
		}

		// GS-7 (guided-chronicle-setup-design.md §2.1, §3.3): this path writes no
		// membership row today, and the one-time backfill can never run again - a
		// chronicle created here would otherwise start with zero members forever.
		$creator_id = get_current_user_id();
		if ( $creator_id > 0 ) {
			\BeyondElysium\Models\Game_Member::set_role( (int) $id, $creator_id, 'hst' );
		}

		// Runs upgrade-time provisioning now that a game exists for it to act on.
		do_action( 'be_after_upgrade' );

		$game = Game::find( $id );
		if ( isset( $game->settings ) && $game->settings instanceof \stdClass ) {
			Ai_Assist::redact_settings_read( $game->settings );
		}
		return $this->success( $game, 201 );
	}

	/**
	 * Updates an existing game identified by slug with any of the recognized
	 * fields present in the request body. A slug change is routed through
	 * Game::rename() - the only path that cascades the change to every
	 * character, schema-block fork, page, and Elementor widget that names
	 * the old slug - rather than through the plain field update, which
	 * cannot change the slug at all. Coerces notifications_enabled to 0/1,
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

		$rename_report = null;
		$requested_slug = $request->get_param( 'slug' );
		if ( $requested_slug !== null && sanitize_title( $requested_slug ) !== $game->slug ) {
			$result = Game::rename( (int) $game->id, $requested_slug );

			if ( ! $result['changed'] ) {
				if ( ( $result['error'] ?? '' ) === 'duplicate_slug' ) {
					return $this->error( 'duplicate_slug', __( 'A game with this slug already exists.', 'beyond-elysium' ), 409 );
				}
				if ( ( $result['error'] ?? '' ) === 'fork_collision' ) {
					$blocks = implode( ', ', $result['blocks'] ?? [] );
					return $this->error(
						'fork_collision',
						sprintf(
							/* translators: %s: comma-separated list of schema block slugs */
							__( 'Cannot rename: a chronicle already using this slug left customized schema blocks behind (%s). Delete or rename that fork first, or choose a different slug.', 'beyond-elysium' ),
							$blocks
						),
						409
					);
				}
				return $this->error( 'rename_failed', __( 'Failed to rename game.', 'beyond-elysium' ), 500 );
			}

			$current_slug  = sanitize_title( $requested_slug );
			$rename_report = [
				'characters'    => $result['characters'],
				'schema_blocks' => $result['schema_blocks'],
				'pages'         => $result['pages'],
				'elementor'     => $result['elementor'],
			];
		}

		$data = [];
		// Only fields present in the request are included in the update. slug is handled
		// above, through rename() - Game::update() cannot change it at all.
		foreach ( [ 'name', 'game_type', 'description', 'settings', 'asc_role_path', 'notifications_enabled' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = $value;
			}
		}

		// Merge, never replace: `settings` is a shared bag (enabled_stacks, apr.*,
		// require_new_character_approval, auto_approve, ...) and Game::update() writes the
		// column wholesale (R6) - a caller sending only `enabled_stacks` must not silently
		// erase kony's own real `apr` object. Same hazard background-ledger-apr-design.md
		// flags for the Apr_Controller editor; one shared merge here covers both (GS-2/GS-6).
		if ( isset( $data['settings'] ) ) {
			$existing         = (array) ( $game->settings ?? new \stdClass() );
			$data['settings'] = Ai_Assist::merge_settings_write( (array) $data['settings'], $existing );
		}

		// Normalizes a boolean notifications_enabled value to 0/1 for the tinyint column.
		if ( isset( $data['notifications_enabled'] ) ) {
			$data['notifications_enabled'] = $data['notifications_enabled'] ? 1 : 0;
		}

		// An empty $data set is treated as a no-op rather than a failure.
		if ( ! empty( $data ) ) {
			$ok = Game::update( $current_slug, $data );
			if ( ! $ok ) {
				return $this->error( 'update_failed', __( 'Failed to update game.', 'beyond-elysium' ), 500 );
			}
		}

		$updated = Game::find_by_slug( $current_slug );
		if ( isset( $updated->settings ) && $updated->settings instanceof \stdClass ) {
			Ai_Assist::redact_settings_read( $updated->settings );
		}
		if ( $rename_report !== null ) {
			// stdClass from $wpdb->get_row() - a dynamic property here is not the PHP 8.2
			// deprecation (that applies to declared classes only), and this is the one
			// response the cascade report belongs on: the same request that triggered it.
			$updated->rename_report = $rename_report;
		}
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

		// GS-11: opt-in, since Game::delete()'s narrower "row only, content becomes
		// unreachable" behavior (Game.php's own doc comment) is what every existing
		// caller of this route already expects. The Setup checklist's demo-chronicle
		// delete action is the one real caller that needs the cascade.
		if ( $request->get_param( 'with_content' ) ) {
			Game::delete_with_content( $request['slug'] );
		} else {
			Game::delete( $request['slug'] );
		}
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
