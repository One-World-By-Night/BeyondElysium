<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Saved_Query;
use BeyondElysium\Services\Query_Engine;
use BeyondElysium\Services\St_Filter;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the character query builder. Runs ad-hoc filtered
 * queries and statistics against a game's characters, and manages saved
 * queries an ST can re-run later. All endpoints require the be_run_queries
 * capability.
 */
class Query_Controller extends Base_Controller {

	protected $rest_base = 'query';

	/**
	 * Registers the REST routes for running a query, running a statistic,
	 * and listing, creating, updating, and deleting saved queries. All
	 * routes are scoped to a game slug and require be_run_queries.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/query', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'run_query' ],
				'permission_callback' => $this->permission( 'be_run_queries' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/statistics', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'run_statistics' ],
				'permission_callback' => $this->permission( 'be_run_queries' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/queries', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_run_queries' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_run_queries' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/queries/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_run_queries' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_run_queries' ),
			],
		] );
	}

	/**
	 * Runs a filtered query against a game's characters. Validates every
	 * condition against the field registry before execution and returns a
	 * 400 error naming the offending clause when a condition is invalid.
	 * Strips ST-only fields from the results before returning them.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_query( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$conditions = (array) $request->get_param( 'conditions' );
		$logic      = strtoupper( (string) ( $request->get_param( 'logic' ) ?: 'AND' ) );

		$error = Query_Engine::validate_conditions( $conditions );
		if ( $error !== null ) {
			return $this->error( 'invalid_condition', sprintf( __( 'Clause %1$d: %2$s', 'beyond-elysium' ), $error['index'], $error['message'] ), 400 );
		}

		$paging = [
			'sort'     => $request->get_param( 'sort' ),
			'page'     => $request->get_param( 'page' ),
			'per_page' => $request->get_param( 'per_page' ),
		];

		$result = Query_Engine::execute( $request['game_slug'], $conditions, $logic, $paging );

		// Strips rp_notes and other ST-only fields from each result character.
		foreach ( $result['results'] as $character ) {
			unset( $character->rp_notes );
			$character->biography = St_Filter::strip_for_game( (string) $character->biography, $game->settings ?? null );
			$character->notes     = St_Filter::strip_for_game( (string) $character->notes, $game->settings ?? null );
		}

		Saved_Query::save_recent( (int) $game->id, get_current_user_id(), (string) $request->get_param( 'inventory' ) ?: 'char', $logic === 'AND', $conditions );

		$response = $this->success( $result['results'] );
		return $this->paginate( $response, $result['total'], (int) ( $paging['per_page'] ?: 20 ), (int) ( $paging['page'] ?: 1 ) );
	}

	/**
	 * Runs a statistical aggregation (such as a distribution or average)
	 * over a game's characters matching a set of filter conditions.
	 * Validates the stat_type and, for specific_distribution, the required
	 * trait parameter, before execution.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_statistics( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$conditions = (array) $request->get_param( 'conditions' );
		$logic      = strtoupper( (string) ( $request->get_param( 'logic' ) ?: 'AND' ) );
		$key        = (string) $request->get_param( 'key' );
		$stat_type  = (string) $request->get_param( 'stat_type' );
		$trait      = $request->get_param( 'trait' );

		if ( ! in_array( $stat_type, Query_Engine::STATISTIC_TYPES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'stat_type must be one of: %s.', 'beyond-elysium' ), implode( ', ', Query_Engine::STATISTIC_TYPES ) ), 400 );
		}
		if ( $stat_type === 'specific_distribution' && empty( $trait ) ) {
			return $this->error( 'invalid_param', __( 'specific_distribution requires "trait".', 'beyond-elysium' ), 400 );
		}

		$error = Query_Engine::validate_conditions( $conditions );
		if ( $error !== null ) {
			return $this->error( 'invalid_condition', sprintf( __( 'Clause %1$d: %2$s', 'beyond-elysium' ), $error['index'], $error['message'] ), 400 );
		}

		$ok_zero = $request->get_param( 'ok_zero' );
		$result  = Query_Engine::statistics(
			$request['game_slug'],
			$conditions,
			$logic,
			$key,
			$stat_type,
			$ok_zero === null ? true : (bool) $ok_zero,
			$trait ? (string) $trait : null
		);

		return $this->success( $result );
	}

	/**
	 * Returns every saved query belonging to one game, so a storyteller can
	 * browse and re-run a previously saved set of filter conditions.
	 * Confirms the game exists before querying.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		return $this->success( Saved_Query::for_game( (int) $game->id ) );
	}

	/**
	 * Saves a new named query for a game: its conditions, match-all/
	 * match-any logic, inventory type, and sort order. Requires a name
	 * and validates the conditions against the field registry before
	 * saving.
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

		$conditions = (array) $request->get_param( 'conditions' );
		$error      = Query_Engine::validate_conditions( $conditions );
		if ( $error !== null ) {
			return $this->error( 'invalid_condition', sprintf( __( 'Clause %1$d: %2$s', 'beyond-elysium' ), $error['index'], $error['message'] ), 400 );
		}

		$id = Saved_Query::create( [
			'game_id'        => (int) $game->id,
			'name'           => sanitize_text_field( $name ),
			'inventory'      => $request->get_param( 'inventory' ) ?: 'char',
			'match_all'      => strtoupper( (string) ( $request->get_param( 'logic' ) ?: 'AND' ) ) === 'AND',
			'conditions'     => $conditions,
			'sort_key'       => $request->get_param( 'sort_key' ),
			'sort_direction' => $request->get_param( 'sort_direction' ) ?: 'asc',
			'created_by'     => get_current_user_id(),
		] );

		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to save query.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Saved_Query::find( $id ), 201 );
	}

	/**
	 * Updates a saved query's name, inventory, sort order, logic, or
	 * conditions with any recognized fields present in the request.
	 * Re-validates conditions when they are included, and treats a
	 * request with no recognized fields as a no-op rather than a failure.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$query = $this->resolve_query( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $query ) ) {
			return $query;
		}

		$data = [];
		foreach ( [ 'name', 'inventory', 'sort_key', 'sort_direction' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = $value;
			}
		}
		if ( $request->get_param( 'logic' ) !== null ) {
			$data['match_all'] = strtoupper( (string) $request->get_param( 'logic' ) ) === 'AND';
		}
		if ( $request->get_param( 'conditions' ) !== null ) {
			$conditions = (array) $request->get_param( 'conditions' );
			$error      = Query_Engine::validate_conditions( $conditions );
			if ( $error !== null ) {
				return $this->error( 'invalid_condition', sprintf( __( 'Clause %1$d: %2$s', 'beyond-elysium' ), $error['index'], $error['message'] ), 400 );
			}
			$data['conditions'] = $conditions;
		}

		// An empty $data set is treated as a no-op rather than a failure.
		if ( ! empty( $data ) && ! Saved_Query::update( (int) $query->id, $data ) ) {
			return $this->error( 'update_failed', __( 'Failed to update saved query.', 'beyond-elysium' ), 500 );
		}
		return $this->success( Saved_Query::find( (int) $query->id ) );
	}

	/**
	 * Deletes a saved query after confirming it exists and belongs to the
	 * requested game, returning a 404 error when no match is found.
	 * Responds with an empty 204 on success.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$query = $this->resolve_query( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $query ) ) {
			return $query;
		}
		Saved_Query::delete( (int) $query->id );
		return $this->success( null, 204 );
	}

	/**
	 * Looks up a saved query by id and confirms it belongs to the game
	 * identified by the given slug, returning a WP_Error with a 404
	 * status when the game or the query cannot be found.
	 *
	 * @param int    $id
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	private function resolve_query( int $id, string $game_slug ) {
		$game = $this->resolve_game( $game_slug );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$query = Saved_Query::find( $id );
		if ( ! $query || (int) $query->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Saved query not found in this game.', 'beyond-elysium' ), 404 );
		}
		return $query;
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
