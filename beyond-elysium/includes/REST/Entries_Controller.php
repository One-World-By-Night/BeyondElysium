<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for plot entries.
 *
 * An entry is one item in a plot's timeline: a player action, an ST
 * response, a resolution, or an ST-only note. Covers listing entries for a
 * plot, creating one with type-specific permission checks, updating an
 * entry's content, and deleting one.
 */
class Entries_Controller extends Base_Controller {

	protected $rest_base = 'entries';

	/**
	 * Registers the entry routes.
	 *
	 * Entries are listed and created under their parent plot, but addressed
	 * by their own ID for update and delete, since an entry never moves
	 * between plots.
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
	 * Supports filtering by entry type, and hides `note` entries from anyone
	 * without `be_manage_plots`, since notes are ST-only.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$plot = $this->resolve_plot( (int) $request['plot_id'], $request['game_slug'] );
		if ( is_wp_error( $plot ) ) {
			return $plot;
		}

		// Chronicle-scoped, not a bare current_user_can(): a site editor who is only a
		// plain player in this specific chronicle must not see its ST notes or another
		// character's allocation, even though the capability alone would pass (§3.4/§5.8).
		$can_manage = Authorization::check_request( 'be_manage_plots', $request );

		// Whether this plot is someone else's action-allocation - its entries disclose
		// exact background dot ratings and are never visible past ownership (§3.4/§5.8).
		$is_unowned_allocation = ! $can_manage
			&& Action_Allocator::actor_character_id( (int) $plot->id ) !== null
			&& ! Action_Allocator::is_actor_owned_by( (int) $plot->id, get_current_user_id() );

		$entries = $is_unowned_allocation
			? []
			: Plot_Entry::for_plot( (int) $plot->id, [ 'entry_type' => $request->get_param( 'entry_type' ) ] );

		if ( ! $can_manage ) {
			$entries = array_values( array_filter( $entries, static function ( $entry ) {
				return $entry->entry_type !== 'note';
			} ) );
		}

		return $this->success( $entries );
	}

	/**
	 * Creates an entry on a plot.
	 *
	 * Validates the entry type, then checks permission by that submitted
	 * type rather than by whichever capability let the request through the
	 * route's permission callback: a player holding only `be_submit_actions`
	 * may create an `action` entry, nothing else.
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

		$can_manage = current_user_can( 'be_manage_plots' );
		if ( $entry_type === 'action' ) {
			if ( ! $can_manage && ! Authorization::check( 'be_submit_actions' ) ) {
				return $this->error( 'forbidden', __( 'You do not have permission to submit an action.', 'beyond-elysium' ), 403 );
			}
		} elseif ( ! $can_manage ) {
			// response, note, and resolution entries are ST-only regardless of other capabilities held.
			return $this->error( 'forbidden', sprintf( __( 'You do not have permission to create a %s entry.', 'beyond-elysium' ), $entry_type ), 403 );
		}

		$content = $request->get_param( 'content' );
		if ( empty( $content ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: content.', 'beyond-elysium' ), 400 );
		}

		$id = Plot_Entry::create( [
			'plot_id'    => (int) $plot->id,
			'author_id'  => get_current_user_id(),
			'entry_type' => $entry_type,
			'content'    => wp_kses_post( $content ),
			// The entry's in-fiction date, independent of when it was actually written.
			'event_date' => $request->get_param( 'event_date' ) ?: null,
		] );

		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create entry.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Plot_Entry::find( $id ), 201 );
	}

	/**
	 * Updates an entry's content.
	 *
	 * A manager may edit any entry. A non-manager may edit only their own
	 * `action` entry, and only while the plot has no `response` entry after
	 * it; once an ST has responded, the action becomes part of the record
	 * and can no longer be changed.
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

		// An allocator budget row or a ledger spend is only ever edited through Apr_Controller's
		// own routes, which know how to preserve its JSON marker - the generic PUT here would
		// otherwise pass a plain string through wp_kses_post() and silently strip it, orphaning
		// the entry (§5.1/§BL-12).
		if ( self::is_apr_managed( $entry ) ) {
			return $this->error( 'apr_managed', __( 'This entry is managed by the Action & Rumor system and cannot be edited here.', 'beyond-elysium' ), 409 );
		}

		$can_manage = current_user_can( 'be_manage_plots' );
		if ( ! $can_manage ) {
			if ( $entry->entry_type !== 'action' || (int) $entry->author_id !== get_current_user_id() ) {
				return $this->error( 'ownership_denied', __( 'You may only edit your own action entries.', 'beyond-elysium' ), 403 );
			}
			// A 409, not a 403: the request is valid, but the current state forbids it.
			if ( $this->has_response_after( $plot, $entry ) ) {
				return $this->error( 'entry_locked', __( 'This action has already been responded to and can no longer be edited.', 'beyond-elysium' ), 409 );
			}
		}

		$content = $request->get_param( 'content' );
		if ( $content === null ) {
			return $this->error( 'invalid_param', __( 'Missing required field: content.', 'beyond-elysium' ), 400 );
		}

		$update = [ 'content' => wp_kses_post( $content ) ];
		if ( $request->get_param( 'event_date' ) !== null ) {
			$update['event_date'] = $request->get_param( 'event_date' ) ?: null;
		}

		Plot_Entry::update( (int) $entry->id, $update );
		return $this->success( Plot_Entry::find( (int) $entry->id ) );
	}

	/**
	 * Deletes an entry.
	 *
	 * Resolves the entry and its parent plot, verifying the plot belongs to
	 * the game named in the URL, then removes the entry permanently.
	 * Requires `be_manage_plots`.
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

		// An allocator budget row or a ledger spend has its own deletion rules (a ledger
		// use is always deletable via its own route; a budget row is only ever regenerated
		// by persist(), never removed directly) - the generic route enforces neither (§BL-12).
		if ( self::is_apr_managed( $entry ) ) {
			return $this->error( 'apr_managed', __( 'This entry is managed by the Action & Rumor system and cannot be deleted here.', 'beyond-elysium' ), 409 );
		}

		Plot_Entry::delete( (int) $entry->id );
		return $this->success( null, 204 );
	}

	/**
	 * Reports whether a plot entry is managed by the action-allocation/
	 * background-ledger system - marked `source: 'allocator'` or
	 * `source: 'ledger'` in its JSON content - and therefore off-limits to
	 * this controller's generic update/delete routes (§BL-12).
	 *
	 * @param object $entry
	 * @return bool
	 */
	private static function is_apr_managed( $entry ): bool {
		if ( $entry->entry_type !== 'action' ) {
			return false;
		}
		$data = json_decode( (string) $entry->content, true );
		return is_array( $data ) && in_array( $data['source'] ?? '', [ 'allocator', 'ledger' ], true );
	}

	/**
	 * Checks whether any `response` entry exists after the given entry on
	 * its plot.
	 *
	 * Compares creation timestamps against every `response` entry on the
	 * plot, returning true as soon as one is found at or after the given
	 * entry's own timestamp.
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
	 * Resolves a plot by ID, verifying it belongs to the game named in the
	 * URL.
	 *
	 * Returns a 404 error when the game does not exist, or when the plot is
	 * missing or belongs to a different game, rather than revealing that a
	 * plot with that ID exists elsewhere.
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
