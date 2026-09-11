<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller exposing aggregate statistics for a single game's storyteller dashboard.
 * Returns character counts grouped by stack and by status, the count of pending changes,
 * the count of active plots, and a bounded list of recent activity. Restricted to users
 * with the be_manage_characters capability.
 */
class Game_Stats_Controller extends Base_Controller {

	protected $rest_base = 'stats';

	/** How long one game's cached stats remain valid before recomputing, in seconds. */
	const CACHE_TTL = MINUTE_IN_SECONDS;

	/** How many Change rows "recent activity" shows. */
	const RECENT_ACTIVITY_LIMIT = 10;

	/**
	 * Registers the REST route for retrieving one game's storyteller dashboard
	 * statistics. Exposes a single GET endpoint scoped to the game slug and
	 * gated behind the be_manage_characters capability.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/stats', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * Returns the aggregate dashboard statistics for one game: character counts
	 * by stack and by status, the pending change count, the active plot count,
	 * and a bounded list of recent activity with character names attached.
	 * Serves the cached value when available and populates the cache otherwise.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_stats( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$cache_key = 'be_game_stats_' . $game->slug;
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $this->success( $cached );
		}

		// Each metric below is a single dedicated query; none re-derives another's result.
		$recent_activity = Change::for_game( $game->slug, [ 'per_page' => self::RECENT_ACTIVITY_LIMIT ] );

		// Attaches each recent-activity item's character name via a bounded per-row lookup.
		$characters = [];
		foreach ( $recent_activity as $item ) {
			$id = (int) $item->character_id;
			if ( ! isset( $characters[ $id ] ) ) {
				$characters[ $id ] = Character::find( $id );
			}
			$item->character_name = $characters[ $id ]->name ?? null;
		}

		$stats = [
			'characters_by_stack'  => Character::counts_by_stack_for_game( $game->slug ),
			'characters_by_status' => Character::counts_by_status_for_game( $game->slug ),
			'pending_changes'      => Change::count_for_game( $game->slug, [ 'status' => 'pending' ] ),
			'active_plots'         => Plot::count_for_game( (int) $game->id, [ 'status' => 'active' ] ),
			'recent_activity'      => $recent_activity,
		];

		set_transient( $cache_key, $stats, self::CACHE_TTL );

		return $this->success( $stats );
	}

	/**
	 * Deletes the cached statistics transient for one game, forcing the next
	 * request to recompute and re-cache the dashboard values. Used to invalidate
	 * stale stats after a change affecting the counts has been made.
	 *
	 * @param string $game_slug
	 * @return void
	 */
	public static function invalidate( string $game_slug ): void {
		delete_transient( 'be_game_stats_' . $game_slug );
	}

	/**
	 * Looks up a game by its slug and returns the game object, or a WP_Error
	 * with a 404 status when no game matches. Used by route callbacks to
	 * resolve the game_slug URL parameter before performing further work.
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
}
