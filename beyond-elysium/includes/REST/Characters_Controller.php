<?php

namespace BeyondElysium\REST;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\St_Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for player and non-player characters.
 */
class Characters_Controller extends Base_Controller {

	protected $rest_base = 'characters';

	/**
	 * Valid character status values.
	 */
	const STATUSES = Character::STATUSES;

	/**
	 * Registers the character routes.
	 */
	public function register_routes(): void {
		// Lists and creates characters for a game.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
				'args'                => $this->get_collection_params(),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_edit_own_characters', true ),
			],
		] );

		// The current user's own characters in this game.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/my/characters', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_my_characters' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/statuses', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_statuses' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		// Sets the same status on a batch of characters at once.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/bulk-status', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'bulk_status' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		// Retrieves, updates, or deletes a single character.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_edit_own_characters' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_delete_characters' ),
			],
		] );

		// Site-wide WordPress user search.
		register_rest_route( $this->namespace, '/wp-users', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'search_wp_users' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );

		// A chronicle Storyteller's account search for assigning a player to a character.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/wp-users', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'search_wp_users_for_chronicle' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * Searches WordPress users by display name, email, or login.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function search_wp_users( $request ) {
		$search = trim( (string) $request->get_param( 'search' ) );

		$args = [
			'number'  => 50,
			'orderby' => 'display_name',
			'order'   => 'ASC',
		];
		if ( $search !== '' ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = [ 'display_name', 'user_email', 'user_login' ];
		}

		$users = get_users( $args );

		return $this->success( array_map(
			static fn( $u ) => [ 'id' => $u->ID, 'display_name' => $u->display_name, 'email' => $u->user_email ],
			$users
		) );
	}

	/**
	 * Finds an account to assign as a character's player, for a Storyteller of this chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function search_wp_users_for_chronicle( $request ) {
		$search = trim( (string) $request->get_param( 'search' ) );
		if ( mb_strlen( $search ) < 3 ) {
			return $this->success( [] );
		}

		if ( is_email( $search ) ) {
			$user = get_user_by( 'email', $search );
			return $this->success( $user ? [ [ 'id' => $user->ID, 'display_name' => $user->display_name, 'email' => $user->user_email ] ] : [] );
		}

		$users = get_users( [
			'number'         => 20,
			'orderby'        => 'display_name',
			'order'          => 'ASC',
			'search'         => '*' . $search . '*',
			'search_columns' => [ 'display_name', 'user_login' ],
		] );

		return $this->success( array_map(
			static fn( $u ) => [ 'id' => $u->ID, 'display_name' => $u->display_name ],
			$users
		) );
	}

	/**
	 * Returns the fixed status vocabulary a bulk-status picker offers.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_statuses() {
		return $this->success( [ 'statuses' => self::STATUSES ] );
	}

	/**
	 * Sets the same status on a batch of characters at once.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_status( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character_ids = $request->get_param( 'character_ids' );
		if ( empty( $character_ids ) || ! is_array( $character_ids ) ) {
			return $this->error( 'invalid_param', __( 'character_ids must be a non-empty array of integers.', 'beyond-elysium' ), 400 );
		}

		$status = $request->get_param( 'status' );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'status must be one of: %s.', 'beyond-elysium' ), implode( ', ', self::STATUSES ) ), 400 );
		}

		$results = Character::bulk_update_status( array_map( 'intval', $character_ids ), $status, $request['game_slug'] );

		return $this->success( [
			'results' => $results,
			'updated' => count( array_filter( $results, static fn( $r ) => $r['success'] ) ),
		] );
	}

	/**
	 * Lists characters for a game with optional filters and pagination.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		// An exact UUID match short-circuits the filtered listing, returned as a single-item collection.
		$uuid = $request->get_param( 'uuid' );
		if ( $uuid ) {
			$character = Character::find_by_uuid( (string) $uuid );
			$visible   = $character
				&& $character->owner_slug === $request['game_slug']
				&& ( \BeyondElysium\Core\Authorization::can( 'be_manage_characters' ) || (int) $character->wp_user_id === get_current_user_id() );
			$found = $visible ? [ $character ] : [];
			return $this->paginate( $this->success( $found ), count( $found ), 1, 1 );
		}

		$can_manage = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );
		// A Narrator may list every character, to allocate actions.
		$can_view_roster = $can_manage || \BeyondElysium\Core\Authorization::can( 'be_manage_plots' );

		$pagination = $this->get_pagination( $request );
		$args       = [
			'status'     => $request->get_param( 'status' ),
			'stack_slug' => $request->get_param( 'stack_slug' ),
			// A non-manager is restricted to their own characters regardless of the requested wp_user_id.
			'wp_user_id' => $can_view_roster ? $request->get_param( 'wp_user_id' ) : get_current_user_id(),
			'is_npc'     => $can_manage && $request->get_param( 'is_npc' ) !== null ? $request->get_param( 'is_npc' ) : 0,
			'search'     => $request->get_param( 'search' ),
			'orderby'    => $request->get_param( 'orderby' ) ?: 'name',
			'order'      => $request->get_param( 'order' ) ?: 'ASC',
			'per_page'   => $pagination['per_page'],
			'offset'     => $pagination['offset'],
		];

		$items = Character::all_for_game( $request['game_slug'], $args );

		// The newest open transfer per uuid for the page.
		$travel_states = Transfer::open_states_for_game( $request['game_slug'] );

		foreach ( $items as $item ) {
			$item->image_url = $item->image_id ? wp_get_attachment_image_url( (int) $item->image_id, 'thumbnail' ) : null;
			$item->travelling_status = $travel_states[ $item->uuid ] ?? null;
			self::apply_computed_player_fields( $item, $can_manage );
		}

		$hidden = $can_manage ? [] : Schema_Block::storyteller_only_slugs( $game->slug );
		foreach ( $items as $item ) {
			St_Visibility::filter_character( $item, $game, $can_manage, $hidden );
		}

		$total    = Character::count_for_game( $request['game_slug'], $args );
		$response = $this->success( $items );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * Retrieves a single character by ID, scoped to the game.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = Character::find( (int) $request['id'] );
		if ( ! $character ) {
			return $this->error( 'character_not_found', __( 'Character not found.', 'beyond-elysium' ), 404 );
		}

		// Verify character belongs to this game.
		if ( $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		$can_manage      = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );
		$can_view_roster = $can_manage || \BeyondElysium\Core\Authorization::can( 'be_manage_plots' );

		// A non-manager (and not a Narrator viewing someone else's) may only view their own character.
		if ( ! $can_view_roster && (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You do not have permission to view this character.', 'beyond-elysium' ), 403 );
		}

		St_Visibility::filter_character( $character, $game, $can_manage );

		// Computed permission flags.
		$character->can_edit   = $this->can_edit_character( $character );
		$character->can_manage = $can_manage;
		// A per-user grant (User_Settings).
		$character->can_customize_sheet = current_user_can( 'be_customize_sheet' )
			&& ( $can_manage || (int) $character->wp_user_id === get_current_user_id() );

		// Resolves the stored attachment ID to a real URL for the client.
		$character->image_url = $character->image_id
			? wp_get_attachment_image_url( (int) $character->image_id, 'medium' )
			: null;

		$character->travelling_status = Transfer::open_states_for_game( $request['game_slug'] )[ $character->uuid ] ?? null;

		self::apply_computed_player_fields( $character, $can_manage );

		return $this->success( $character );
	}

	/**
	 * Lists the current user's own characters in this game.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_my_characters( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$items      = Character::find_for_user( get_current_user_id(), $request['game_slug'] );
		$can_manage = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );
		$hidden     = $can_manage ? [] : Schema_Block::storyteller_only_slugs( $game->slug );

		foreach ( $items as $item ) {
			$item->image_url = $item->image_id ? wp_get_attachment_image_url( (int) $item->image_id, 'thumbnail' ) : null;
			St_Visibility::filter_character( $item, $game, $can_manage, $hidden );
		}

		return $this->success( $items );
	}

	/**
	 * Computes the player_name and pending_match fields on a character.
	 *
	 * @param object $character
	 * @param bool   $can_manage
	 */
	private static function apply_computed_player_fields( $character, bool $can_manage ): void {
		if ( $character->wp_user_id ) {
			$user = get_userdata( (int) $character->wp_user_id );
			if ( $user ) {
				$character->player_name = $user->display_name;
			}
			if ( $can_manage ) {
				$character->pending_match = null;
			}
			return;
		}

		if ( ! $can_manage ) {
			return;
		}

		$match = $character->pending_player_email ? get_user_by( 'email', $character->pending_player_email ) : false;
		$character->pending_match = $match ? [ 'id' => $match->ID, 'display_name' => $match->display_name ] : null;
	}

	/**
	 * Creates a new character in the game.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$name = $request->get_param( 'name' );
		if ( empty( $name ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: name.', 'beyond-elysium' ), 400 );
		}

		$stack_slug = $request->get_param( 'stack_slug' );
		if ( empty( $stack_slug ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: stack_slug.', 'beyond-elysium' ), 400 );
		}

		// Refuses a creature type this chronicle has not enabled.
		$allowed_stacks = array_column( Creature_Stack::all_for_game( $request['game_slug'] ), 'slug' );
		if ( ! in_array( $stack_slug, $allowed_stacks, true ) ) {
			return $this->error(
				'invalid_param',
				sprintf(
					/* translators: %s: comma-separated list of stack slugs this chronicle allows */
					__( 'stack_slug must be one of the creature types this chronicle allows: %s.', 'beyond-elysium' ),
					implode( ', ', $allowed_stacks )
				),
				400
			);
		}

		$is_manager = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );

		// A non-manager's wp_user_id is always forced to their own id, never taken from the request.
		$wp_user_id = $is_manager ? $request->get_param( 'wp_user_id' ) : get_current_user_id();

		$requested_status = $request->get_param( 'status' );
		if ( $requested_status && ! in_array( $requested_status, self::STATUSES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'status must be one of: %s.', 'beyond-elysium' ), implode( ', ', self::STATUSES ) ), 400 );
		}

		// A newcomer with no membership or accessSchema grant asks to join.
		$joining = ! $is_manager && ! \BeyondElysium\Core\Authorization::can( 'be_edit_own_characters' );
		if ( $joining ) {
			$waiting = Manager::get_var(
				'SELECT COUNT(*) FROM ' . Manager::table( 'characters' ) . " WHERE owner_type = 'chronicle' AND owner_slug = %s AND wp_user_id = %d AND status = 'pending'",
				$request['game_slug'],
				get_current_user_id()
			);
			if ( (int) $waiting > 0 ) {
				return $this->error( 'join_already_requested', __( 'Your character is already waiting for this chronicle\'s Storytellers to approve it.', 'beyond-elysium' ), 409 );
			}
			// A waiting Grapevine file for this chronicle blocks a second request.
			if ( \BeyondElysium\Models\Submission::has_waiting( (int) $game->id, get_current_user_id() ) ) {
				return $this->error( 'join_already_requested', __( 'Your character is already waiting for this chronicle\'s Storytellers to approve it.', 'beyond-elysium' ), 409 );
			}
		}

		if ( $is_manager ) {
			$status = $requested_status ?: 'active';
		} elseif ( $joining ) {
			$status = 'pending';
		} else {
			// A non-manager's status is never trusted.
			$status = ! empty( $game->settings->require_new_character_approval ) ? 'pending' : 'active';
		}

		$start_date = $request->get_param( 'start_date' );
		if ( $start_date && ! self::is_valid_date( $start_date ) ) {
			return $this->error( 'invalid_param', __( 'start_date must be a valid date (YYYY-MM-DD).', 'beyond-elysium' ), 400 );
		}

		// The starting sheet is written directly, never priced or turned into Change records.
		$sheet_data = $request->get_param( 'sheet_data' );
		if ( $sheet_data !== null && ! is_array( $sheet_data ) ) {
			return $this->error( 'invalid_param', __( 'sheet_data must be an object.', 'beyond-elysium' ), 400 );
		}
		$sheet_data = $sheet_data ?: [];

		$resolved = Creature_Stack::resolve( $stack_slug, $request['game_slug'] );
		if ( ! $resolved ) {
			return $this->error( 'invalid_param', __( 'stack_slug does not resolve to a real creature stack.', 'beyond-elysium' ), 400 );
		}

		if ( ! empty( $sheet_data ) ) {
			$unknown_blocks = array_diff( array_keys( $sheet_data ), array_keys( $resolved['blocks'] ) );
			if ( $unknown_blocks ) {
				return $this->error(
					'invalid_param',
					sprintf( __( 'sheet_data references block(s) not on this creature stack: %s.', 'beyond-elysium' ), implode( ', ', $unknown_blocks ) ),
					400
				);
			}

			// Enforces the chronicle's sub-faction restrictions.
			$disallowed = Creature_Stack::find_disallowed_identity_value( $stack_slug, $sheet_data, $resolved['blocks'], $request['game_slug'] );
			if ( $disallowed ) {
				return $this->error(
					'invalid_param',
					sprintf(
						/* translators: 1: identity field name, 2: the disallowed value submitted */
						__( '%1$s "%2$s" is not allowed by this chronicle.', 'beyond-elysium' ),
						$disallowed['field'],
						$disallowed['value']
					),
					400
				);
			}
		}

		// Applies each block's own default_held starting template.
		foreach ( $resolved['blocks'] as $block_slug => $block ) {
			if ( array_key_exists( $block_slug, $sheet_data ) ) {
				continue;
			}
			$default_held = $block->definition->default_held ?? null;
			if ( $default_held ) {
				$sheet_data[ $block_slug ] = $default_held;
			}
		}

		$data = [
			'name'        => sanitize_text_field( $name ),
			'stack_slug'  => sanitize_text_field( $stack_slug ),
			'owner_type'  => 'chronicle',
			'owner_slug'  => $request['game_slug'],
			'wp_user_id'  => $wp_user_id,
			// Stored only when there is no wp_user_id.
			'player_name' => $wp_user_id
				? null
				: ( $request->get_param( 'player_name' ) ? sanitize_text_field( $request->get_param( 'player_name' ) ) : null ),
			'status'      => $status,
			// A non-manager's is_npc claim is never trusted.
			'is_npc'      => $is_manager && $request->get_param( 'is_npc' ) ? 1 : 0,
			// Whether a new NPC is quick or full.
			'npc_detail'  => $is_manager && $request->get_param( 'npc_detail' ) === 'quick' ? 'quick' : 'full',
			'narrator'    => $request->get_param( 'narrator' ) ? sanitize_text_field( $request->get_param( 'narrator' ) ) : null,
			'start_date'  => $start_date,
			// Biography and notes are rich text, sanitized with the same allowlist as post content.
			'biography'   => $request->get_param( 'biography' ) ? wp_kses_post( $request->get_param( 'biography' ) ) : null,
			'notes'       => $request->get_param( 'notes' ) ? wp_kses_post( $request->get_param( 'notes' ) ) : null,
			'rp_notes'    => $request->get_param( 'rp_notes' ) ? sanitize_textarea_field( $request->get_param( 'rp_notes' ) ) : null,
			'sheet_data'  => $sheet_data,
			// A join request grants no membership until a Storyteller activates the character.
			'await_approval' => $joining,
		];

		$id = Character::create( $data );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create character.', 'beyond-elysium' ), 500 );
		}

		$character = Character::find( $id );
		if ( ! $character ) {
			return $this->error( 'character_not_found', __( 'Character not found.', 'beyond-elysium' ), 404 );
		}
		if ( $joining ) {
			\BeyondElysium\Core\Notifications::join_requested( $game, $character, wp_get_current_user() );
			$character->join_pending = true;
		}
		return $this->success( $character, 201 );
	}

	/**
	 * Updates the header fields of a character.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = Character::find( (int) $request['id'] );
		if ( ! $character ) {
			return $this->error( 'character_not_found', __( 'Character not found.', 'beyond-elysium' ), 404 );
		}

		if ( $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		if ( ! $this->can_edit_character( $character ) ) {
			return $this->error( 'ownership_denied', __( 'You do not have permission to edit this character.', 'beyond-elysium' ), 403 );
		}

		$is_manager = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );

		// A player edits their own character's story and portrait.
		$allowed_fields = [ 'name', 'biography', 'notes', 'player_name', 'start_date', 'image_id' ];
		if ( $is_manager ) {
			array_push( $allowed_fields, 'status', 'narrator', 'rp_notes', 'is_npc', 'npc_detail' );
		}

		// Sanitized the same way create_item() sanitizes each field.
		$sanitizers = [
			'name'        => 'sanitize_text_field',
			'narrator'    => 'sanitize_text_field',
			'player_name' => 'sanitize_text_field',
			'biography'   => 'wp_kses_post',
			'notes'       => 'wp_kses_post',
			'rp_notes'    => 'sanitize_textarea_field',
		];

		$data = [];
		foreach ( $allowed_fields as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = isset( $sanitizers[ $field ] ) ? call_user_func( $sanitizers[ $field ], (string) $value ) : $value;
			}
		}

		if ( isset( $data['status'] ) && ! in_array( $data['status'], self::STATUSES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'status must be one of: %s.', 'beyond-elysium' ), implode( ', ', self::STATUSES ) ), 400 );
		}

		// Promotes a quick NPC to a full one.
		if ( isset( $data['npc_detail'] ) && ! in_array( $data['npc_detail'], [ 'full', 'quick' ], true ) ) {
			return $this->error( 'invalid_param', __( 'npc_detail must be one of: full, quick.', 'beyond-elysium' ), 400 );
		}

		// Manager-only; uses has_param() to distinguish an omitted field from an explicit unassign.
		if ( $is_manager && $request->has_param( 'wp_user_id' ) ) {
			$raw = $request->get_param( 'wp_user_id' );
			if ( $raw === null || $raw === '' || (int) $raw === 0 ) {
				$data['wp_user_id'] = null;
			} else {
				$user = get_userdata( (int) $raw );
				if ( ! $user ) {
					return $this->error( 'invalid_param', __( 'wp_user_id does not match a real WordPress user.', 'beyond-elysium' ), 400 );
				}
				$data['wp_user_id'] = (int) $raw;
			}
		}

		// Manager-only; uses the same has_param() tri-state handling as wp_user_id.
		if ( $is_manager && $request->has_param( 'pending_player_email' ) ) {
			$raw = trim( (string) $request->get_param( 'pending_player_email' ) );
			if ( $raw === '' ) {
				$data['pending_player_email'] = null;
			} elseif ( ! is_email( $raw ) ) {
				return $this->error( 'invalid_param', __( 'pending_player_email must be a valid email address.', 'beyond-elysium' ), 400 );
			} else {
				$data['pending_player_email'] = sanitize_email( $raw );
			}
		}
		if ( isset( $data['wp_user_id'] ) && $data['wp_user_id'] !== null && ! $request->has_param( 'pending_player_email' ) ) {
			$data['pending_player_email'] = null;
		}

		// Uses whichever wp_user_id this request leaves in place, newly assigned or pre-existing.
		$effective_wp_user_id = array_key_exists( 'wp_user_id', $data ) ? $data['wp_user_id'] : $character->wp_user_id;
		if ( $effective_wp_user_id ) {
			unset( $data['player_name'] );
			// A new assignment in this request also clears any stale stored player_name.
			if ( array_key_exists( 'wp_user_id', $data ) && $data['wp_user_id'] !== null ) {
				$data['player_name'] = null;
			}
		}

		if ( isset( $data['start_date'] ) && ! self::is_valid_date( $data['start_date'] ) ) {
			return $this->error( 'invalid_param', __( 'start_date must be a valid date (YYYY-MM-DD).', 'beyond-elysium' ), 400 );
		}

		if ( ! empty( $data['image_id'] ) && get_post_type( (int) $data['image_id'] ) !== 'attachment' ) {
			return $this->error( 'invalid_param', __( 'image_id must be a real media attachment.', 'beyond-elysium' ), 400 );
		}

		if ( isset( $data['is_npc'] ) ) {
			$data['is_npc'] = $data['is_npc'] ? 1 : 0;
		}

		// Staff assignment for an NPC.
		if ( $is_manager && $character->is_npc && $request->has_param( 'assigned_to' ) ) {
			$raw = $request->get_param( 'assigned_to' );
			if ( $raw === null || $raw === '' || (int) $raw === 0 ) {
				$data['assigned_to'] = null;
			} else {
				$assignee = \BeyondElysium\Models\Game_Member::find( (int) $game->id, (int) $raw );
				if ( ! $assignee || ! in_array( $assignee->role, \BeyondElysium\Models\Game_Member::STAFF_ROLES, true ) ) {
					return $this->error( 'invalid_assignee', __( 'assigned_to must be a chronicle member with role hst, ast, or narrator.', 'beyond-elysium' ), 400 );
				}
				$data['assigned_to'] = (int) $raw;
			}
		}

		Character::update_header( (int) $request['id'], $data );

		$updated = Character::find( (int) $request['id'] );
		if ( ! \BeyondElysium\Core\Authorization::can( 'be_manage_characters' ) ) {
			unset( $updated->rp_notes );
		}
		return $this->success( $updated );
	}

	/**
	 * Deletes a character.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = Character::find( (int) $request['id'] );
		if ( ! $character ) {
			return $this->error( 'character_not_found', __( 'Character not found.', 'beyond-elysium' ), 404 );
		}

		if ( $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		Character::delete( (int) $request['id'] );
		return $this->success( null, 204 );
	}

	/**
	 * Resolves a game by slug, returning a WP_Error if not found.
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
	 * Checks whether the current user can edit the given character.
	 *
	 * @param object $character
	 * @return bool
	 */
	protected function can_edit_character( $character ): bool {
		if ( \BeyondElysium\Core\Authorization::can( 'be_manage_characters' ) ) {
			return true;
		}
		// Player can edit only their own character.
		return (int) $character->wp_user_id === get_current_user_id();
	}

	/**
	 * Checks whether a value is a valid Y-m-d date string.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	private static function is_valid_date( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$parsed = \DateTime::createFromFormat( 'Y-m-d', $value );
		return $parsed !== false && $parsed->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Defines the query parameters accepted by the collection endpoint.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return [
			'status'     => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'stack_slug' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'is_npc'     => [
				'type' => 'boolean',
			],
			'search'     => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'uuid'       => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => 'Exact lookup by permanent character UUID.',
			],
			'orderby'    => [
				'type'    => 'string',
				'default' => 'name',
				'enum'    => [ 'name', 'status', 'created_at', 'updated_at', 'xp_earned', 'xp_unspent', 'stack_slug', 'player_name' ],
			],
			'order'      => [
				'type'    => 'string',
				'default' => 'ASC',
				'enum'    => [ 'ASC', 'DESC' ],
			],
			'page'       => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page'   => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
		];
	}
}
