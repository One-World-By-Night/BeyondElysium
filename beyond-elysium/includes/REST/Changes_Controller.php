<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Notifications;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Change_Validator;
use BeyondElysium\Services\Cost_Engine;
use BeyondElysium\Services\St_Visibility;

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
	 * by the requested sort direction. be_view_characters is a broad,
	 * site-wide/game-role capability, not per-row (same D33 class of gap
	 * Characters_Controller::get_item() already closes) - a non-manager may
	 * only list one character's own change history, never another player's.
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

		if ( ! \BeyondElysium\Core\Authorization::can( 'be_manage_characters' ) && (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You do not have permission to view this character\'s change history.', 'beyond-elysium' ), 403 );
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
		$this->redact_changes( $items, $game );
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

		$is_manager = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );

		// Ownership check: player can only submit for their own character.
		if ( ! $is_manager && (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You do not have permission to submit changes for this character.', 'beyond-elysium' ), 403 );
		}

		// Nothing below trusts the change's shape until it has been checked against this
		// character's own sections - a chronicle's forks included - and normalized
		// (1.0.0-review F-030).
		$stack      = Creature_Stack::resolve( (string) $character->stack_slug, (string) $character->owner_slug );
		$validation = Change_Validator::validate(
			[ 'change_type' => $change_type, 'change_data' => $change_data ],
			$stack['blocks'] ?? [],
			is_array( $character->sheet_data ) ? $character->sheet_data : [],
			$is_manager,
			Change_Validator::protected_fields( $stack['stack'] ?? null )
		);
		if ( ! $validation['ok'] ) {
			return $this->validation_error( $validation );
		}
		$change_data = $validation['change_data'];

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
		if ( $change ) {
			$this->redact_changes( [ $change ], $game );
		}
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

		$change = $this->resolve_change( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $change ) ) {
			return $change;
		}

		// Only a pending change may be reviewed; this guards against re-approving the same change twice.
		if ( $change->status !== 'pending' ) {
			return $this->error( 'change_not_pending', __( 'This change has already been reviewed.', 'beyond-elysium' ), 400 );
		}

		// The token the queue issued for exactly what the reviewer saw. A player's resubmission
		// rewrites a pending change in place, so a stale token means the reviewer is deciding on
		// content that is no longer there (1.0.0-review F-031).
		$token = $request->get_param( 'review_token' );
		$token = $token !== null ? (string) $token : null;
		if ( $token !== null && ! hash_equals( Change::review_token( $change ), $token ) ) {
			return $this->change_changed_error();
		}

		$notes  = $request->get_param( 'notes' );
		$result = false;

		if ( $new_status === 'approved' ) {
			$catalog_denied = $this->catalog_capability_denied( $change );
			if ( $catalog_denied ) {
				return $catalog_denied;
			}
			$result = Change_Engine::approve( (int) $request['id'], get_current_user_id(), $notes, $token );
		} else {
			$result = Change_Engine::reject( (int) $request['id'], get_current_user_id(), $notes, $token );
		}

		if ( ! $result ) {
			// The engine re-checks under a row lock, so a review or resubmission that landed after
			// the checks above is reported as what it was rather than as a server error.
			$current = Change::find( (int) $request['id'] );
			if ( $current && $current->status !== 'pending' ) {
				return $this->error( 'change_not_pending', __( 'This change has already been reviewed.', 'beyond-elysium' ), 400 );
			}
			if ( $current && $token !== null && ! hash_equals( Change::review_token( $current ), $token ) ) {
				return $this->change_changed_error();
			}
			return $this->error( 'update_failed', __( 'Failed to update change status.', 'beyond-elysium' ), 500 );
		}

		$updated = Change::find( (int) $request['id'] );

		// Sends the review notification immediately since this route only ever touches one change.
		$reviewed_character = Character::find( (int) $change->character_id );
		if ( $updated && $reviewed_character ) {
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
	 * stored and is instead recalculated on every request. Filtering by
	 * `approval_level` therefore works the level out for every matching
	 * change before cutting the page, so the page and its total count only
	 * that level (1.0.0-review F-099).
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

		$level = $request->get_param( 'approval_level' );
		if ( $level ) {
			unset( $args['per_page'], $args['offset'] );
			$matching = array_values( array_filter(
				$this->with_review_fields( Change::for_game( $request['game_slug'], $args ) ),
				static fn( $item ) => $item->approval_level === $level
			) );
			$total = count( $matching );
			$items = array_slice( $matching, $pagination['offset'], $pagination['per_page'] );
		} else {
			$items = $this->with_review_fields( Change::for_game( $request['game_slug'], $args ) );
			$total = Change::count_for_game( $request['game_slug'], $args );
		}

		$response = $this->success( $items );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * Adds what a reviewer sees to each change: its review token, its
	 * character's name, its submitter's display name, and its
	 * live-computed approval level.
	 *
	 * @param object[] $items
	 * @return object[]
	 */
	private function with_review_fields( array $items ): array {
		$characters = [];
		$submitters = [];
		foreach ( $items as $item ) {
			$item->review_token = Change::review_token( $item );
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

			// 1.0.0-review F-116: the queue showed the raw wp_user_id ("1") here. A user
			// deleted since submitting (or never valid) falls back to null, not a fatal.
			$submitter_id = (int) $item->submitted_by;
			if ( ! array_key_exists( $submitter_id, $submitters ) ) {
				$user                        = get_userdata( $submitter_id );
				$submitters[ $submitter_id ] = $user ? $user->display_name : null;
			}
			$item->submitted_by_name = $submitters[ $submitter_id ];
		}
		return $items;
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
			$item->review_token = Change::review_token( $item );
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

		$this->redact_changes( $items, $game );
		return $this->success( $items );
	}

	/**
	 * The sharp edge of 1.0.1 D3. The Approval Queue is gated on `be_manage_characters`, but
	 * approving a `propose_world_object` change *writes the chronicle's catalog*. Without this,
	 * a role holding character-approval rights but no catalog rights could create catalog
	 * entries simply by approving them.
	 *
	 * An HST and an AST hold both capabilities and are unaffected. A reviewer without the
	 * catalog capability still sees the row and can reject it - they just cannot approve it.
	 *
	 * @param object $change
	 * @return \WP_Error|null Null when approval may proceed.
	 */
	private function catalog_capability_denied( $change ) {
		if ( ( $change->change_type ?? '' ) !== 'propose_world_object' ) {
			return null;
		}
		if ( \BeyondElysium\Core\Authorization::can( 'be_manage_world_objects' ) ) {
			return null;
		}

		return $this->error(
			'catalog_permission_denied',
			__( 'Approving this adds an entry to the chronicle\'s catalog, which needs item and location rights. You can still reject it.', 'beyond-elysium' ),
			403
		);
	}

	/**
	 * Strips `[ST]`-marked text from every change in a list for a non-manager.
	 *
	 * A player reads their own change history, and two of its three free-text
	 * fields are written by a Storyteller - so this runs on every route a
	 * non-manager can reach, never on the manager-only review queue.
	 *
	 * @param array<object> $changes
	 * @param object        $game
	 */
	private function redact_changes( array $changes, object $game ): void {
		$can_manage = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );
		foreach ( $changes as $change ) {
			St_Visibility::filter_change( $change, $game, $can_manage );
		}
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
		// Optional { change id: review token } for exactly what the reviewer saw (F-031).
		$tokens = (array) ( $request->get_param( 'review_tokens' ) ?? [] );

		foreach ( $change_ids as $change_id ) {
			$change_id = (int) $change_id;
			$change    = $this->resolve_change( $change_id, $request['game_slug'] );

			if ( is_wp_error( $change ) || $change->status !== 'pending' ) {
				$skipped[] = $change_id;
				continue;
			}

			// A catalog-writing proposal in a batch is skipped, not silently approved, when the
			// reviewer lacks catalog rights - the same gate the single-change route applies.
			if ( $this->catalog_capability_denied( $change ) ) {
				$skipped[] = $change_id;
				continue;
			}

			$token = isset( $tokens[ (string) $change_id ] ) ? (string) $tokens[ (string) $change_id ] : null;
			$ok    = Change_Engine::approve( $change_id, get_current_user_id(), null, $token );
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
		if ( ! \BeyondElysium\Core\Authorization::can( 'be_manage_characters' ) ) {
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
		$is_manager          = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );
		$stack               = Creature_Stack::resolve( (string) $character->stack_slug, (string) $character->owner_slug );
		$sheet_data          = is_array( $character->sheet_data ) ? $character->sheet_data : [];
		$protected_fields    = Change_Validator::protected_fields( $stack['stack'] ?? null );

		foreach ( $changes as $change ) {
			$change = is_array( $change ) ? $change : [];

			// Previewed through the same check as a submission, so a preview never shows a
			// price for something the submit route would refuse.
			$validation = Change_Validator::validate( $change, $stack['blocks'] ?? [], $sheet_data, $is_manager, $protected_fields );
			if ( ! $validation['ok'] ) {
				$error     = $this->validation_error( $validation );
				$results[] = [
					'xp_cost'         => 0,
					'approval_level'  => null,
					'approval_reason' => null,
					'error'           => [ 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ],
				];
				continue;
			}
			$change['change_data'] = $validation['change_data'];

			$cost = Cost_Engine::cost_for_change( $character, $change );
			$resolved = Change_Engine::resolve_approval_level(
				$character,
				(object) [
					'change_type' => $change['change_type'] ?? '',
					'change_data' => $change['change_data'],
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
	 * Turns a Change_Validator failure into a 400, translating the messages a
	 * player can meet; malformed-request messages stay in English.
	 *
	 * @param array $result A failed Change_Validator::validate() result.
	 * @return \WP_Error
	 */
	private function validation_error( array $result ): \WP_Error {
		$formats = [
			'invalid_change_type'  => __( 'That kind of change cannot be submitted.', 'beyond-elysium' ),
			'unknown_block'        => __( "That section isn't part of this character's sheet.", 'beyond-elysium' ),
			'wrong_section_type'   => __( 'That change does not fit this section.', 'beyond-elysium' ),
			/* translators: %s: the trait or power name as submitted */
			'unknown_trait'        => __( '"%s" is not in this section\'s catalog.', 'beyond-elysium' ),
			/* translators: %s: the power name as submitted */
			'unknown_power'        => __( '"%s" is not in this section\'s catalog.', 'beyond-elysium' ),
			/* translators: 1: the power name as submitted, 2: the discipline it was submitted under */
			'unknown_power_pick'   => __( '"%1$s" is not a power of %2$s.', 'beyond-elysium' ),
			/* translators: %s: the pool name as submitted */
			'unknown_pool'         => __( '"%s" is not a pool on this sheet.', 'beyond-elysium' ),
			/* translators: %s: a resource pool, such as Glory */
			'pool_not_purchasable' => __( "%s's permanent rating is set by a Storyteller.", 'beyond-elysium' ),
			/* translators: %s: the field name as submitted */
			'unknown_field'        => __( '"%s" is not a field on this sheet.', 'beyond-elysium' ),
			/* translators: 1: the choice as submitted, 2: the field, such as Clan */
			'unknown_option'       => __( '"%1$s" is not a choice for %2$s.', 'beyond-elysium' ),
			/* translators: %s: an identity field, such as Clan */
			'field_required'       => __( '%s cannot be cleared - ask a Storyteller.', 'beyond-elysium' ),
		];
		$code    = (string) ( $result['code'] ?? 'invalid_param' );
		$message = isset( $formats[ $code ] ) ? vsprintf( $formats[ $code ], $result['args'] ?? [] ) : (string) ( $result['message'] ?? '' );
		return $this->error( $code, $message, 400 );
	}

	/** @return \WP_Error The refusal for a review whose change was edited after it was shown. */
	private function change_changed_error(): \WP_Error {
		return $this->error( 'change_changed', __( 'This change was edited after you opened it. Reload the queue and review it again.', 'beyond-elysium' ), 409 );
	}

	/**
	 * Resolves a change by ID, verifying its character belongs to the game.
	 *
	 * A change id is a sequential integer that says nothing about which
	 * chronicle it came from, so the character behind it is checked against
	 * the URL's chronicle before anyone can review it (1.0.0-review F-008).
	 * Returns the same 404 whether the change is missing or belongs elsewhere.
	 *
	 * @param int    $change_id
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	protected function resolve_change( int $change_id, string $game_slug ) {
		$change    = Change::find( $change_id );
		$character = $change ? Character::find( (int) $change->character_id ) : null;
		if ( ! $change || ! $character || $character->owner_slug !== $game_slug ) {
			return $this->error( 'not_found', __( 'Change not found.', 'beyond-elysium' ), 404 );
		}
		return $change;
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
			'approval_level' => [
				'type' => 'string',
				'enum' => [ 'auto', 'st' ],
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
