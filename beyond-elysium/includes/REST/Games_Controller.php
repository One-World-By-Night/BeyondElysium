<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Ai_Assist;
use BeyondElysium\Services\St_Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the games resource.
 */
class Games_Controller extends Base_Controller {

	protected $rest_base = 'games';

	/**
	 * Registers the REST routes for the games collection and for a single game addressed by slug.
	 */
	public function register_routes(): void {
		// The current user's own real chronicle memberships.
		register_rest_route( $this->namespace, '/my/games', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_my_games' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

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

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<slug>[a-z0-9\-]+)/content', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_content_counts' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/chronicle-setup', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_chronicle_setup_settings' ],
				'permission_callback' => $this->permission( 'be_manage_chronicle_setup' ),
			],
		] );

		// Site-wide brand accent default.
		register_rest_route( $this->namespace, '/branding', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_branding' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_branding' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );
	}

	/**
	 * Returns the site-wide brand accent default (System Config -> Branding).
	 *
	 * @return \WP_REST_Response
	 */
	public function get_branding() {
		return $this->success( [
			'accent_color' => (string) get_option( Game::ACCENT_COLOR_OPTION, '' ),
		] );
	}

	/**
	 * Sets the site-wide brand accent default.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_branding( $request ) {
		$value = (string) ( $request->get_param( 'accent_color' ) ?? '' );
		if ( $value !== '' && ! self::is_valid_hex_color( $value ) ) {
			return $this->error( 'invalid_param', __( 'accent_color must be a hex color like #1a1a1a.', 'beyond-elysium' ), 400 );
		}
		update_option( Game::ACCENT_COLOR_OPTION, $value );
		return $this->success( [ 'accent_color' => $value ] );
	}

	/**
	 * Returns a paginated list of games, optionally filtered by game_type and ordered by the requested column and
	 * direction.
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
	 * Returns every chronicle the current user actually holds a membership row in, each with the role they hold there.
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
	 * Returns what the current user can actually do in one specific chronicle.
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
			// Gates the Storyteller Toolkit's World Objects tab.
			'be_manage_world_objects',
			// Gates the Storyteller Toolkit's Game Nights tab.
			'be_manage_sessions',
			// Gates the Game Nights downtime-window editor.
			'be_manage_apr',
			// Gates the Storyteller Toolkit's Factions tab.
			'be_manage_factions',
		];

		$game = Game::find_by_slug( $request['game_slug'] );
		if ( ! $game ) {
			return $this->success( [
				'capabilities' => array_fill_keys( $capability_list, false ),
				'accent_color' => '',
			] );
		}

		return $this->success( [
			'capabilities' => Authorization::capabilities_for_request( $capability_list, $request ),
			'accent_color' => Game::resolve_accent_color( $game ),
		] );
	}

	/**
	 * Returns a single game identified by its slug, or a 404 error when no game with that slug exists.
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
	 * Creates a new game from the request's name, slug, game_type, description, and settings fields.
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

		if ( ! empty( $slug ) && array_sum( Game::orphaned_content_counts( sanitize_title( $slug ) ) ) > 0 ) {
			return $this->error( 'slug_has_orphaned_content', __( 'A deleted chronicle\'s characters or records are still stored under this slug. Choose a different slug.', 'beyond-elysium' ), 409 );
		}

		$settings = $request->get_param( 'settings' );
		if ( is_array( $settings ) ) {
			// Encrypts or clears an AI key, as an update does.
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

		// Makes the creator the chronicle's HST.
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
	 * Updates an existing game identified by slug with any of the recognized fields present in the request body.
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
		// Only fields present in the request are included in the update. slug is handled above, through rename().
		foreach ( [ 'name', 'game_type', 'description', 'settings', 'asc_role_path', 'notifications_enabled' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = $value;
			}
		}

		// Merges `settings` into the stored settings rather than replacing them.
		if ( isset( $data['settings'] ) ) {
			$existing = (array) ( $game->settings ?? new \stdClass() );
			$incoming = (array) $data['settings'];
			// enabled_factions merges stack by stack and field by field.
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

		// An empty $data set is treated as a no-op.
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
			$updated->rename_report = $rename_report;
		}
		return $this->success( $updated );
	}

	/**
	 * Saves exactly the Chronicle Setup settings an HST may set for their own chronicle: enabled_stacks,
	 * enabled_factions, require_new_character_approval, accent_color and purchase_scope.
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
		foreach ( [ 'enabled_stacks', 'enabled_factions', 'require_new_character_approval', 'accent_color', 'purchase_scope' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$incoming[ $field ] = $value;
			}
		}

		if ( empty( $incoming ) ) {
			return $this->error( 'invalid_param', __( 'At least one of enabled_stacks, enabled_factions, require_new_character_approval, accent_color, or purchase_scope is required.', 'beyond-elysium' ), 400 );
		}

		// Empty string clears the override (falls through to the site-wide default).
		if ( isset( $incoming['accent_color'] ) && $incoming['accent_color'] !== '' && ! self::is_valid_hex_color( (string) $incoming['accent_color'] ) ) {
			return $this->error( 'invalid_param', __( 'accent_color must be a hex color like #1a1a1a.', 'beyond-elysium' ), 400 );
		}

		// Each area is switched on or off by itself.
		if ( isset( $incoming['purchase_scope'] ) ) {
			$switches = \BeyondElysium\Services\Purchase_Scope::sanitize( $incoming['purchase_scope'] );
			if ( $switches === null ) {
				return $this->error( 'invalid_param', __( 'purchase_scope must switch abilities, backgrounds, or merits_flaws on or off.', 'beyond-elysium' ), 400 );
			}
			$incoming['purchase_scope'] = $switches;
		}

		$existing = (array) ( $game->settings ?? new \stdClass() );
		// Same one-stack-at-a-time merge update_item() itself applies.
		if ( isset( $incoming['enabled_factions'] ) && is_array( $incoming['enabled_factions'] ) ) {
			$incoming['enabled_factions'] = self::merge_faction_restrictions(
				json_decode( (string) wp_json_encode( $existing['enabled_factions'] ?? [] ), true ) ?: [],
				$incoming['enabled_factions']
			);
		}
		if ( isset( $incoming['purchase_scope'] ) ) {
			$incoming['purchase_scope'] = array_merge(
				\BeyondElysium\Services\Purchase_Scope::normalize( $existing['purchase_scope'] ?? null ),
				$incoming['purchase_scope']
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
	 * Counts everything deleting this chronicle would delete with it.
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
	 * Deletes a chronicle identified by slug.
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
	 * Defines the query parameters accepted by the games collection endpoint: a game_type filter, orderby/order sort
	 * controls, and page/per_page pagination, each with its allowed values and defaults.
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
	 * Merges a write's sub-faction restrictions into the stored ones, stack by stack and field by field: a field the
	 * write names takes its new list (an empty list lifts that field's restriction), and every stack or field it doesn't
	 * name keeps its own.
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
	 * Checks whether a string is a 6-digit hex color prefixed with a hash, such as #1a1a1a.
	 *
	 * @param string $value
	 * @return bool
	 */
	private static function is_valid_hex_color( string $value ): bool {
		return (bool) preg_match( '/^#[0-9a-fA-F]{6}$/', $value );
	}

	/**
	 * Defines the request parameters accepted when creating a game: the required name, an optional slug and game_type, a
	 * free-text description, and an arbitrary settings object, each with its sanitization rule.
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
			// Rich text, same allowlist as post content.
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
	 * Defines the request parameters accepted when updating a game: the same fields as create, each optional.
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
