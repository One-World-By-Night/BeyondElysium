<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Notifications;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Cost_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for character sheet changes.
 *
 * Handles the full change lifecycle: a player submits a proposed change to
 * a character's sheet, the cost engine prices it, and either it auto-applies
 * or an ST reviews it through the approval queue. Also exposes change
 * history per character, a per-user pending-changes view, batch approval,
 * and a cost/approval preview endpoint used before a change is actually
 * submitted.
 */
class Changes_Controller extends Base_Controller {

	protected $rest_base = 'changes';

	/**
	 * Registers the game-scoped change routes.
	 *
	 * Adds routes for listing and submitting changes on one character, the
	 * cross-character approval queue and batch approval, the current user's
	 * own pending changes, a cost/approval preview, and single-change
	 * approval or rejection.
	 */
	public function register_routes(): void {
		// Lists and creates changes for one character.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<character_id>\d+)/changes', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
				'args'                => $this->get_collection_params(),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_edit_own_characters' ),
			],
		] );

		// The cross-character pending-changes queue.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/changes', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_queue' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
				'args'                => $this->get_collection_params(),
			],
		] );
		// Approves several queued changes in one request.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/changes/batch-approve', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'batch_approve' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		// The current user's own pending changes across all of their characters.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/my/changes', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_my_changes' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		// Prices a set of proposed changes without submitting them.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<character_id>\d+)/preview-changes', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'preview_changes' ],
				'permission_callback' => $this->permission( 'be_edit_own_characters' ),
			],
		] );

		// Approves or rejects a single change.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/changes/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * Lists changes for one character.
	 *
	 * Resolves the game and character from the URL, then returns a
	 * paginated, filterable list of that character's change records ordered
	 * by the requested sort direction.
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
			'status'      => $request->get_param( 'status' ),
			'change_type' => $request->get_param( 'change_type' ),
			'order'       => $request->get_param( 'order' ) ?: 'DESC',
			'per_page'    => $pagination['per_page'],
			'offset'      => $pagination['offset'],
		];

		$items    = Change::for_character( (int) $request['character_id'], $args );
		$total    = Change::count_for_character( (int) $request['character_id'], $args );
		$response = $this->success( $items );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * Submits a new change for a character.
	 *
	 * Validates the required fields, checks that a custom trait is only used
	 * on a block that allows it, enforces that a non-manager may only submit
	 * for their own character, prices the change through the cost engine,
	 * and hands it to `Change_Engine::submit()` to record and, where
	 * eligible, auto-apply.
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

		$change_type = $request->get_param( 'change_type' );
		if ( empty( $change_type ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: change_type.', 'beyond-elysium' ), 400 );
		}

		$category = $request->get_param( 'category' );
		if ( empty( $category ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: category.', 'beyond-elysium' ), 400 );
		}

		$change_data = $request->get_param( 'change_data' );
		if ( empty( $change_data ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: change_data.', 'beyond-elysium' ), 400 );
		}

		// A custom trait is only valid on a block whose definition allows it.
		if ( ! empty( $change_data['trait']['custom'] ) ) {
			$block_slug = $change_data['block_slug'] ?? null;
			$block      = $block_slug ? \BeyondElysium\Models\Schema_Block::find_by_slug( $block_slug ) : null;
			if ( ! $block || empty( $block->definition->allow_custom ) ) {
				return $this->error( 'custom_not_allowed', __( 'This block does not allow custom trait entries.', 'beyond-elysium' ), 400 );
			}
		}

		$is_manager = current_user_can( 'be_manage_characters' );

		// Ownership check: player can only submit for their own character.
		if ( ! $is_manager && (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You do not have permission to submit changes for this character.', 'beyond-elysium' ), 403 );
		}

		// Server-computed XP cost; a non-manager is refused if it would leave xp_unspent negative.
		$xp_cost = Cost_Engine::cost_for_change(
			$character,
			[ 'change_type' => $change_type, 'change_data' => $change_data ]
		);
		if ( ! $is_manager && $xp_cost > (int) $character->xp_unspent ) {
			return $this->error(
				'insufficient_xp',
				sprintf( __( 'This costs %d XP; only %d is unspent.', 'beyond-elysium' ), $xp_cost, (int) $character->xp_unspent ),
				400
			);
		}

		$change_id = Change_Engine::submit(
			(int) $request['character_id'],
			[
				'change_type' => $change_type,
				'category'    => $category,
				'change_data' => $change_data,
				'xp_cost'     => $xp_cost,
				'notes'       => $request->get_param( 'notes' ),
			],
			get_current_user_id()
		);

		if ( ! $change_id ) {
			return $this->error( 'submit_failed', __( 'Failed to submit change.', 'beyond-elysium' ), 500 );
		}

		$change = Change::find( $change_id );
		return $this->success( $change, 201 );
	}

	/**
	 * Approves or rejects a single change.
	 *
	 * Validates the requested status, confirms the change is still pending,
	 * then delegates to `Change_Engine::approve()` or `::reject()`. On
	 * success, enqueues and flushes a notification and invalidates the
	 * game's cached stats.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$new_status = $request->get_param( 'status' );
		if ( ! in_array( $new_status, [ 'approved', 'rejected' ], true ) ) {
			return $this->error( 'invalid_param', __( 'status must be "approved" or "rejected".', 'beyond-elysium' ), 400 );
		}

		$change = Change::find( (int) $request['id'] );
		if ( ! $change ) {
			return $this->error( 'not_found', __( 'Change not found.', 'beyond-elysium' ), 404 );
		}

		// Only a pending change may be reviewed; this guards against re-approving the same change twice.
		if ( $change->status !== 'pending' ) {
			return $this->error( 'change_not_pending', __( 'This change has already been reviewed.', 'beyond-elysium' ), 400 );
		}

		$notes  = $request->get_param( 'notes' );
		$result = false;

		if ( $new_status === 'approved' ) {
			$result = Change_Engine::approve( (int) $request['id'], get_current_user_id(), $notes );
		} else {
			$result = Change_Engine::reject( (int) $request['id'], get_current_user_id(), $notes );
		}

		if ( ! $result ) {
			return $this->error( 'update_failed', __( 'Failed to update change status.', 'beyond-elysium' ), 500 );
		}

		$updated = Change::find( (int) $request['id'] );

		// Sends the review notification immediately since this route only ever touches one change.
		$reviewed_character = Character::find( (int) $change->character_id );
		if ( $reviewed_character ) {
			Notifications::enqueue( $updated, $reviewed_character, get_current_user_id() );
		}
		Notifications::flush();

		// Invalidates the cached game stats so this review is reflected immediately.
		Game_Stats_Controller::invalidate( $request['game_slug'] );

		return $this->success( $updated );
	}

	/**
	 * Lists the approval queue: pending (or otherwise filtered) changes
	 * across every character in the game.
	 *
	 * Loads matching change records, then enriches each row with its
	 * character's name and a live-computed `approval_level`, which is never
	 * stored and is instead recalculated on every request.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_queue( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$pagination = $this->get_pagination( $request );
		$args       = [
			// Defaults to pending changes; can be overridden to view past ones.
			'status'       => $request->get_param( 'status' ) ?: 'pending',
			'change_type'  => $request->get_param( 'change_type' ),
			'character_id' => $request->get_param( 'character_id' ),
			'order'        => $request->get_param( 'order' ) ?: 'DESC',
			'per_page'     => $pagination['per_page'],
			'offset'       => $pagination['offset'],
		];

		$items = Change::for_game( $request['game_slug'], $args );

		$characters = [];
		foreach ( $items as $item ) {
			$id = (int) $item->character_id;
			if ( ! isset( $characters[ $id ] ) ) {
				$characters[ $id ] = Character::find( $id );
			}
			$character = $characters[ $id ];
			$item->character_name  = $character->name ?? null;
			$resolved             = $character
				? Change_Engine::resolve_approval_level( $character, (object) [
					'change_type' => $item->change_type,
					'change_data' => $item->change_data,
				] )
				: [ 'level' => 'st', 'reason' => null ];
			$item->approval_level = $resolved['level'];
		}

		$total    = Change::count_for_game( $request['game_slug'], $args );
		$response = $this->success( $items );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * Lists the current user's own pending changes across all of their
	 * characters in this game.
	 *
	 * Unpaginated, since this is bounded to one user's own characters rather
	 * than the whole game. Enriches each row with `character_name` and a
	 * live-computed `approval_level`, the same shape `get_queue()` returns.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_my_changes( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$items = Change::for_game( $request['game_slug'], [
			'status'     => 'pending',
			'wp_user_id' => get_current_user_id(),
			'order'      => 'DESC',
		] );

		$characters = [];
		foreach ( $items as $item ) {
			$id = (int) $item->character_id;
			if ( ! isset( $characters[ $id ] ) ) {
				$characters[ $id ] = Character::find( $id );
			}
			$character             = $characters[ $id ];
			$item->character_name  = $character->name ?? null;
			$resolved             = $character
				? Change_Engine::resolve_approval_level( $character, (object) [
					'change_type' => $item->change_type,
					'change_data' => $item->change_data,
				] )
				: [ 'level' => 'st', 'reason' => null ];
			$item->approval_level = $resolved['level'];
		}

		return $this->success( $items );
	}

	/**
	 * Approves several pending changes in one request.
	 *
	 * Iterates the given change IDs, skipping any that are missing or not
	 * pending, and approves the rest individually through the normal
	 * `Change_Engine::approve()` path. Returns which IDs were approved and
	 * which were skipped.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function batch_approve( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$change_ids = $request->get_param( 'change_ids' );
		if ( ! is_array( $change_ids ) || empty( $change_ids ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: change_ids.', 'beyond-elysium' ), 400 );
		}

		$approved = [];
		$skipped  = [];

		foreach ( $change_ids as $change_id ) {
			$change_id = (int) $change_id;
			$change    = Change::find( $change_id );

			if ( ! $change || $change->status !== 'pending' ) {
				$skipped[] = $change_id;
				continue;
			}

			$ok = Change_Engine::approve( $change_id, get_current_user_id(), null );
			if ( $ok ) {
				$approved[] = $change_id;

				// Notifications are enqueued per change but flushed once after the loop.
				$approved_character = Character::find( (int) $change->character_id );
				if ( $approved_character ) {
					$change->status = 'approved';
					Notifications::enqueue( $change, $approved_character, get_current_user_id() );
				}
			} else {
				$skipped[] = $change_id;
			}
		}

		Notifications::flush();

		if ( ! empty( $approved ) ) {
			Game_Stats_Controller::invalidate( $request['game_slug'] );
		}

		return $this->success(
			[
				'approved' => $approved,
				'skipped'  => $skipped,
			]
		);
	}

	/**
	 * Prices a set of proposed changes without submitting them.
	 *
	 * Runs each proposed change through `Cost_Engine` and
	 * `Change_Engine::resolve_approval_level()`, the same logic used when a
	 * change is actually submitted, and returns the XP cost and approval
	 * level for each along with a running unspent-XP total.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview_changes( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = $this->resolve_character( (int) $request['character_id'], $request['game_slug'] );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		// Same ownership rule as create_item(): only a manager or the character's own owner may preview.
		if ( ! current_user_can( 'be_manage_characters' ) ) {
			if ( (int) $character->wp_user_id !== get_current_user_id() ) {
				return $this->error( 'ownership_denied', __( 'You do not have permission to preview changes for this character.', 'beyond-elysium' ), 403 );
			}
		}

		$changes = $request->get_param( 'changes' );
		if ( ! is_array( $changes ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: changes.', 'beyond-elysium' ), 400 );
		}

		$results             = [];
		$running_xp_unspent  = (int) $character->xp_unspent;

		foreach ( $changes as $change ) {
			$change = is_array( $change ) ? $change : [];

			$cost = Cost_Engine::cost_for_change( $character, $change );
			$resolved = Change_Engine::resolve_approval_level(
				$character,
				(object) [
					'change_type' => $change['change_type'] ?? '',
					'change_data' => $change['change_data'] ?? [],
				]
			);

			$running_xp_unspent -= $cost;

			$results[] = [
				'xp_cost'         => $cost,
				'approval_level'  => $resolved['level'],
				'approval_reason' => $resolved['reason'],
			];
		}

		return $this->success(
			[
				'results'            => $results,
				'running_xp_unspent' => $running_xp_unspent,
			]
		);
	}

	/**
	 * Resolves a game by slug.
	 *
	 * Looks up the game record for the given slug and returns a 404 error
	 * when no game matches it. Every route handler in this controller calls
	 * this first to scope its work to a real, existing game.
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
	 * Resolves a character by ID, verifying it belongs to the game.
	 *
	 * Looks up the character and confirms its `owner_slug` matches the
	 * game, returning a 404 error if either check fails. Used by every
	 * route handler in this controller that operates on a single character.
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
	 * Defines the query parameters accepted by the collection endpoints.
	 *
	 * Covers status and change-type filtering, sort order, and pagination,
	 * shared by both the per-character listing and the approval queue.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return [
			'status'      => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'enum'              => [ 'pending', 'approved', 'rejected' ],
			],
			'change_type' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'character_id' => [
				'type'    => 'integer',
				'minimum' => 1,
			],
			'order'       => [
				'type'    => 'string',
				'default' => 'DESC',
				'enum'    => [ 'ASC', 'DESC' ],
			],
			'page'        => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page'    => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
		];
	}
}
