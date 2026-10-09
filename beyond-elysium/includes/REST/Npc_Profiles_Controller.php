<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Attachment;
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

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/profiles', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_profiles' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/profile', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_profile' ],
				'permission_callback' => $this->permission( 'be_edit_own_characters' ),
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
	 * Every NPC and player-character profile the viewer can see, each carrying a kind discriminator.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_profiles( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$can_manage = Authorization::can( 'be_manage_characters' );
		$visible    = self::profile_characters( $request['game_slug'], get_current_user_id(), $can_manage );

		$profiles = array_merge(
			array_map( fn( $npc ) => $this->profile_shape( $npc, $game, $can_manage, 'npc' ), $visible['npcs'] ),
			array_map( fn( $pc ) => $this->profile_shape( $pc, $game, $can_manage, 'pc' ), $visible['pcs'] )
		);

		return $this->success( array_values( $profiles ) );
	}

	/**
	 * The active NPCs and player characters whose Who's Who profile reaches a viewer: a manager sees every active
	 * character, anyone else those the profile's audience lets them see.
	 *
	 * @param string $game_slug
	 * @param int    $viewer_id
	 * @param bool   $can_manage
	 * @return array{npcs:array<int,object>,pcs:array<int,object>}
	 */
	private static function profile_characters( string $game_slug, int $viewer_id, bool $can_manage ): array {
		$npcs = Character::all_for_game( $game_slug, [ 'is_npc' => 1, 'status' => 'active' ] );
		$pcs  = Character::all_for_game( $game_slug, [ 'is_npc' => 0, 'status' => 'active' ] );

		if ( ! $can_manage ) {
			$npcs = array_values( array_filter(
				$npcs,
				fn( $npc ) => Audience::can_see( self::profile_projection_entity( $npc ), 'npc', $viewer_id, $game_slug, false )
			) );
			$pcs = array_values( array_filter(
				$pcs,
				fn( $pc ) => Audience::can_see( self::profile_projection_entity( $pc ), 'pc', $viewer_id, $game_slug, false )
			) );
		}

		return [ 'npcs' => $npcs, 'pcs' => $pcs ];
	}

	/**
	 * The id, display name and kind of every character in a viewer's Who's Who, keyed by character id.
	 *
	 * @param string $game_slug
	 * @param int    $viewer_id
	 * @return array<int,array{id:int,name:string,kind:string}>
	 */
	public static function whos_who_people( string $game_slug, int $viewer_id ): array {
		$visible = self::profile_characters( $game_slug, $viewer_id, Authorization::can( 'be_manage_characters' ) );
		$people  = [];
		foreach ( [ 'npc' => $visible['npcs'], 'pc' => $visible['pcs'] ] as $kind => $characters ) {
			foreach ( $characters as $character ) {
				$people[ (int) $character->id ] = [
					'id'   => (int) $character->id,
					'name' => self::display_name( $character ),
					'kind' => $kind,
				];
			}
		}
		return $people;
	}

	/**
	 * The name a character goes by in Who's Who: its public name when it has one, else its own.
	 *
	 * @param object $character
	 * @return string
	 */
	public static function display_name( object $character ): string {
		return ! empty( $character->public_name ) ? (string) $character->public_name : (string) $character->name;
	}

	/**
	 * Updates a character's public-profile fields - the full set for a manager, or for a player's own non-NPC
	 * character its display name, description, show/hide and played-by choice.
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

		$can_manage = Authorization::can( 'be_manage_characters' );
		if ( ! $can_manage && (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You can only edit your own character.', 'beyond-elysium' ), 403 );
		}

		// The ownership check above already refused anyone but a manager or this character's own owning player.
		$data = [];
		if ( $request->has_param( 'public_name' ) ) {
			$raw               = (string) $request->get_param( 'public_name' );
			$data['public_name'] = $raw === '' ? null : sanitize_text_field( $raw );
		}
		if ( $request->has_param( 'public_description' ) ) {
			$data['public_description'] = wp_kses_post( (string) $request->get_param( 'public_description' ) );
		}
		if ( $request->has_param( 'public_image_id' ) ) {
			if ( ! $can_manage ) {
				return $this->error( 'image_not_allowed', __( 'Only a Storyteller can set a character\'s public portrait here.', 'beyond-elysium' ), 403 );
			}
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
			if ( ! $can_manage && ! in_array( $audience, [ 'everyone', 'storytellers' ], true ) ) {
				return $this->error( 'audience_not_allowed', __( 'You can set the profile to Show or Hidden only.', 'beyond-elysium' ), 400 );
			}
			$data['profile_audience'] = $audience;
		}
		if ( $can_manage && $request->has_param( 'profile_audience_rules' ) ) {
			$data['profile_audience_rules'] = $request->get_param( 'profile_audience_rules' );
		}
		if ( $request->has_param( 'profile_show_player' ) ) {
			$data['profile_show_player'] = $request->get_param( 'profile_show_player' ) ? 1 : 0;
		}

		Character::update_header( (int) $character->id, $data );

		$updated = Character::find( (int) $character->id );
		if ( ! $updated ) {
			return $this->error( 'update_failed', __( 'Failed to update profile.', 'beyond-elysium' ), 500 );
		}
		return $this->success( $this->public_shape( $updated, $game, true ) );
	}

	/**
	 * Whether a character's own Who's Who profile reaches a given viewer - the same rule `get_profiles()` filters by,
	 * exposed for other controllers.
	 *
	 * @param int    $character_id
	 * @param string $game_slug
	 * @param int    $viewer_id
	 * @return bool
	 */
	public static function is_in_whos_who( int $character_id, string $game_slug, int $viewer_id ): bool {
		$character = Character::find( $character_id );
		if ( ! $character || $character->owner_slug !== $game_slug || $character->status !== 'active' ) {
			return false;
		}
		if ( Authorization::can( 'be_manage_characters' ) ) {
			return true;
		}
		$kind = $character->is_npc ? 'npc' : 'pc';
		return Audience::can_see( self::profile_projection_entity( $character ), $kind, $viewer_id, $game_slug, false );
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
	 * @return array<string,mixed>
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
					if ( ! ( $m->is_public ?? true ) ) {
						return false;
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
			'name'               => self::display_name( $npc ),
			'public_description' => $profile->public_description,
			'image_url'          => $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : null,
			'titles'             => array_values( $titles ),
			'factions'           => array_values( $factions ),
		];
	}

	/**
	 * public_shape(), with a kind discriminator and the played-by name when the character has opted to show it.
	 *
	 * @param object $character
	 * @param object $game
	 * @param bool   $can_manage
	 * @param string $kind 'npc' or 'pc'.
	 * @return array<string,mixed>
	 */
	private function profile_shape( object $character, object $game, bool $can_manage, string $kind ): array {
		$shape         = $this->public_shape( $character, $game, $can_manage );
		$shape['kind'] = $kind;

		$played_by = null;
		if ( $kind === 'pc' && ! empty( $character->profile_show_player ) && ! empty( $character->wp_user_id ) ) {
			$user = get_userdata( (int) $character->wp_user_id );
			if ( $user ) {
				$played_by = $user->display_name;
			}
		}
		$shape['played_by'] = $played_by;

		$portrait = Attachment::for_entity( 'character', (int) $character->id );
		$shape['portrait_attachment_id'] = ! empty( $portrait ) ? (int) $portrait[0]->id : null;

		return $shape;
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
