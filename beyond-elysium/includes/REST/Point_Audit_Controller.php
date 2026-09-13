<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Services\Point_Audit;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the point audit: `GET /{game_slug}/characters/{id}/point-audit`.
 *
 * Gated `be_manage_characters`, not `be_view_characters` and not
 * `be_edit_own_characters` (point-calculator-design.md §5.5) - a grand total
 * computed across a Storyteller-only block leaks its stored values
 * arithmetically to anyone who can difference the result against the public
 * catalog, the same class of leak `Characters_Controller::strip_storyteller_only_blocks()`
 * exists to prevent on the ordinary read path. Restricting the route, not
 * filtering inside `Point_Audit`, is the fix - an audit of only the visible
 * blocks would produce a second number that disagrees with the real one for
 * reasons a player can never see.
 *
 * Computed, not cached - `Game_Stats_Controller.php`'s one-minute transient
 * exists because it aggregates a whole chronicle; this is one character and
 * a bounded set of block queries, and a stale audit next to a fresh sheet is
 * worse than a slow one.
 *
 * @see BE_PROCESS/point-calculator-design.md §5.5
 */
class Point_Audit_Controller extends Base_Controller {

	protected $rest_base = 'point-audit';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/point-audit', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$character_id = (int) $request['id'];
		$character    = Character::find( $character_id );

		if ( $character === null || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		$report = Point_Audit::for_character( $character_id );
		if ( $report === null ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		return $this->success( $report );
	}
}
