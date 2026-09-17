<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Ai_Assist;
use BeyondElysium\Services\St_Visibility;

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

		// What deleting a chronicle would take with it, so the Games screen can say so in its one confirmation.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<slug>[a-z0-9\-]+)/content', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_content_counts' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );

		// Owner ruling, 1.0.0-checklist.md item 18: a narrow slice of update_item()'s own
		// settings, carved into its own route (Decision 109's Default Approval Policy is the
		// precedent) so an HST can save these three without the full be_manage_games the rest
		// of update_item() still requires - a rename, description, or asc_role_path change.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/chronicle-setup', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_chronicle_setup_settings' ],
				'permission_callback' => $this->permission( 'be_manage_chronicle_setup' ),
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
		// Deliberately conservative. A chronicle description is a short blurb shown in
		// pickers and lists, and this route has no single chronicle to scope a role check
		// against, so anyone without the site-wide capability has it stripped. Over-filtering
		// a blurb costs nothing; under-filtering leaks.
		$can_manage = current_user_can( 'be_manage_games' );
		foreach ( $items as $item ) {
			if ( isset( $item->settings ) && $item->settings instanceof \stdClass ) {
				Ai_Assist::redact_settings_read( $item->settings );
			}
			St_Visibility::filter_game( $item, $can_manage );
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
			// Added for the Storyteller Toolkit's World Objects tab (1.0.1 D2). The catalog
			// existed only in wp-admin before that, so no front-end screen had ever needed
			// this capability resolved per chronicle.
			'be_manage_world_objects',
			// Added for the Storyteller Toolkit's Game Nights tab (1.1.0 §3.1).
			'be_manage_sessions',
			// Added for GameNights.tsx's own downtime-window editor (1.1.0 §3.3).
			'be_manage_apr',
			// Added for the Storyteller Toolkit's Factions tab (1.1.0 §3.10, F1/F2).
			'be_manage_factions',
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
		St_Visibility::filter_game( $game, Authorization::check_request( 'be_manage_games', $request ) );
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

		// A slug still holding a deleted chronicle's content would hand that content to this one (1.0.0-review F-036).
		if ( ! empty( $slug ) && array_sum( Game::orphaned_content_counts( sanitize_title( $slug ) ) ) > 0 ) {
			return $this->error( 'slug_has_orphaned_content', __( 'A deleted chronicle\'s characters or records are still stored under this slug. Choose a different slug.', 'beyond-elysium' ), 409 );
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
				if ( ( $result['error'] ?? '' ) === 'orphan_collision' ) {
					return $this->error(
						'orphan_collision',
						__( 'Cannot rename: a deleted chronicle\'s characters or records are still stored under this slug, and this chronicle would take them over. Choose a different slug.', 'beyond-elysium' ),
						409
					);
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
				'attestations'  => $result['attestations'],
				'transfers'     => $result['transfers'],
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
			$existing = (array) ( $game->settings ?? new \stdClass() );
			$incoming = (array) $data['settings'];
			// enabled_factions goes one level further: Chronicle Setup saves one stack's field at a
			// time, so a write changes the fields it names and keeps every other restriction - the
			// second save no longer erases the first (1.0.0-review F-066).
			if ( isset( $incoming['enabled_factions'] ) && is_array( $incoming['enabled_factions'] ) ) {
				$incoming['enabled_factions'] = self::merge_faction_restrictions(
					json_decode( (string) wp_json_encode( $existing['enabled_factions'] ?? [] ), true ) ?: [],
					$incoming['enabled_factions']
				);
			}
			$data['settings'] = Ai_Assist::merge_settings_write( $incoming, $existing );
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
		if ( ! $updated ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
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
	 * Saves exactly the three Chronicle Setup settings an HST may set for their own
	 * chronicle (owner ruling, 1.0.0-checklist.md item 18): enabled_stacks (creature
	 * types), enabled_factions (sub-faction restrictions), and
	 * require_new_character_approval. Merges into the chronicle's existing settings
	 * object the same way update_item() does - never a wholesale replace - since both
	 * routes write the same shared `settings` column (R6). Only these three field
	 * names are ever read from the request; every other game field (name, slug,
	 * description, asc_role_path, ...) still requires the full be_manage_games
	 * update_item() gates on.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_chronicle_setup_settings( $request ) {
		$game_slug = $request->get_url_params()['game_slug'] ?? '';

		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$incoming = [];
		foreach ( [ 'enabled_stacks', 'enabled_factions', 'require_new_character_approval' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$incoming[ $field ] = $value;
			}
		}

		if ( empty( $incoming ) ) {
			return $this->error( 'invalid_param', __( 'At least one of enabled_stacks, enabled_factions, or require_new_character_approval is required.', 'beyond-elysium' ), 400 );
		}

		$existing = (array) ( $game->settings ?? new \stdClass() );
		// Same one-stack-at-a-time merge update_item() itself applies (1.0.0-review F-066).
		if ( isset( $incoming['enabled_factions'] ) && is_array( $incoming['enabled_factions'] ) ) {
			$incoming['enabled_factions'] = self::merge_faction_restrictions(
				json_decode( (string) wp_json_encode( $existing['enabled_factions'] ?? [] ), true ) ?: [],
				$incoming['enabled_factions']
			);
		}

		$ok = Game::update( $game_slug, [ 'settings' => Ai_Assist::merge_settings_write( $incoming, $existing ) ] );
		if ( ! $ok ) {
			return $this->error( 'update_failed', __( 'Failed to update game.', 'beyond-elysium' ), 500 );
		}

		$updated = Game::find_by_slug( $game_slug );
		if ( ! $updated ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		if ( isset( $updated->settings ) && $updated->settings instanceof \stdClass ) {
			Ai_Assist::redact_settings_read( $updated->settings );
		}
		return $this->success( $updated );
	}

	/**
	 * Counts everything deleting this chronicle would delete with it -
	 * Game::content_counts(), the same numbers delete_item() refuses on.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_content_counts( $request ) {
		$game = Game::find_by_slug( $request['slug'] );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		return $this->success( Game::content_counts( $game ) );
	}

	/**
	 * Deletes a chronicle identified by slug. A chronicle that still holds
	 * content is deleted only when `with_content` is set, and then with all
	 * of it; without the flag the request is refused with 409
	 * `chronicle_has_content` and the counts, so the caller can show exactly
	 * what would be lost. Nothing is ever left behind under the slug for a
	 * later chronicle to inherit (1.0.0-review F-036).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$game = Game::find_by_slug( $request['slug'] );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$counts = Game::content_counts( $game );
		if ( ! $request->get_param( 'with_content' ) && array_sum( $counts ) > 0 ) {
			return new \WP_Error(
				'chronicle_has_content',
				__( 'This chronicle still holds characters or other content. Delete it with its content, or keep it.', 'beyond-elysium' ),
				[ 'status' => 409, 'counts' => $counts ]
			);
		}

		if ( ! Game::delete_with_content( $game->slug ) ) {
			return $this->error( 'delete_failed', __( 'Failed to delete the chronicle. Nothing was deleted.', 'beyond-elysium' ), 500 );
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
	 * Merges a write's sub-faction restrictions into the stored ones, stack
	 * by stack and field by field: a field the write names takes its new
	 * list (an empty list lifts that field's restriction), and every stack or
	 * field it doesn't name keeps its own.
	 *
	 * @param array<string,mixed> $existing Stored `enabled_factions`, stack => field => allowed values.
	 * @param array<mixed>        $incoming The write's `enabled_factions`, the same shape.
	 * @return array<string,mixed>
	 */
	private static function merge_faction_restrictions( array $existing, array $incoming ): array {
		foreach ( $incoming as $stack => $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field => $allowed ) {
				$existing[ (string) $stack ][ (string) $field ] = array_values( array_map( 'strval', (array) $allowed ) );
			}
		}
		return $existing;
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
			// Rich text, same allowlist as post content - matches biography/notes and
			// every plot free-text field (1.0.1 D1).
			'description' => [
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
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
			// Rich text - see the note on the same field in get_create_params().
			'description' => [
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			],
			'settings' => [
				'type' => 'object',
			],
		];
	}
}
