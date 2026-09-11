<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\St_Filter;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for player and non-player characters.
 *
 * Covers the full character resource: listing with filtering and
 * pagination, single-character retrieval, the player's own "my characters"
 * view, creation (including hand-entered starting sheets), header updates,
 * deletion, and a WordPress-user search used to assign a character to a
 * real account.
 */
class Characters_Controller extends Base_Controller {

	protected $rest_base = 'characters';

	/** Valid character status values; "pending" applies only when a game requires approval for new characters. */
	const STATUSES = [ 'active', 'inactive', 'retired', 'dead', 'pending' ];

	/**
	 * Registers the character routes.
	 *
	 * Adds the game-scoped collection and single-character routes, the
	 * current user's "my characters" route, and a site-wide WordPress-user
	 * search route used to assign a character to a real account.
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
				// Bootstraps access so a player with no prior membership can create their first character.
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
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		// Site-wide WordPress user search, not scoped to any one game.
		register_rest_route( $this->namespace, '/wp-users', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'search_wp_users' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * Searches WordPress users by display name, email, or login.
	 *
	 * A purpose-built search used to find and assign a real WordPress
	 * account to a character, since core's own users REST route does not
	 * expose email. Returns up to 50 matches with id, display name, and
	 * email.
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
	 * Lists characters for a game with optional filters and pagination.
	 *
	 * Supports an exact UUID lookup that short-circuits the normal filtered
	 * listing, plus filtering by status, stack, owner, NPC flag, and search
	 * text. Restricts non-managers to their own characters, hides NPCs from
	 * non-managers, and strips ST-only content from the response for them.
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
				&& ( current_user_can( 'be_manage_characters' ) || (int) $character->wp_user_id === get_current_user_id() );
			$found = $visible ? [ $character ] : [];
			return $this->paginate( $this->success( $found ), count( $found ), 1, 1 );
		}

		$can_manage = current_user_can( 'be_manage_characters' );

		$pagination = $this->get_pagination( $request );
		$args       = [
			'status'     => $request->get_param( 'status' ),
			'stack_slug' => $request->get_param( 'stack_slug' ),
			// A non-manager is restricted to their own characters regardless of the requested wp_user_id.
			'wp_user_id' => $can_manage ? $request->get_param( 'wp_user_id' ) : get_current_user_id(),
			// NPCs are hidden from non-managers, and default to excluded even for a manager unless explicitly requested.
			'is_npc'     => $can_manage && $request->get_param( 'is_npc' ) !== null ? $request->get_param( 'is_npc' ) : 0,
			'search'     => $request->get_param( 'search' ),
			'orderby'    => $request->get_param( 'orderby' ) ?: 'name',
			'order'      => $request->get_param( 'order' ) ?: 'ASC',
			'per_page'   => $pagination['per_page'],
			'offset'     => $pagination['offset'],
		];

		$items = Character::all_for_game( $request['game_slug'], $args );

		foreach ( $items as $item ) {
			// Uses the thumbnail size since a roster renders many of these per page.
			$item->image_url = $item->image_id ? wp_get_attachment_image_url( (int) $item->image_id, 'thumbnail' ) : null;
			self::apply_computed_player_fields( $item, $can_manage );
		}

		if ( ! current_user_can( 'be_manage_characters' ) ) {
			// Looked up once for the whole page rather than per character.
			$hidden = Schema_Block::storyteller_only_slugs();
			foreach ( $items as $item ) {
				unset( $item->rp_notes );
				$item->biography = St_Filter::strip_for_game( (string) $item->biography, $game->settings ?? null );
				$item->notes     = St_Filter::strip_for_game( (string) $item->notes, $game->settings ?? null );
				self::strip_storyteller_only_blocks( $item, $hidden );
			}
		}

		$total    = Character::count_for_game( $request['game_slug'], $args );
		$response = $this->success( $items );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * Retrieves a single character by ID, scoped to the game.
	 *
	 * Restricts a non-manager to viewing only their own character, strips
	 * ST-only content when the viewer is not a manager, and adds computed
	 * fields such as image URL, editability, and the viewer's own
	 * capability flags.
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

		// A non-manager may only view their own character.
		if ( ! current_user_can( 'be_manage_characters' ) && (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You do not have permission to view this character.', 'beyond-elysium' ), 403 );
		}

		// Strips rp_notes and ST-only text for viewers without management capability.
		if ( ! current_user_can( 'be_manage_characters' ) ) {
			unset( $character->rp_notes );
			$character->biography = St_Filter::strip_for_game( (string) $character->biography, $game->settings ?? null );
			$character->notes     = St_Filter::strip_for_game( (string) $character->notes, $game->settings ?? null );
			self::strip_storyteller_only_blocks( $character, Schema_Block::storyteller_only_slugs() );
		}

		// Computed permission flags so the client can decide whether to render an editor or a read-only sheet.
		$character->can_edit   = $this->can_edit_character( $character );
		$character->can_manage = current_user_can( 'be_manage_characters' );
		// A separate capability from can_manage, kept distinct for future finer-grained permissions.
		$character->can_customize_sheet = current_user_can( 'be_customize_sheet' );

		// Resolves the stored attachment ID to a real URL for the client.
		$character->image_url = $character->image_id
			? wp_get_attachment_image_url( (int) $character->image_id, 'medium' )
			: null;

		self::apply_computed_player_fields( $character, current_user_can( 'be_manage_characters' ) );

		return $this->success( $character );
	}

	/**
	 * Lists the current user's own characters in this game.
	 *
	 * Resolves every character owned by the current user via
	 * `Character::find_for_user()`, adding a thumbnail image URL to each
	 * and stripping ST-only content when the viewer is not a manager.
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
		$can_manage = current_user_can( 'be_manage_characters' );
		// Looked up once for the whole page rather than per character.
		$hidden     = $can_manage ? [] : Schema_Block::storyteller_only_slugs();

		foreach ( $items as $item ) {
			$item->image_url = $item->image_id ? wp_get_attachment_image_url( (int) $item->image_id, 'thumbnail' ) : null;
			if ( ! $can_manage ) {
				unset( $item->rp_notes );
				$item->biography = St_Filter::strip_for_game( (string) $item->biography, $game->settings ?? null );
				$item->notes     = St_Filter::strip_for_game( (string) $item->notes, $game->settings ?? null );
				self::strip_storyteller_only_blocks( $item, $hidden );
			}
		}

		return $this->success( $items );
	}

	/**
	 * Removes every Storyteller-only block's stored values from a
	 * character's sheet_data. Dropping the section from a resolved template
	 * layout does not cover this on its own, since the block's own data
	 * would still ship inside the character payload.
	 *
	 * @param object $character
	 */
	private static function strip_storyteller_only_blocks( $character, array $hidden ): void {
		if ( ! is_array( $character->sheet_data ?? null ) ) {
			return;
		}

		foreach ( $hidden as $slug ) {
			unset( $character->sheet_data[ $slug ] );
		}
	}

	/**
	 * Computes the player_name and pending_match fields on a character.
	 *
	 * When a character has a real `wp_user_id`, overwrites `player_name`
	 * with that user's current display name, since the stored column is not
	 * authoritative once an account is attached. When it has none, resolves
	 * a `pending_match` candidate from `pending_player_email`, visible only
	 * to a manager.
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
	 * Validates the required fields, resolves status and owning user
	 * according to the caller's role, validates any hand-entered starting
	 * sheet data against the chosen creature stack's blocks, and creates
	 * the character record in one call.
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

		$is_manager = current_user_can( 'be_manage_characters' );

		// A non-manager's wp_user_id is always forced to their own id, never taken from the request.
		$wp_user_id = $is_manager ? $request->get_param( 'wp_user_id' ) : get_current_user_id();

		$requested_status = $request->get_param( 'status' );
		if ( $requested_status && ! in_array( $requested_status, self::STATUSES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'status must be one of: %s.', 'beyond-elysium' ), implode( ', ', self::STATUSES ) ), 400 );
		}

		if ( $is_manager ) {
			$status = $requested_status ?: 'active';
		} else {
			// A non-manager's status is never trusted; it is pending only if the game requires approval, active otherwise.
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
		if ( ! empty( $sheet_data ) ) {
			// Resolves against the game's own customized blocks, not just the base catalog.
			$resolved = Creature_Stack::resolve( $stack_slug, $request['game_slug'] );
			if ( ! $resolved ) {
				return $this->error( 'invalid_param', __( 'stack_slug does not resolve to a real creature stack.', 'beyond-elysium' ), 400 );
			}
			$unknown_blocks = array_diff( array_keys( $sheet_data ), array_keys( $resolved['blocks'] ) );
			if ( $unknown_blocks ) {
				return $this->error(
					'invalid_param',
					sprintf( __( 'sheet_data references block(s) not on this creature stack: %s.', 'beyond-elysium' ), implode( ', ', $unknown_blocks ) ),
					400
				);
			}
		}

		$data = [
			'name'        => sanitize_text_field( $name ),
			'stack_slug'  => sanitize_text_field( $stack_slug ),
			'owner_type'  => 'chronicle',
			'owner_slug'  => $request['game_slug'],
			'wp_user_id'  => $wp_user_id,
			// Stored only when there is no wp_user_id; otherwise resolved live from the account.
			'player_name' => $wp_user_id
				? null
				: ( $request->get_param( 'player_name' ) ? sanitize_text_field( $request->get_param( 'player_name' ) ) : null ),
			'status'      => $status,
			// A non-manager's is_npc claim is never trusted.
			'is_npc'      => $is_manager && $request->get_param( 'is_npc' ) ? 1 : 0,
			'narrator'    => $request->get_param( 'narrator' ) ? sanitize_text_field( $request->get_param( 'narrator' ) ) : null,
			'start_date'  => $start_date,
			// Biography and notes are rich text, sanitized with the same allowlist as post content.
			'biography'   => $request->get_param( 'biography' ) ? wp_kses_post( $request->get_param( 'biography' ) ) : null,
			'notes'       => $request->get_param( 'notes' ) ? wp_kses_post( $request->get_param( 'notes' ) ) : null,
			'rp_notes'    => $request->get_param( 'rp_notes' ) ? sanitize_textarea_field( $request->get_param( 'rp_notes' ) ) : null,
			'sheet_data'  => $sheet_data,
		];

		$id = Character::create( $data );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create character.', 'beyond-elysium' ), 500 );
		}

		$character = Character::find( $id );
		return $this->success( $character, 201 );
	}

	/**
	 * Updates the header fields of a character.
	 *
	 * Applies only an allowlisted set of fields; sheet_data is never
	 * touched here. Restricts is_npc, wp_user_id, and pending_player_email
	 * to managers, sanitizes rich-text fields, and validates status,
	 * start_date, and image_id.
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

		$is_manager = current_user_can( 'be_manage_characters' );

		// is_npc is manager-only; image_id is not, since a player may customize their own portrait.
		$allowed_fields = [ 'name', 'status', 'biography', 'notes', 'rp_notes', 'narrator', 'player_name', 'start_date', 'image_id' ];
		if ( $is_manager ) {
			$allowed_fields[] = 'is_npc';
		}

		// Only these two fields accept rich text; sanitized the same way as create_item().
		$rich_text_fields = [ 'biography', 'notes' ];

		$data = [];
		foreach ( $allowed_fields as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = in_array( $field, $rich_text_fields, true ) ? wp_kses_post( $value ) : $value;
			}
		}

		if ( isset( $data['status'] ) && ! in_array( $data['status'], self::STATUSES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'status must be one of: %s.', 'beyond-elysium' ), implode( ', ', self::STATUSES ) ), 400 );
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
		// Clears any pending email once a real player is actually assigned in this request.
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

		// Verifies image_id actually references a media attachment.
		if ( ! empty( $data['image_id'] ) && get_post_type( (int) $data['image_id'] ) !== 'attachment' ) {
			return $this->error( 'invalid_param', __( 'image_id must be a real media attachment.', 'beyond-elysium' ), 400 );
		}

		if ( isset( $data['is_npc'] ) ) {
			$data['is_npc'] = $data['is_npc'] ? 1 : 0;
		}

		Character::update_header( (int) $request['id'], $data );

		$updated = Character::find( (int) $request['id'] );
		if ( ! current_user_can( 'be_manage_characters' ) ) {
			unset( $updated->rp_notes );
		}
		return $this->success( $updated );
	}

	/**
	 * Deletes a character.
	 *
	 * Resolves the game and character, verifying the character belongs to
	 * it, then permanently removes the character record. Requires
	 * `be_manage_characters`.
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
	 * Looks up the game record for the given slug and returns a 404 error
	 * when no game matches it.
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
	 * A manager can edit any character in the game. A non-manager can edit
	 * only the character whose `wp_user_id` matches their own account.
	 *
	 * @param object $character
	 * @return bool
	 */
	protected function can_edit_character( $character ): bool {
		if ( current_user_can( 'be_manage_characters' ) ) {
			return true;
		}
		// Player can edit only their own character.
		return (int) $character->wp_user_id === get_current_user_id();
	}

	/**
	 * Checks whether a value is a valid Y-m-d date string.
	 *
	 * Parses the value with `DateTime::createFromFormat()` and confirms the
	 * parsed date formats back to exactly the input string, rejecting
	 * values like "2026-02-30" that parse but do not round-trip.
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
	 * Covers status, stack, NPC, search, UUID, exact-match filtering, sort
	 * order, and pagination.
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
