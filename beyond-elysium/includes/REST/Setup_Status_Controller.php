<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Setup_Status;

defined( 'ABSPATH' ) || exit;

/**
 * The Chronicle Setup checklist's status endpoint (GS-4,
 * guided-chronicle-setup-design.md §6.3-6.4): `GET /{game_slug}/setup-status`.
 *
 * The rows themselves, and what makes each one `ok`, `attention` or `info`, are
 * `Services\Setup_Status`'s. This adds what only a request can know: whether the viewer can act
 * on each row.
 *
 * Two deliberate differences from `Game_Stats_Controller`, the precedent this shape otherwise
 * follows exactly:
 *
 *  1. No transient. A checklist that shows "no Storytellers assigned" sixty seconds after one
 *     was assigned is worse than no checklist - every query is a bounded COUNT(*) or a
 *     single-row read, so there is nothing worth caching.
 *  2. Capability `be_manage_characters` - staff: an administrator or editor site-wide, then
 *     narrowed by chronicle role to an HST or AST. `actionable` is still reported per row, so an
 *     AST reads the checklist read-only and an HST acts on the rows their own capability
 *     covers. It was `be_view_characters` until 1.3.2.2 - "the widest capability that still
 *     requires a real user" - which let any player read the chronicle's governance settings;
 *     Chronicle Setup is for staff (owner, 2026-09-23).
 *
 * @see BE_PROCESS/design/guided-chronicle-setup-design.md §6.3, §6.4
 */
class Setup_Status_Controller extends Base_Controller {

	protected $rest_base = 'setup-status';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/setup-status', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_status( $request ) {
		$game = Game::find_by_slug( $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$rows = Setup_Status::rows( $game );
		foreach ( $rows as &$row ) {
			$row['actionable'] = Authorization::can( $row['fix']['capability'] );
		}
		unset( $row );

		return $this->success( [ 'items' => $rows, 'summary' => Setup_Status::summarise( $rows ) ] );
	}
}
