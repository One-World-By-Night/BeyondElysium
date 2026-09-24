<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Faction_Member;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Position;
use BeyondElysium\Services\Audience;
use BeyondElysium\Services\St_Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for NPC public profiles.
 */
class Npc_Profiles_Controller extends Base_Controller {

	protected $rest_base = 'npcs';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/npcs', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/npcs/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/profile', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_profile' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * Every NPC whose public profile the viewer can see.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$can_manage = Authorization::can( 'be_manage_characters' );
		$npcs       = Character::all_for_game( $request['game_slug'], [ 'is_npc' => 1 ] );

		if ( ! $can_manage ) {
			$npcs = array_values( array_filter(
				$npcs,
				fn( $npc ) => Audience::can_see( self::profile_projection_entity( $npc ), 'npc', get_current_user_id(), $request['game_slug'], false )
			) );
		}

		return $this->success( array_map( fn( $npc ) => $this->public_shape( $npc, $game, $can_manage ), $npcs ) );
	}

	/**
	 * One NPC's public profile.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$npc = Character::find( (int) $request['id'] );
		if ( ! $npc || $npc->owner_slug !== $request['game_slug'] || ! $npc->is_npc ) {
			return $this->error( 'not_found', __( 'NPC not found in this game.', 'beyond-elysium' ), 404 );
		}

		$can_manage = Authorization::can( 'be_manage_characters' );
		if ( ! $can_manage && ! Audience::can_see( self::profile_projection_entity( $npc ), 'npc', get_current_user_id(), $request['game_slug'], false ) ) {
			return $this->error( 'not_found', __( 'NPC not found in this game.', 'beyond-elysium' ), 404 );
		}

		return $this->success( $this->public_shape( $npc, $game, $can_manage ) );
	}

	/**
	 * Updates one NPC's five public-profile fields. 400 not_an_npc on a PC.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_profile( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = Character::find( (int) $request['id'] );
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}
		if ( ! $character->is_npc ) {
			return $this->error( 'not_an_npc', __( 'Only an NPC has a public profile.', 'beyond-elysium' ), 400 );
		}

		$data = [];
		if ( $request->has_param( 'public_name' ) ) {
			$raw               = (string) $request->get_param( 'public_name' );
			$data['public_name'] = $raw === '' ? null : sanitize_text_field( $raw );
		}
		if ( $request->has_param( 'public_description' ) ) {
			$data['public_description'] = wp_kses_post( (string) $request->get_param( 'public_description' ) );
		}
		if ( $request->has_param( 'public_image_id' ) ) {
			$raw = $request->get_param( 'public_image_id' );
			if ( ! empty( $raw ) && get_post_type( (int) $raw ) !== 'attachment' ) {
				return $this->error( 'invalid_param', __( 'public_image_id must be a real media attachment.', 'beyond-elysium' ), 400 );
			}
			$data['public_image_id'] = ! empty( $raw ) ? (int) $raw : null;
		}
		if ( $request->has_param( 'profile_audience' ) ) {
			$audience = $request->get_param( 'profile_audience' );
			if ( ! in_array( $audience, Audience::VALUES, true ) ) {
				return $this->error( 'invalid_param', sprintf( __( 'profile_audience must be one of: %s.', 'beyond-elysium' ), implode( ', ', Audience::VALUES ) ), 400 );
			}
			$data['profile_audience'] = $audience;
		}
		if ( $request->has_param( 'profile_audience_rules' ) ) {
			$data['profile_audience_rules'] = $request->get_param( 'profile_audience_rules' );
		}

		Character::update_header( (int) $character->id, $data );

		$updated = Character::find( (int) $character->id );
		if ( ! $updated ) {
			return $this->error( 'update_failed', __( 'Failed to update profile.', 'beyond-elysium' ), 500 );
		}
		return $this->success( $this->public_shape( $updated, $game, true ) );
	}

	/**
	 * The projection Audience::can_see() and filter() read: {id, audience, audience_rules}, built from an NPC's own
	 * profile_audience and profile_audience_rules columns.
	 *
	 * @param object $npc
	 * @return object
	 */
	private static function profile_projection_entity( object $npc ): object {
		return (object) [
			'id'             => (int) $npc->id,
			'audience'       => $npc->profile_audience ?? 'storytellers',
			'audience_rules' => $npc->profile_audience_rules ?? null,
		];
	}

	/**
	 * The public projection: id, a display name, the description, an image, the titles the NPC holds and the factions it
	 * belongs to, each filtered by its own visibility rule for a non-manager.
	 *
	 * @param object $npc
	 * @param object $game
	 * @param bool   $can_manage
	 * @return array
	 */
	private function public_shape( object $npc, object $game, bool $can_manage ): array {
		$image_id = ! empty( $npc->public_image_id ) ? (int) $npc->public_image_id : ( $npc->image_id ? (int) $npc->image_id : null );

		$profile = (object) [
			'public_description' => (string) ( $npc->public_description ?? '' ),
		];
		St_Visibility::filter_npc_profile( $profile, $game, $can_manage );

		$titles = array_map(
			static fn( $p ) => (string) $p->title,
			array_filter(
				Position::for_character( (int) $npc->id ),
				static fn( $p ) => $can_manage || ! empty( $p->holder_public )
			)
		);

		$factions = array_map(
			static fn( $m ) => (string) $m->faction_name,
			array_filter(
				Faction_Member::for_character( (int) $npc->id ),
				function ( $m ) use ( $can_manage, $game ) {
					if ( ( $m->faction_status ?? 'active' ) !== 'active' ) {
						return false;
					}
					if ( $can_manage ) {
						return true;
					}
					return Audience::can_see( (object) [
						'id'             => $m->faction_id,
						'audience'       => $m->faction_audience,
						'audience_rules' => $m->faction_audience_rules,
					], 'faction', get_current_user_id(), $game->slug, false );
				}
			)
		);

		return [
			'id'                 => (int) $npc->id,
			'name'               => ! empty( $npc->public_name ) ? (string) $npc->public_name : (string) $npc->name,
			'public_description' => $profile->public_description,
			'image_url'          => $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : null,
			'titles'             => array_values( $titles ),
			'factions'           => array_values( $factions ),
		];
	}

	/**
	 * Looks up a game by its slug and returns the game object, or a WP_Error with a 404 status when no game matches.
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
