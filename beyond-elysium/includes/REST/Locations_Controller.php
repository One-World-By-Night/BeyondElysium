<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Location_Link;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Audience;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a location's four named links - owner, domain, haven, based_at
 * (1.1.0 §3.9 item 2) - and the "who's here" roster item 4 reads from the same links.
 *
 * `GET` is `be_view_characters` (every real role) rather than `be_manage_world_objects`,
 * since "who's here" is a player-facing feature; the method itself narrows what a
 * non-manager actually sees. Writing a link is `be_manage_world_objects` only, matching
 * `World_Objects_Controller`'s own write gate for everything else about a location.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.9
 */
class Locations_Controller extends Base_Controller {

	protected $rest_base = 'locations';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/locations/(?P<id>\d+)/links', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_links' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_link' ],
				'permission_callback' => $this->permission( 'be_manage_world_objects' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/locations/(?P<id>\d+)/links/(?P<link_id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_link' ],
				'permission_callback' => $this->permission( 'be_manage_world_objects' ),
			],
		] );
	}

	/**
	 * A Storyteller sees every link on the location, each with its label and the source
	 * character's real name. A non-manager sees only "who's here" (item 4): `based_at` links
	 * whose character is an NPC and whose public profile audience (§3.7) reaches this viewer
	 * - never a plain player character's `based_at`, and never any `owner`/`domain`/`haven`
	 * link at all (the design's own "never another player's haven").
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_links( $request ) {
		$location = $this->resolve_location( $request );
		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$can_manage = Authorization::can( 'be_manage_world_objects' );
		$links      = Location_Link::for_location( (int) $location->id );

		if ( $can_manage ) {
			return $this->success( array_values( array_filter( array_map(
				fn( $link ) => $this->shape_link( $link ),
				$links
			) ) ) );
		}

		$wp_user_id = get_current_user_id();
		$game_slug  = (string) $request['game_slug'];
		$visible    = array_values( array_filter( $links, function ( $link ) use ( $wp_user_id, $game_slug ) {
			if ( $link->label !== Location_Link::BASED_AT || $link->source_type !== 'character' ) {
				return false;
			}
			$npc = Character::find( (int) $link->source_id );
			if ( ! $npc || ! $npc->is_npc ) {
				return false;
			}
			$projection = (object) [
				'id'             => (int) $npc->id,
				'audience'       => $npc->profile_audience ?? 'storytellers',
				'audience_rules' => $npc->profile_audience_rules ?? null,
			];
			return Audience::can_see( $projection, 'npc', $wp_user_id, $game_slug, false );
		} ) );

		return $this->success( array_values( array_filter( array_map(
			fn( $link ) => $this->shape_link( $link ),
			$visible
		) ) ) );
	}

	/**
	 * Resolves one connection row into the Links panel's own display shape - id, label, the
	 * source character's id, and a name (the NPC's public name when it has one, matching
	 * `Npc_Profiles_Controller::public_shape()`'s identical fallback, otherwise its real
	 * name). Null when the source character no longer exists (a stale link left behind by a
	 * deleted character - shown nowhere rather than fatally erroring the whole list).
	 *
	 * @param object $link
	 * @return array{id:int,label:string,source_type:string,source_id:int,name:string}|null
	 */
	private function shape_link( object $link ): ?array {
		if ( $link->source_type !== 'character' ) {
			return null;
		}
		$character = Character::find( (int) $link->source_id );
		if ( ! $character ) {
			return null;
		}
		return [
			'id'          => (int) $link->id,
			'label'       => (string) $link->label,
			'source_type' => 'character',
			'source_id'   => (int) $character->id,
			'name'        => ! empty( $character->public_name ) ? (string) $character->public_name : (string) $character->name,
		];
	}

	/**
	 * Creates a location link. 400 `invalid_param` on an unrecognized label or a source that
	 * isn't a real character in this game.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_link( $request ) {
		$location = $this->resolve_location( $request );
		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$label = (string) $request->get_param( 'label' );
		if ( ! in_array( $label, Location_Link::LABELS, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'label must be one of: %s.', 'beyond-elysium' ), implode( ', ', Location_Link::LABELS ) ), 400 );
		}

		$source_type = (string) ( $request->get_param( 'source_type' ) ?: 'character' );
		$source_id   = (int) $request->get_param( 'source_id' );
		if ( ! $source_id || $source_type !== 'character' || ! Character::find( $source_id ) ) {
			return $this->error( 'invalid_param', __( 'source_id must be a real character in this game.', 'beyond-elysium' ), 400 );
		}

		$id = Location_Link::create( (int) $location->game_id, $label, $source_type, $source_id, (int) $location->id, get_current_user_id() );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create this link.', 'beyond-elysium' ), 500 );
		}

		return $this->success( $this->shape_link( (object) [
			'id' => $id, 'label' => $label, 'source_type' => $source_type, 'source_id' => $source_id,
		] ), 201 );
	}

	/**
	 * Removes a location link.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_link( $request ) {
		$location = $this->resolve_location( $request );
		if ( is_wp_error( $location ) ) {
			return $location;
		}

		if ( ! Location_Link::delete( (int) $request['link_id'], (int) $location->id ) ) {
			return $this->error( 'not_found', __( 'Link not found on this location.', 'beyond-elysium' ), 404 );
		}

		return $this->success( null, 204 );
	}

	/**
	 * Looks up a location by id, confirming it belongs to the URL's game and is really a
	 * location (never an item, rote, or boon).
	 *
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function resolve_location( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$location = World_Object::find( (int) $request['id'] );
		if ( ! $location || (int) $location->game_id !== (int) $game->id || $location->object_type !== 'location' ) {
			return $this->error( 'not_found', __( 'Location not found in this game.', 'beyond-elysium' ), 404 );
		}
		return $location;
	}

	/**
	 * Looks up a game by its slug and returns the game object, or a WP_Error with a 404
	 * status when no game matches.
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
