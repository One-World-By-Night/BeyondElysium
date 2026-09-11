<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for chronicle membership.
 *
 * Manages which WordPress users hold which chronicle-level role in a game:
 * listing current members with their display name and email, adding a
 * member or changing an existing member's role, and removing a member.
 * Every route is gated by `be_manage_games`.
 */
class Game_Members_Controller extends Base_Controller {

	protected $rest_base = 'members';

	/**
	 * Registers the game membership routes.
	 *
	 * Adds the collection route for listing members and adding/changing one,
	 * plus a single-member route for removing one by WordPress user ID.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/(?P<wp_user_id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );
	}

	/**
	 * Lists the members of a game.
	 *
	 * Loads every membership row for the game and enriches each with the
	 * corresponding WordPress user's display name and email, so a caller
	 * does not need a separate lookup to show a human-readable list.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$members = Game_Member::for_game( (int) $game->id );

		// Enriches each member row with a display name and email.
		$enriched = array_map(
			static function ( $member ) {
				$user               = get_userdata( (int) $member->wp_user_id );
				$member->name       = $user ? $user->display_name : null;
				$member->user_email = $user ? $user->user_email : null;
				return $member;
			},
			$members
		);

		return $this->success( $enriched );
	}

	/**
	 * Adds a member or changes an existing member's role.
	 *
	 * Validates that `wp_user_id` references a real WordPress user and that
	 * `role` is one of the valid roles, then upserts the membership row
	 * through `Game_Member::set_role()`, which covers both adding a new
	 * member and changing an existing one's role.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$wp_user_id = (int) $request->get_param( 'wp_user_id' );
		$role       = (string) $request->get_param( 'role' );

		if ( ! $wp_user_id || ! get_userdata( $wp_user_id ) ) {
			return $this->error( 'invalid_param', __( 'wp_user_id must reference a real WordPress user.', 'beyond-elysium' ), 400 );
		}
		if ( ! in_array( $role, Game_Member::VALID_ROLES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'role must be one of: %s.', 'beyond-elysium' ), implode( ', ', Game_Member::VALID_ROLES ) ), 400 );
		}

		if ( ! Game_Member::set_role( (int) $game->id, $wp_user_id, $role ) ) {
			return $this->error( 'update_failed', __( 'Failed to set membership role.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Game_Member::find( (int) $game->id, $wp_user_id ), 201 );
	}

	/**
	 * Removes a member from a game.
	 *
	 * Resolves the game by slug, then removes the membership row for the
	 * WordPress user ID named in the URL.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		Game_Member::remove( (int) $game->id, (int) $request['wp_user_id'] );
		return $this->success( null, 204 );
	}

	/**
	 * Resolves a game by its slug.
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
}
