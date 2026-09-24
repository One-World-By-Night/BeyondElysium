<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Core\Notifications;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Audience;
use BeyondElysium\Services\Downtime_Window;
use BeyondElysium\Services\St_Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for plot entries.
 */
class Entries_Controller extends Base_Controller {

	protected $rest_base = 'entries';

	/**
	 * Registers the entry routes.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/plots/(?P<plot_id>\d+)/entries', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission_any( [ 'be_submit_actions', 'be_manage_plots' ] ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/entries/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission_any( [ 'be_submit_actions', 'be_manage_plots' ] ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );
	}

	/**
	 * Lists entries for a plot, chronological ascending.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$plot = $this->resolve_plot( (int) $request['plot_id'], $request['game_slug'] );
		if ( is_wp_error( $plot ) ) {
			return $plot;
		}

		$can_manage = Authorization::check_request( 'be_manage_plots', $request );

		// Someone else's action allocation is not found, as it is for the plot itself.
		if ( ! $can_manage && self::is_unowned_allocation( $plot ) ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}

		$entries = Plot_Entry::for_plot( (int) $plot->id, [ 'entry_type' => $request->get_param( 'entry_type' ) ] );

		if ( ! $can_manage ) {
			$wp_user_id    = get_current_user_id();
			$game_slug     = (string) $request['game_slug'];
			$out_batch_ids = Release_Batch::out_ids( (int) $plot->game_id );
			$entries       = array_values( array_filter( $entries, static function ( $entry ) use ( $wp_user_id, $game_slug, $out_batch_ids, $plot ) {
				if ( $entry->entry_type === 'note' ) {
					return false;
				}
				return Audience::can_see_entry( $entry, $wp_user_id, $game_slug, false, $out_batch_ids, $plot );
			} ) );

			$game = Game::find_by_slug( (string) $request["game_slug"] );
			foreach ( $entries as $entry ) {
				St_Visibility::filter_entry( $entry, $game, false );
			}
		}

		return $this->success( $entries );
	}

	/**
	 * Creates an entry on a plot.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$plot = $this->resolve_plot( (int) $request['plot_id'], $request['game_slug'] );
		if ( is_wp_error( $plot ) ) {
			return $plot;
		}

		$entry_type = $request->get_param( 'entry_type' );
		if ( ! in_array( $entry_type, Plot_Entry::ENTRY_TYPES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'entry_type must be one of: %s.', 'beyond-elysium' ), implode( ', ', Plot_Entry::ENTRY_TYPES ) ), 400 );
		}
		// A rumor level text is only ever written through PUT.../rumor-levels.
		if ( $entry_type === 'rumor_level' ) {
			return $this->error( 'invalid_param', __( 'Rumor levels are set through the rumor-levels route, not created directly.', 'beyond-elysium' ), 400 );
		}

		$can_manage = Authorization::can( 'be_manage_plots' );
		if ( $entry_type === 'action' ) {
			if ( ! $can_manage && ! Authorization::can( 'be_submit_actions' ) ) {
				return $this->error( 'forbidden', __( 'You do not have permission to submit an action.', 'beyond-elysium' ), 403 );
			}
		} elseif ( ! $can_manage ) {
			// response, note, and resolution entries are ST-only regardless of other capabilities held.
			return $this->error( 'forbidden', sprintf( __( 'You do not have permission to create a %s entry.', 'beyond-elysium' ), $entry_type ), 403 );
		}

		if ( ! $can_manage && self::is_unowned_allocation( $plot ) ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}

		// Downtime windows are enforced for a non-manager's own action only.
		if ( ! $can_manage && $entry_type === 'action' ) {
			$window_error = $this->downtime_window_error( $plot );
			if ( $window_error !== null ) {
				return $window_error;
			}
		}

		$content = $request->get_param( 'content' );
		if ( empty( $content ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: content.', 'beyond-elysium' ), 400 );
		}

		if ( self::carries_apr_marker( (string) $content ) ) {
			return $this->reserved_entry_error();
		}

		$audience = $this->resolve_entry_audience( $request, $plot, $can_manage );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		$insert = [
			'plot_id'                => (int) $plot->id,
			'author_id'              => get_current_user_id(),
			'entry_type'             => $entry_type,
			'content'                => wp_kses_post( $content ),
			'event_date'             => $request->get_param( 'event_date' ) ?: null,
			'audience'               => $audience['audience'],
			'audience_character_ids' => $audience['audience_character_ids'],
		];

		// A Storyteller's response on an action plot is held by default.
		if ( $entry_type === 'response' && Action_Allocator::actor_character_id( (int) $plot->id ) !== null ) {
			$held = $request->get_param( 'held' );
			if ( $held === null || $held ) {
				$insert['held']             = true;
				$insert['release_batch_id'] = $this->resolve_answer_batch_id( $request, $plot );
			}
		}

		$id = Plot_Entry::create( $insert );

		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create entry.', 'beyond-elysium' ), 500 );
		}

		$entry = Plot_Entry::find( $id );
		if ( $entry ) {
			$this->notify_new_post( $plot, $entry, (string) $request['game_slug'] );
			Notifications::flush_posts();
		}

		return $this->success( $entry, 201 );
	}

	/**
	 * Notifies whoever a new plot post is for.
	 *
	 * @param object $plot
	 * @param object $entry
	 * @param string $game_slug
	 * @return void
	 */
	private function notify_new_post( object $plot, object $entry, string $game_slug ): void {
		if ( ! empty( $entry->held ) || in_array( $entry->entry_type, [ 'note', 'rumor_level' ], true ) ) {
			return;
		}

		$game = Game::find( (int) $plot->game_id );
		if ( ! $game ) {
			return;
		}

		$author_id  = (int) $entry->author_id;
		$plot_id    = property_exists( $plot, 'id' ) ? (int) $plot->id : 0;
		$plot_title = property_exists( $plot, 'title' ) ? (string) $plot->title : '';

		if ( $entry->entry_type === 'action' ) {
			$label = $this->action_poster_label( $plot );
			$link  = Notifications::storyteller_plot_url( $plot_id );

			if ( ! empty( $plot->assigned_to ) ) {
				$recipient_id = (int) $plot->assigned_to;
				if ( $recipient_id !== $author_id ) {
					Notifications::notify_post( $recipient_id, $game, $plot_id, $plot_title, $label, $link );
				}
				return;
			}

			foreach ( Notifications::staff_including_narrators( $game ) as $user ) {
				if ( (int) $user->ID === $author_id ) {
					continue;
				}
				Notifications::notify_post( (int) $user->ID, $game, $plot_id, $plot_title, $label, $link );
			}
			return;
		}

		$label = __( 'A Storyteller', 'beyond-elysium' );
		$link  = Notifications::player_plot_url( $plot_id );
		foreach ( Audience::connected_character_ids( $plot, 'plot' ) as $character_id ) {
			$character = Character::find( $character_id );
			$wp_user_id = $character ? (int) ( $character->wp_user_id ?? 0 ) : 0;
			if ( ! $wp_user_id || $wp_user_id === $author_id ) {
				continue;
			}
			if ( ! Audience::can_see_entry( $entry, $wp_user_id, $game_slug, false, null, $plot ) ) {
				continue;
			}
			Notifications::notify_post( $wp_user_id, $game, $plot_id, $plot_title, $label, $link );
		}
	}

	/**
	 * "Who posted" for a player's own action entry: the action-allocation plot's bound character's name, or a generic
	 * fallback when the plot has no bound character.
	 *
	 * @param object $plot
	 * @return string
	 */
	private function action_poster_label( object $plot ): string {
		$character_id = Action_Allocator::actor_character_id( (int) $plot->id );
		$character    = $character_id ? Character::find( $character_id ) : null;
		return $character ? (string) $character->name : __( 'A player', 'beyond-elysium' );
	}

	/**
	 * Validates a requested entry audience and, for `characters`, checks it against the parent plot's own visible
	 * characters; absent from the request, it returns `Plot_Entry::DEFAULT_AUDIENCE`.
	 *
	 * @param \WP_REST_Request $request
	 * @param object           $plot
	 * @param bool             $can_manage
	 * @return array{audience:string,audience_character_ids:?int[]}|\WP_Error
	 */
	private function resolve_entry_audience( $request, $plot, bool $can_manage ) {
		$audience = $request->get_param( 'audience' );
		if ( $audience === null ) {
			return [ 'audience' => Plot_Entry::DEFAULT_AUDIENCE, 'audience_character_ids' => null ];
		}
		if ( ! in_array( $audience, Plot_Entry::AUDIENCE_VALUES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'audience must be one of: %s.', 'beyond-elysium' ), implode( ', ', Plot_Entry::AUDIENCE_VALUES ) ), 400 );
		}
		// A player may direct a post no further than public or private.
		if ( ! $can_manage && $audience === Plot_Entry::AUDIENCE_CHARACTERS ) {
			return $this->error( 'forbidden', __( 'You may only choose plot or storytellers for your own entry.', 'beyond-elysium' ), 403 );
		}
		if ( $audience !== Plot_Entry::AUDIENCE_CHARACTERS ) {
			return [ 'audience' => $audience, 'audience_character_ids' => null ];
		}

		$target_ids = array_values( array_unique( array_map( 'intval', (array) $request->get_param( 'audience_character_ids' ) ) ) );
		if ( empty( $target_ids ) ) {
			return $this->error( 'invalid_param', __( 'audience_character_ids must name at least one character.', 'beyond-elysium' ), 400 );
		}
		$visible_ids = Audience::visible_character_ids( $plot, 'plot', $request['game_slug'] );
		if ( array_diff( $target_ids, $visible_ids ) ) {
			return $this->error( 'invalid_param', __( 'A directed post can only name a character who can already see this plot.', 'beyond-elysium' ), 400 );
		}

		return [ 'audience' => Plot_Entry::AUDIENCE_CHARACTERS, 'audience_character_ids' => $target_ids ];
	}

	/**
	 * Updates an entry's content.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$entry = Plot_Entry::find( (int) $request['id'] );
		if ( ! $entry ) {
			return $this->error( 'not_found', __( 'Entry not found.', 'beyond-elysium' ), 404 );
		}

		$plot = $this->resolve_plot( (int) $entry->plot_id, $request['game_slug'] );
		if ( is_wp_error( $plot ) ) {
			return $plot;
		}

		// An allocator budget row or a ledger spend is only ever edited through Apr_Controller's own routes.
		if ( self::is_apr_managed( $entry ) ) {
			return $this->error( 'apr_managed', __( 'This entry is managed by the Action & Rumor system and cannot be edited here.', 'beyond-elysium' ), 409 );
		}

		$can_manage = Authorization::can( 'be_manage_plots' );
		if ( ! $can_manage ) {
			if ( $entry->entry_type !== 'action' || (int) $entry->author_id !== get_current_user_id() ) {
				return $this->error( 'ownership_denied', __( 'You may only edit your own action entries.', 'beyond-elysium' ), 403 );
			}
			if ( $this->has_response_after( $plot, $entry ) ) {
				return $this->error( 'entry_locked', __( 'This action has already been responded to and can no longer be edited.', 'beyond-elysium' ), 409 );
			}
			$window_error = $this->downtime_window_error( $plot, true );
			if ( $window_error !== null ) {
				return $window_error;
			}
		}

		$content = $request->get_param( 'content' );
		if ( $content === null ) {
			return $this->error( 'invalid_param', __( 'Missing required field: content.', 'beyond-elysium' ), 400 );
		}

		// An ordinary entry must not be turned into a budget or ledger row after the fact either.
		if ( self::carries_apr_marker( (string) $content ) ) {
			return $this->reserved_entry_error();
		}

		$update = [ 'content' => wp_kses_post( $content ) ];
		if ( $request->get_param( 'event_date' ) !== null ) {
			$update['event_date'] = $request->get_param( 'event_date' ) ?: null;
		}
		// Left untouched entirely when not sent.
		if ( $request->get_param( 'audience' ) !== null ) {
			$audience = $this->resolve_entry_audience( $request, $plot, $can_manage );
			if ( is_wp_error( $audience ) ) {
				return $audience;
			}
			$update['audience']               = $audience['audience'];
			$update['audience_character_ids'] = $audience['audience_character_ids'];
		}

		Plot_Entry::update( (int) $entry->id, $update );
		return $this->success( Plot_Entry::find( (int) $entry->id ) );
	}

	/**
	 * Deletes an entry.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$entry = Plot_Entry::find( (int) $request['id'] );
		if ( ! $entry ) {
			return $this->error( 'not_found', __( 'Entry not found.', 'beyond-elysium' ), 404 );
		}

		$plot = $this->resolve_plot( (int) $entry->plot_id, $request['game_slug'] );
		if ( is_wp_error( $plot ) ) {
			return $plot;
		}

		if ( self::is_apr_managed( $entry ) ) {
			return $this->error( 'apr_managed', __( 'This entry is managed by the Action & Rumor system and cannot be deleted here.', 'beyond-elysium' ), 409 );
		}

		Plot_Entry::delete( (int) $entry->id );
		return $this->success( null, 204 );
	}

	/**
	 * The release batch a held downtime answer joins: the request's own release_batch_id, else the plot's game date's own
	 * session default_batch_id, else null (a plain draft with no batch at all).
	 *
	 * @param \WP_REST_Request $request
	 * @param object            $plot
	 * @return int|null
	 */
	private function resolve_answer_batch_id( $request, $plot ): ?int {
		$release_batch_id = (int) $request->get_param( 'release_batch_id' );
		if ( $release_batch_id > 0 ) {
			return $release_batch_id;
		}
		if ( empty( $plot->game_date ) ) {
			return null;
		}
		$game_id = property_exists( $plot, 'game_id' ) ? (int) $plot->game_id : 0;
		$session = Game_Session::find_by_date( $game_id, (string) $plot->game_date );
		return $session && ! empty( $session->default_batch_id ) ? (int) $session->default_batch_id : null;
	}

	/**
	 * Refuses an action-entry write the character's downtime window doesn't allow.
	 *
	 * @param object $plot
	 * @param bool   $for_edit
	 * @return \WP_Error|null
	 */
	private function downtime_window_error( $plot, bool $for_edit = false ) {
		$character_id = Action_Allocator::actor_character_id( (int) $plot->id );
		if ( $character_id === null || empty( $plot->game_date ) ) {
			return null;
		}

		$game_id = property_exists( $plot, 'game_id' ) ? (int) $plot->game_id : 0;
		$state   = Downtime_Window::state( $game_id, (string) $plot->game_date, $character_id );

		if ( $for_edit ) {
			if ( $state === Downtime_Window::CLOSED ) {
				return $this->error( 'entry_locked', __( 'A Storyteller is answering this action, so it can no longer be edited.', 'beyond-elysium' ), 409 );
			}
			return null;
		}

		if ( $state === Downtime_Window::NOT_OPEN ) {
			return $this->error( 'downtime_not_open', sprintf(
				/* translators: %s: game date, Y-m-d */
				__( 'Downtime for %s has not opened yet.', 'beyond-elysium' ),
				$plot->game_date
			), 409 );
		}
		if ( $state === Downtime_Window::CLOSED ) {
			return $this->error( 'downtime_closed', sprintf(
				/* translators: %s: game date, Y-m-d */
				__( 'Downtime for %s has closed.', 'beyond-elysium' ),
				$plot->game_date
			), 409 );
		}
		return null;
	}

	/**
	 * Reports whether a plot entry is managed by the action-allocation/background-ledger system.
	 *
	 * @param object $entry
	 * @return bool
	 */
	private static function is_apr_managed( $entry ): bool {
		return $entry->entry_type === 'action' && self::carries_apr_marker( (string) $entry->content );
	}

	/**
	 * Whether an entry body carries the Action & Rumor system's own JSON marker.
	 *
	 * @param string $content
	 * @return bool
	 */
	private static function carries_apr_marker( string $content ): bool {
		$data = json_decode( $content, true );
		return is_array( $data ) && in_array( $data['source'] ?? '', [ 'allocator', 'ledger' ], true );
	}

	/**
	 * Whether a plot is an action-allocation plot belonging to a character the current user does not own AND is not an
	 * invited member of.
	 *
	 * @param object $plot
	 * @return bool
	 */
	private static function is_unowned_allocation( $plot ): bool {
		$plot_id = (int) $plot->id;
		if ( Action_Allocator::actor_character_id( $plot_id ) === null ) {
			return false;
		}
		$wp_user_id = get_current_user_id();
		return ! Action_Allocator::is_actor_owned_by( $plot_id, $wp_user_id )
			&& ! Plot::viewer_is_member( $plot_id, $wp_user_id );
	}

	/** @return \WP_Error The refusal for a body carrying the Action & Rumor marker. */
	private function reserved_entry_error(): \WP_Error {
		return $this->error( 'reserved_entry', __( 'This entry format is reserved for the Action & Rumor system.', 'beyond-elysium' ), 400 );
	}

	/**
	 * Checks whether any `response` entry exists after the given entry on its plot.
	 *
	 * @param object $plot
	 * @param object $entry
	 * @return bool
	 */
	private function has_response_after( $plot, $entry ): bool {
		foreach ( Plot_Entry::for_plot( (int) $plot->id, [ 'entry_type' => 'response' ] ) as $response ) {
			if ( strcmp( (string) $response->created_at, (string) $entry->created_at ) >= 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolves a plot by ID, verifying it belongs to the game named in the URL.
	 *
	 * @param int    $plot_id
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	private function resolve_plot( int $plot_id, string $game_slug ) {
		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$plot = Plot::find( $plot_id );
		if ( ! $plot || (int) $plot->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}

		return $plot;
	}
}
