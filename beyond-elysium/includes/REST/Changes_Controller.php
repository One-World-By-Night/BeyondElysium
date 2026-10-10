<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Notifications;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Secret;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Change_Validator;
use BeyondElysium\Services\Cost_Engine;
use BeyondElysium\Services\Display\Change_Description;
use BeyondElysium\Services\St_Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for character sheet changes.
 */
class Changes_Controller extends Base_Controller {

	protected $rest_base = 'changes';

	/**
	 * Registers the game-scoped change routes.
	 */
	public function register_routes(): void {
		// The current user's own changes across every chronicle on this site where they have a character. Registered
		// before the game-scoped routes below.
		register_rest_route( $this->namespace, '/my/changes', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_my_changes_across_games' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

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

		// Submits a character's whole set of editor changes together, under one shared submission id.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<character_id>\d+)/changes/submit', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'submit_set' ],
				'permission_callback' => $this->permission( 'be_edit_own_characters' ),
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
			'status'             => $request->get_param( 'status' ),
			'change_type'        => $request->get_param( 'change_type' ),
			'exclude_staff_only' => ! \BeyondElysium\Core\Authorization::can( 'be_manage_characters' ),
			'order'              => $request->get_param( 'order' ) ?: 'DESC',
			'per_page'           => $pagination['per_page'],
			'offset'             => $pagination['offset'],
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

		$is_manager = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );
		if ( ! $is_manager && (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You do not have permission to submit changes for this character.', 'beyond-elysium' ), 403 );
		}

		if ( ! $is_manager ) {
			$refusal = $this->host_copy_edit_refusal( $character );
			if ( $refusal !== null ) {
				return $refusal;
			}
		}

		$prepared = $this->prepare_one_change(
			$character,
			$is_manager,
			$request->get_param( 'change_type' ),
			$request->get_param( 'category' ),
			$request->get_param( 'change_data' ),
			$request->get_param( 'notes' )
		);
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( ( $game->settings->approval_on_removal ?? false ) === true
			&& Change_Engine::catches_removal_rule( $character, $prepared )
		) {
			$prepared['force_level']  = 'st';
			$prepared['force_reason'] = __( 'Part of a change that removes, lowers or renames something.', 'beyond-elysium' );
		}

		$change_id = Change_Engine::submit( (int) $request['character_id'], $prepared, get_current_user_id() );

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
	 * Submits a character's whole set of pending editor changes together, each sharing one submission id, in one
	 * transaction. With the chronicle's removal/lowering switch on, any change in the set that `catches_removal_rule()`
	 * catches makes every change in the set wait for a Storyteller, with a shared reason.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit_set( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = $this->resolve_character( (int) $request['character_id'], $request['game_slug'] );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		$is_manager = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );
		if ( ! $is_manager && (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You do not have permission to submit changes for this character.', 'beyond-elysium' ), 403 );
		}

		if ( ! $is_manager ) {
			$refusal = $this->host_copy_edit_refusal( $character );
			if ( $refusal !== null ) {
				return $refusal;
			}
		}

		$changes = $request->get_param( 'changes' );
		if ( ! is_array( $changes ) || empty( $changes ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: changes.', 'beyond-elysium' ), 400 );
		}

		$prepared = [];
		foreach ( $changes as $one ) {
			$one      = (array) $one;
			$result   = $this->prepare_one_change(
				$character,
				$is_manager,
				$one['change_type'] ?? null,
				$one['category'] ?? null,
				$one['change_data'] ?? null,
				$one['notes'] ?? null
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$prepared[] = $result;
		}

		$outcome = Change_Engine::submit_set( (int) $request['character_id'], $prepared, get_current_user_id() );
		if ( empty( $outcome['change_ids'] ) ) {
			return $this->error( 'submit_failed', __( 'Failed to submit the change set. Nothing was changed.', 'beyond-elysium' ), 500 );
		}

		$results = array_map( static fn( $id ) => Change::find( $id ), $outcome['change_ids'] );
		$results = array_values( array_filter( $results ) );
		$this->redact_changes( $results, $game );

		return $this->success( [ 'submission_id' => $outcome['submission_id'], 'changes' => $results ], 201 );
	}

	/**
	 * The blocks a character may be changed in beyond its creature type's own sections, for a creature type that allows
	 * any block: every block it already holds, plus - for a Storyteller - each block a change names.
	 *
	 * @param object            $character
	 * @param bool              $is_manager
	 * @param array<int,mixed>  $requested   Block slugs the submitted changes name.
	 * @return string[]
	 */
	private function blocks_in_play( $character, bool $is_manager, array $requested ): array {
		$held = is_array( $character->sheet_data ) ? array_map( 'strval', array_keys( $character->sheet_data ) ) : [];
		if ( ! $is_manager ) {
			return $held;
		}
		$named = array_filter( $requested, static fn( $slug ): bool => is_string( $slug ) && $slug !== '' );
		return array_values( array_unique( array_merge( $held, $named ) ) );
	}

	/**
	 * Validates and server-prices one proposed change against a character's own sections, returning the shape
	 * `Change_Engine::submit()` takes, or a `WP_Error` naming what's wrong.
	 *
	 * @param object      $character
	 * @param bool        $is_manager
	 * @param mixed       $change_type
	 * @param mixed       $category
	 * @param mixed       $change_data
	 * @param mixed       $notes
	 * @return array{change_type:string,category:string,change_data:array<string,mixed>,xp_cost:int,notes:?string}|\WP_Error
	 */
	private function prepare_one_change( $character, bool $is_manager, $change_type, $category, $change_data, $notes ) {
		if ( empty( $change_type ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: change_type.', 'beyond-elysium' ), 400 );
		}
		if ( empty( $category ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: category.', 'beyond-elysium' ), 400 );
		}
		if ( empty( $change_data ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: change_data.', 'beyond-elysium' ), 400 );
		}

		$stack      = Creature_Stack::resolve(
			(string) $character->stack_slug,
			(string) $character->owner_slug,
			$this->blocks_in_play( $character, $is_manager, [ is_array( $change_data ) ? ( $change_data['block_slug'] ?? null ) : null ] )
		);
		$validation = Change_Validator::validate(
			[ 'change_type' => $change_type, 'change_data' => $change_data ],
			$stack['blocks'] ?? [],
			is_array( $character->sheet_data ) ? $character->sheet_data : [],
			$is_manager,
			Change_Validator::protected_fields( $stack['stack'] ?? null ),
			Creature_Stack::closed_blocks( $stack['stack'] ?? null )
		);
		if ( ! $validation['ok'] ) {
			return $this->validation_error( $validation );
		}
		$change_data = $validation['change_data'];

		$quote   = Cost_Engine::quote_for_change(
			$character,
			[ 'change_type' => $change_type, 'change_data' => $change_data ],
			$is_manager
		);
		$xp_cost = $quote['xp'];
		if ( ! $quote['priced'] ) {
			$change_data['cost_pending'] = true;
		}
		if ( ! $is_manager && $xp_cost > (int) $character->xp_unspent ) {
			return $this->error(
				'insufficient_xp',
				sprintf( __( 'This costs %d XP; only %d is unspent.', 'beyond-elysium' ), $xp_cost, (int) $character->xp_unspent ),
				400
			);
		}

		return [
			'change_type' => $change_type,
			'category'    => $category,
			'change_data' => $change_data,
			'xp_cost'     => $xp_cost,
			'notes'       => $notes,
		];
	}

	/**
	 * Approves or rejects a single change.
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

		// Only a pending change may be reviewed.
		if ( $change->status !== 'pending' ) {
			return $this->error( 'change_not_pending', __( 'This change has already been reviewed.', 'beyond-elysium' ), 400 );
		}

		// The token the queue issued for exactly what the reviewer saw.
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
			$faction_denied = $this->faction_capability_denied( $change );
			if ( $faction_denied ) {
				return $faction_denied;
			}
			$secret_denied = $this->secret_capability_denied( $change );
			if ( $secret_denied ) {
				return $secret_denied;
			}
			// A purchase waiting for a price is approved at the one the reviewer names, and only then.
			$set_cost = null;
			if ( ! empty( $change->change_data['cost_pending'] ) ) {
				$set_cost = $this->reviewer_price( $request->get_param( 'xp_cost' ) );
				if ( is_wp_error( $set_cost ) ) {
					return $set_cost;
				}
			}
			// A logged knowledge claim is approved only once the reviewer names which secret it is.
			$secret_choice = null;
			if ( $change->change_type === 'log_knowledge' ) {
				$secret_choice = $this->resolve_secret_choice( $request, (int) $game->id );
				if ( is_wp_error( $secret_choice ) ) {
					return $secret_choice;
				}
			}
			$result = Change_Engine::approve( (int) $request['id'], get_current_user_id(), $notes, $token, $set_cost, $secret_choice );
		} else {
			$result = Change_Engine::reject( (int) $request['id'], get_current_user_id(), $notes, $token );
		}

		if ( ! $result ) {
			// The engine re-checks under a row lock.
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

		$reviewed_character = Character::find( (int) $change->character_id );
		if ( $updated && $reviewed_character ) {
			Notifications::enqueue( $updated, $reviewed_character, get_current_user_id() );
		}
		Notifications::flush();

		// Invalidates the cached game stats.
		Game_Stats_Controller::invalidate( $request['game_slug'] );

		return $this->success( $updated );
	}

	/**
	 * Lists the approval queue: pending, or otherwise filtered, changes across every character in the game, each with its
	 * character's name and a live-computed `approval_level`.
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
	 * Adds what a reviewer sees to each change: its review token, its character's name, its submitter's display name, and
	 * its live-computed approval level.
	 *
	 * @param object[] $items
	 * @return object[]
	 */
	private function with_review_fields( array $items ): array {
		$characters = [];
		$submitters = [];
		$visits     = [];
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
			$item->cost_units     = $this->cost_units( $character, $item );

			$submitter_id = (int) $item->submitted_by;
			if ( ! array_key_exists( $submitter_id, $submitters ) ) {
				$user                        = get_userdata( $submitter_id );
				$submitters[ $submitter_id ] = $user ? $user->display_name : null;
			}
			$item->submitted_by_name = $submitters[ $submitter_id ];

			$item->host_chronicle = null;
			if ( ! empty( $item->source_visit_id ) ) {
				$visit_id = (int) $item->source_visit_id;
				if ( ! array_key_exists( $visit_id, $visits ) ) {
					$visits[ $visit_id ] = Transfer::find( $visit_id );
				}
				$item->host_chronicle = $visits[ $visit_id ]->host_chronicle ?? null;
			}
		}
		return $items;
	}

	/**
	 * What a Storyteller's price would cover for a purchase waiting for one.
	 *
	 * @param object|null $character
	 * @param object      $item      A change row.
	 * @return array{per:string,units:int,negative:bool}|null
	 */
	private function cost_units( $character, object $item ): ?array {
		$data = is_array( $item->change_data ) ? $item->change_data : [];
		if ( ! $character || empty( $data['cost_pending'] ) ) {
			return null;
		}
		$block_slug = (string) ( $data['block_slug'] ?? '' );
		$block      = Schema_Block::find_for_game( $block_slug, (string) $character->owner_slug );
		if ( ! $block || ! is_object( $block->definition ) ) {
			return null;
		}
		$sheet = is_array( $character->sheet_data ) ? $character->sheet_data : [];
		return Cost_Engine::price_units( $sheet, $block->definition, $block_slug, (string) $item->change_type, $data )
			+ [ 'negative' => ! empty( $block->definition->negative ) ];
	}

	/**
	 * Lists the current user's own pending changes across all of their characters in this game.
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
			'status'             => 'pending',
			'wp_user_id'         => get_current_user_id(),
			'exclude_staff_only' => ! \BeyondElysium\Core\Authorization::can( 'be_manage_characters' ),
			'order'              => 'DESC',
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
	 * Lists the current user's own changes across every chronicle on this site where they have a character: pending,
	 * plus anything reviewed in the last 30 days, newest submission first.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_my_changes_across_games( $request ) {
		$items = array_values( array_filter( Change::for_player_across_games( get_current_user_id() ) ) );

		$games = [];
		foreach ( $items as $item ) {
			$slug = (string) $item->game_slug;
			if ( ! array_key_exists( $slug, $games ) ) {
				$games[ $slug ] = Game::find_by_slug( $slug );
			}
			$game = $games[ $slug ];
			if ( $game ) {
				// A player's own view of their own changes - always redacted, regardless of any other role held.
				St_Visibility::filter_change( $item, $game, false );
			}

			$item->description = Change_Description::describe(
				(string) $item->change_type,
				is_array( $item->change_data ) ? $item->change_data : []
			);
			$item->display_status = $this->my_changes_status( $item );
		}

		return $this->success( $items );
	}

	/**
	 * One of `pending`, `approved`, `auto_approved`, `refused`, for My changes across chronicles.
	 *
	 * @param object $item
	 * @return string
	 */
	private function my_changes_status( object $item ): string {
		if ( $item->status === 'pending' ) {
			return 'pending';
		}
		if ( $item->status === 'rejected' ) {
			return 'refused';
		}
		return ! empty( $item->auto_approved ) ? 'auto_approved' : 'approved';
	}

	/**
	 * Denies approving a change that writes the chronicle's catalog when the reviewer lacks the catalog capability.
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
	 * The same shape as `catalog_capability_denied()`, for a `propose_faction` change: approving it writes a new
	 * `be_factions` row.
	 *
	 * @param object $change
	 * @return \WP_Error|null Null when approval may proceed.
	 */
	private function faction_capability_denied( $change ) {
		if ( ( $change->change_type ?? '' ) !== 'propose_faction' ) {
			return null;
		}
		if ( \BeyondElysium\Core\Authorization::can( 'be_manage_factions' ) ) {
			return null;
		}

		return $this->error(
			'faction_capability_denied',
			__( 'Approving this creates a faction, which needs faction management rights. You can still reject it.', 'beyond-elysium' ),
			403
		);
	}

	/**
	 * The same shape as `catalog_capability_denied()`, for a `log_knowledge` or `pass_secret` change: approving either
	 * writes or attaches a `be_secrets`/`be_secret_reveals` row, which needs secret-management rights.
	 *
	 * @param object $change
	 * @return \WP_Error|null Null when approval may proceed.
	 */
	private function secret_capability_denied( $change ) {
		if ( ! in_array( $change->change_type ?? '', [ 'log_knowledge', 'pass_secret' ], true ) ) {
			return null;
		}
		if ( \BeyondElysium\Core\Authorization::can( 'be_manage_plots' ) ) {
			return null;
		}

		return $this->error(
			'secret_capability_denied',
			__( 'Approving this writes or attaches a secret, which needs secret management rights. You can still reject it.', 'beyond-elysium' ),
			403
		);
	}

	/**
	 * Reads the reviewer's choice of which secret a `log_knowledge` change belongs to: an existing secret's id, or a
	 * new one's title/content, optionally naming a real entity. Confirms a named entity is real in this game.
	 *
	 * @param \WP_REST_Request $request
	 * @param int              $game_id
	 * @return array{secret_id:int}|array{entity_type:?string,entity_id:?int,title:?string,content:?string}|\WP_Error
	 */
	private function resolve_secret_choice( $request, int $game_id ) {
		$secret_id = $request->get_param( 'secret_id' );
		if ( $secret_id ) {
			$secret = Secret::find( (int) $secret_id );
			if ( ! $secret || (int) $secret->game_id !== $game_id ) {
				return $this->error( 'invalid_param', __( 'secret_id must be a real secret in this game.', 'beyond-elysium' ), 400 );
			}
			return [ 'secret_id' => (int) $secret_id ];
		}

		$entity_type = $request->get_param( 'entity_type' );
		$entity_id   = $request->get_param( 'entity_id' );
		if ( $entity_type && $entity_id ) {
			if ( Secrets_Controller::resolve_entity( (string) $entity_type, (int) $entity_id, $game_id ) === null ) {
				return $this->error( 'invalid_param', __( 'entity_type and entity_id must name a real entity in this game.', 'beyond-elysium' ), 400 );
			}
			return [
				'entity_type' => (string) $entity_type,
				'entity_id'   => (int) $entity_id,
				'title'       => $request->get_param( 'title' ) ? sanitize_text_field( (string) $request->get_param( 'title' ) ) : null,
				'content'     => $request->get_param( 'content' ) ? wp_kses_post( (string) $request->get_param( 'content' ) ) : null,
			];
		}

		$title = $request->get_param( 'title' );
		if ( $title ) {
			return [
				'entity_type' => null,
				'entity_id'   => null,
				'title'       => sanitize_text_field( (string) $title ),
				'content'     => $request->get_param( 'content' ) ? wp_kses_post( (string) $request->get_param( 'content' ) ) : null,
			];
		}

		return $this->error(
			'invalid_param',
			__( 'Approving a logged claim needs either an existing secret\'s id, or a new one\'s title - naming an entity is optional.', 'beyond-elysium' ),
			400
		);
	}

	/**
	 * Strips `[ST]`-marked text from every change in a list for a non-manager.
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

		$approved   = [];
		$skipped    = [];
		$needs_cost = [];
		$needs_secret_choice = [];
		// Optional { change id: review token } for exactly what the reviewer saw.
		$tokens = (array) ( $request->get_param( 'review_tokens' ) ?? [] );

		// Screens every change first, same checks as a lone approval, without approving anything yet.
		$eligible = [];
		foreach ( $change_ids as $change_id ) {
			$change_id = (int) $change_id;
			$change    = $this->resolve_change( $change_id, $request['game_slug'] );

			if ( is_wp_error( $change ) || $change->status !== 'pending' ) {
				$skipped[] = $change_id;
				continue;
			}
			if ( ! empty( $change->change_data['cost_pending'] ) ) {
				$needs_cost[] = $change_id;
				continue;
			}
			// A logged claim needs the reviewer to name which secret it belongs to; a batch can't supply that.
			if ( $change->change_type === 'log_knowledge' ) {
				$needs_secret_choice[] = $change_id;
				continue;
			}
			// A catalog-writing, faction-creating, or secret-writing proposal in a batch is skipped if denied.
			if ( $this->catalog_capability_denied( $change ) || $this->faction_capability_denied( $change ) || $this->secret_capability_denied( $change ) ) {
				$skipped[] = $change_id;
				continue;
			}
			$eligible[ $change_id ] = $change;
		}

		// Two or more eligible changes sharing a submission id, in this same batch, approve together, refunds first, all
		// or nothing. A lone member of a submission in this batch, or one with none, approves on its own below.
		$groups = [];
		foreach ( $eligible as $change_id => $change ) {
			$submission_id = $change->submission_id ?? null;
			if ( $submission_id ) {
				$groups[ $submission_id ][] = $change_id;
			}
		}

		$grouped_ids = [];
		foreach ( $groups as $submission_id => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			$grouped_ids  = array_merge( $grouped_ids, $ids );
			$group_tokens = [];
			foreach ( $ids as $id ) {
				if ( isset( $tokens[ (string) $id ] ) ) {
					$group_tokens[ $id ] = (string) $tokens[ (string) $id ];
				}
			}
			$ok = Change_Engine::approve_group( $ids, get_current_user_id(), $group_tokens );
			foreach ( $ids as $id ) {
				if ( $ok ) {
					$approved[] = $id;
					$approved_character = Character::find( (int) $eligible[ $id ]->character_id );
					if ( $approved_character ) {
						$eligible[ $id ]->status = 'approved';
						Notifications::enqueue( $eligible[ $id ], $approved_character, get_current_user_id() );
					}
				} else {
					$skipped[] = $id;
				}
			}
		}

		foreach ( $eligible as $change_id => $change ) {
			if ( in_array( $change_id, $grouped_ids, true ) ) {
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
				'approved'            => $approved,
				'skipped'             => $skipped,
				'needs_cost'          => $needs_cost,
				'needs_secret_choice' => $needs_secret_choice,
			]
		);
	}

	/**
	 * Prices a set of proposed changes without submitting them.
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
		$stack               = Creature_Stack::resolve(
			(string) $character->stack_slug,
			(string) $character->owner_slug,
			$this->blocks_in_play(
				$character,
				$is_manager,
				array_map( static fn( $change ) => is_array( $change ) && is_array( $change['change_data'] ?? null ) ? ( $change['change_data']['block_slug'] ?? null ) : null, $changes )
			)
		);
		$sheet_data          = is_array( $character->sheet_data ) ? $character->sheet_data : [];
		$protected_fields    = Change_Validator::protected_fields( $stack['stack'] ?? null );
		$closed_blocks       = Creature_Stack::closed_blocks( $stack['stack'] ?? null );

		foreach ( $changes as $change ) {
			$change = is_array( $change ) ? $change : [];

			// Previewed through the same check as a submission.
			$validation = Change_Validator::validate( $change, $stack['blocks'] ?? [], $sheet_data, $is_manager, $protected_fields, $closed_blocks );
			if ( ! $validation['ok'] ) {
				$error     = $this->validation_error( $validation );
				$results[] = [
					'xp_cost'         => 0,
					'priced'          => true,
					'unpriced_reason' => null,
					'approval_level'  => null,
					'approval_reason' => null,
					'error'           => [ 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ],
					'invalid'         => true,
				];
				continue;
			}
			$change['change_data'] = $validation['change_data'];

			// `priced` false is a purchase no price exists for yet: the player is told a Storyteller sets it at approval.
			$quote = Cost_Engine::quote_for_change( $character, $change, $is_manager );
			$cost  = $quote['xp'];
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
				'priced'          => $quote['priced'],
				'unpriced_reason' => $quote['unpriced_reason'],
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
	 * Turns a Change_Validator failure into a 400, translating the messages a player can meet.
	 *
	 * @param array<string,mixed> $result A failed Change_Validator::validate() result.
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
			/* translators: %s: the trait name as submitted, with its specialization in parentheses where that is part of what identifies it */
			'trait_already_held'   => __( '"%s" is already on this sheet - change the entry you hold rather than adding a second one.', 'beyond-elysium' ),
			/* translators: %s: the trait name as submitted, with its specialization in parentheses where that is part of what identifies it */
			'trait_not_held'       => __( '"%s" is not on this sheet - add it instead of changing it.', 'beyond-elysium' ),
			/* translators: 1: the power name as submitted, 2: the discipline it was submitted under */
			'unknown_power_pick'   => __( '"%1$s" is not a power of %2$s.', 'beyond-elysium' ),
			/* translators: %s: the pool name as submitted */
			'unknown_pool'         => __( '"%s" is not a pool on this sheet.', 'beyond-elysium' ),
			/* translators: %s: a resource pool, such as Glory */
			'pool_not_purchasable' => __( "%s's permanent rating is set by a Storyteller.", 'beyond-elysium' ),
			/* translators: 1: a resource pool, such as Balance, 2: its highest rating */
			'pool_above_maximum'   => __( "%1\$s can't be raised above %2\$d.", 'beyond-elysium' ),
			/* translators: %s: the field name as submitted */
			'unknown_field'        => __( '"%s" is not a field on this sheet.', 'beyond-elysium' ),
			/* translators: 1: the choice as submitted, 2: the field, such as Clan */
			'unknown_option'       => __( '"%1$s" is not a choice for %2$s.', 'beyond-elysium' ),
			/* translators: %s: an identity field, such as Clan */
			'field_required'       => __( '%s cannot be cleared - ask a Storyteller.', 'beyond-elysium' ),
			/* translators: %s: a sheet section, such as Blood Magic */
			'section_hidden'       => __( '%s is hidden in this chronicle, so nothing new can be bought in it.', 'beyond-elysium' ),
			/* translators: 1: a path, such as Path of Blood, 2: its tradition */
			'already_held'         => __( '%1$s (%2$s) is already on this sheet. Remove the other entry instead.', 'beyond-elysium' ),
		];
		$code    = (string) ( $result['code'] ?? 'invalid_param' );
		$message = isset( $formats[ $code ] ) ? vsprintf( $formats[ $code ], $result['args'] ?? [] ) : (string) ( $result['message'] ?? '' );
		return $this->error( $code, $message, 400 );
	}

	/**
	 * The price a reviewer typed for a purchase that was waiting for one: a whole number of XP from 0 to
	 * `Cost_Engine::MAX_CUSTOM_PRICE`, where 0 is a real answer.
	 *
	 * @param mixed $raw
	 * @return int|\WP_Error
	 */
	private function reviewer_price( $raw ) {
		if ( $raw === null || $raw === '' ) {
			return $this->error( 'cost_required', __( 'Enter what this purchase costs before approving it. 0 is allowed.', 'beyond-elysium' ), 400 );
		}
		if ( ! is_numeric( $raw ) || (float) $raw !== (float) (int) $raw || (int) $raw < 0 || (int) $raw > Cost_Engine::MAX_CUSTOM_PRICE ) {
			return $this->error(
				'invalid_param',
				/* translators: %d: the most XP one price may be, 500 */
				sprintf( __( 'The price must be a whole number of XP from 0 to %d.', 'beyond-elysium' ), Cost_Engine::MAX_CUSTOM_PRICE ),
				400
			);
		}
		return (int) $raw;
	}

	/** @return \WP_Error The refusal for a review whose change was edited after it was shown. */
	private function change_changed_error(): \WP_Error {
		return $this->error( 'change_changed', __( 'This change was edited after you opened it. Reload the queue and review it again.', 'beyond-elysium' ), 409 );
	}

	/**
	 * Resolves a change by ID, verifying its character belongs to the game.
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
	 * @return array<string,array<string,mixed>>
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
