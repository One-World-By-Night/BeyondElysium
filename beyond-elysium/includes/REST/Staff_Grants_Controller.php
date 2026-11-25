<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Services\Staff_Grants;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for what an HST gives an AST or a Narrator beyond the role's own bundle: who holds which of the five
 * areas in a chronicle, who gave each and when, and the changes to it.
 */
class Staff_Grants_Controller extends Base_Controller {

	protected $rest_base = 'staff-grants';

	/**
	 * Registers the staff grant routes.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_grant_staff_access' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/(?P<wp_user_id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_grant_staff_access' ),
				'args'                => [
					'areas' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'required' => true ],
				],
			],
		] );
	}

	/**
	 * Everyone holding an area in the chronicle, with each one's role here, and the last 50 grants and removals.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		return $this->success( $this->snapshot( $game ) );
	}

	/**
	 * Makes one person's areas in the chronicle exactly the ones sent.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$wp_user_id = (int) $request['wp_user_id'];
		if ( ! get_userdata( $wp_user_id ) ) {
			return $this->error( 'user_not_found', __( 'That account does not exist.', 'beyond-elysium' ), 404 );
		}

		$refusal = Staff_Grants::set( $game, $wp_user_id, (array) $request['areas'], get_current_user_id() );
		if ( null !== $refusal ) {
			return $this->refusal( $refusal );
		}

		return $this->success( [
			'person' => $this->person( $game, $wp_user_id, Staff_Grants::people( $game )[ $wp_user_id ] ?? [] ),
			'log'    => $this->log( $game ),
		] );
	}

	/**
	 * Everything the panel shows for a chronicle.
	 *
	 * @return array<string,mixed>
	 */
	private function snapshot( object $game ): array {
		$people = [];
		foreach ( Staff_Grants::people( $game ) as $wp_user_id => $grants ) {
			if ( get_userdata( $wp_user_id ) ) {
				$people[] = $this->person( $game, $wp_user_id, $grants );
			}
		}
		usort( $people, static fn( $a, $b ) => strcasecmp( $a['display_name'], $b['display_name'] ) );

		return [
			'areas'  => Staff_Grants::AREAS,
			'people' => $people,
			'log'    => $this->log( $game ),
		];
	}

	/**
	 * One person's row: their name, their role here, whether a grant counts for them now, and each area they hold.
	 *
	 * @param array<int,array{area:string,by:int,at:string}> $grants
	 * @return array<string,mixed>
	 */
	private function person( object $game, int $wp_user_id, array $grants ): array {
		$user = get_userdata( $wp_user_id );
		$role = $user ? Staff_Grants::role_here( $game, $user ) : null;

		return [
			'wp_user_id'   => $wp_user_id,
			'display_name' => $user ? (string) $user->display_name : '',
			'role'         => $role,
			'eligible'     => null !== $role && in_array( $role, [ 'ast', 'narrator' ], true ),
			'areas'        => array_map(
				fn( array $grant ): array => [
					'area'    => $grant['area'],
					'by'      => $grant['by'],
					'by_name' => $this->name_of( $grant['by'] ),
					'at'      => $grant['at'],
				],
				$grants
			),
		];
	}

	/**
	 * The recent grants and removals, each with both people's names.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function log( object $game ): array {
		return array_map(
			fn( array $event ): array => $event + [
				'user_name' => $this->name_of( $event['wp_user_id'] ),
				'by_name'   => $this->name_of( $event['by'] ),
			],
			Staff_Grants::log( $game )
		);
	}

	/**
	 * An account's display name, or null once the account is gone.
	 */
	private function name_of( int $wp_user_id ): ?string {
		$user = get_userdata( $wp_user_id );
		return $user ? (string) $user->display_name : null;
	}

	/**
	 * The error a refusal code stands for.
	 */
	private function refusal( string $code ): \WP_Error {
		switch ( $code ) {
			case Staff_Grants::REFUSAL_INVALID_AREA:
				return $this->error( $code, __( 'That is not something a member can be given.', 'beyond-elysium' ), 400 );
			case Staff_Grants::REFUSAL_NOT_ELIGIBLE:
				return $this->error( $code, __( 'Give them the AST or Narrator role first.', 'beyond-elysium' ), 400 );
			case Staff_Grants::REFUSAL_DELETE_NEEDS_AST:
				return $this->error( $code, __( 'Only an AST can be given Delete characters; a Narrator has no Characters list.', 'beyond-elysium' ), 400 );
		}
		return $this->error( $code, __( 'Could not save the change.', 'beyond-elysium' ), 500 );
	}

	/**
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
