<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Faction;
use BeyondElysium\Models\Faction_Member;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Position;
use BeyondElysium\Models\Position_History;
use BeyondElysium\Services\Audience;
use BeyondElysium\Services\Query_Engine;
use BeyondElysium\Services\St_Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for chronicle factions and their court positions.
 */
class Factions_Controller extends Base_Controller {

	protected $rest_base = 'factions';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/factions', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_factions' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/factions/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_factions' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_factions' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/factions/(?P<id>\d+)/members', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_members' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_member' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/factions/(?P<id>\d+)/members/candidates', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_member_candidates' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/factions/(?P<id>\d+)/members/(?P<character_id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_member' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_member' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/positions', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_positions' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_position' ],
				'permission_callback' => $this->permission( 'be_manage_factions' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/positions/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_position' ],
				'permission_callback' => $this->permission( 'be_manage_factions' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_position' ],
				'permission_callback' => $this->permission( 'be_manage_factions' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/positions/(?P<id>\d+)/history', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_position_history' ],
				'permission_callback' => $this->permission( 'be_manage_factions' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/position-presets', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_position_presets' ],
				'permission_callback' => $this->permission( 'be_manage_factions' ),
			],
		] );
	}

	// --- Factions -------------------------------------------------------------------

	/**
	 * Lists the factions a manager sees, or the ones this viewer's own characters can see per `Audience`, with goals and
	 * the member roster shown only to members and Storytellers.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$can_manage = Authorization::can( 'be_manage_factions' );
		$factions   = Faction::for_game( (int) $game->id );
		if ( ! $can_manage ) {
			$factions = array_values( Audience::filter( $factions, 'faction', get_current_user_id(), $request['game_slug'], false ) );
		}

		$my_ids = $this->my_character_ids( $request['game_slug'] );
		return $this->success( array_map(
			fn( $faction ) => $this->project_faction( $faction, $can_manage, $this->is_member( (int) $faction->id, $my_ids ), $game ),
			$factions
		) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$resolved = $this->resolve_visible_faction( $request );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ $faction, $can_manage, $is_member ] = $resolved;
		$game = $this->resolve_game( $request['game_slug'] );

		return $this->success( $this->project_faction( $faction, $can_manage, $is_member, is_wp_error( $game ) ? null : $game ) );
	}

	/**
	 * Creates a faction directly.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$name = trim( (string) $request->get_param( 'name' ) );
		if ( $name === '' ) {
			return $this->error( 'invalid_param', __( 'Missing required field: name.', 'beyond-elysium' ), 400 );
		}

		$audience = $this->resolve_audience( $request, Faction::AUDIENCE_VALUES );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		$id = Faction::create( array_merge( [
			'game_id'      => (int) $game->id,
			'parent_id'    => $request->get_param( 'parent_id' ) ? (int) $request->get_param( 'parent_id' ) : null,
			'name'         => $name,
			'faction_type' => (string) ( $request->get_param( 'faction_type' ) ?: 'other' ),
			'description'  => $request->get_param( 'description' ) ? wp_kses_post( (string) $request->get_param( 'description' ) ) : null,
			'goals'        => $request->get_param( 'goals' ) ? wp_kses_post( (string) $request->get_param( 'goals' ) ) : null,
			'created_by'   => get_current_user_id(),
		], $audience ) );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create this faction.', 'beyond-elysium' ), 500 );
		}

		$created = Faction::find( (int) $id );
		if ( ! $created ) {
			return $this->error( 'create_failed', __( 'Failed to create this faction.', 'beyond-elysium' ), 500 );
		}

		return $this->success( $this->project_faction( $created, true, true, $game ), 201 );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$faction = $this->resolve_faction( $request );
		if ( is_wp_error( $faction ) ) {
			return $faction;
		}

		$data = [];
		foreach ( [ 'name', 'faction_type', 'status' ] as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = (string) $request->get_param( $field );
			}
		}
		if ( $request->has_param( 'parent_id' ) ) {
			$data['parent_id'] = $request->get_param( 'parent_id' ) ? (int) $request->get_param( 'parent_id' ) : null;
		}
		foreach ( [ 'description', 'goals' ] as $rich ) {
			if ( $request->has_param( $rich ) ) {
				$value        = $request->get_param( $rich );
				$data[ $rich ] = $value ? wp_kses_post( (string) $value ) : null;
			}
		}

		$audience = $this->resolve_audience( $request, Faction::AUDIENCE_VALUES );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}
		$data = array_merge( $data, $audience );

		if ( ! Faction::update( (int) $faction->id, $data ) && ! empty( $data ) ) {
			return $this->error( 'update_failed', __( 'Failed to update this faction.', 'beyond-elysium' ), 500 );
		}

		$updated = Faction::find( (int) $faction->id );
		if ( ! $updated ) {
			return $this->error( 'update_failed', __( 'Failed to update this faction.', 'beyond-elysium' ), 500 );
		}

		return $this->success( $this->project_faction( $updated, true, true, null ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$faction = $this->resolve_faction( $request );
		if ( is_wp_error( $faction ) ) {
			return $faction;
		}

		Faction::delete( (int) $faction->id );
		return $this->success( null, 204 );
	}

	// --- Faction members --------------------------------------------------------------

	/**
	 * The member roster with rank and leader flags.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_members( $request ) {
		$resolved = $this->resolve_visible_faction( $request );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ $faction, $can_manage, $is_member ] = $resolved;
		if ( ! $can_manage && ! $is_member ) {
			return $this->error( 'roster_denied', __( 'Only a member or a Storyteller may see this faction\'s roster.', 'beyond-elysium' ), 403 );
		}

		return $this->success( array_map( [ $this, 'project_member' ], Faction_Member::for_faction( (int) $faction->id ) ) );
	}

	/**
	 * The member roster's own display shape.
	 *
	 * @param object $member
	 * @return array<string,mixed>
	 */
	private function project_member( object $member ): array {
		$character = Character::find( (int) $member->character_id );
		return [
			'id'              => (int) $member->id,
			'character_id'    => (int) $member->character_id,
			'character_name'  => $character->name ?? null,
			'rank'            => $member->member_rank,
			'is_leader'       => (bool) $member->is_leader,
			'created_at'      => $member->created_at,
		];
	}

	/**
	 * The name-only picker of active non-NPC characters in this chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_member_candidates( $request ) {
		$resolved = $this->resolve_visible_faction( $request );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ $faction, $can_manage ] = $resolved;
		if ( ! $can_manage && ! $this->is_leader( (int) $faction->id, $request['game_slug'] ) ) {
			return $this->error( 'ownership_denied', __( 'Only a leader or a Storyteller may invite characters to this faction.', 'beyond-elysium' ), 403 );
		}

		$member_ids = Faction_Member::character_ids_for_faction( (int) $faction->id );
		$candidates = Character::all_for_game( $request['game_slug'], [ 'status' => 'active', 'is_npc' => 0 ] );
		$candidates = array_values( array_filter(
			$candidates,
			static fn( $c ) => ! in_array( (int) $c->id, $member_ids, true )
		) );

		return $this->success( array_map(
			static fn( $c ) => [ 'id' => (int) $c->id, 'name' => $c->name ],
			$candidates
		) );
	}

	/**
	 * Adds a character to a faction.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_member( $request ) {
		$resolved = $this->resolve_visible_faction( $request );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ $faction, $can_manage ] = $resolved;
		if ( ! $can_manage && ! $this->is_leader( (int) $faction->id, $request['game_slug'] ) ) {
			return $this->error( 'ownership_denied', __( 'Only a leader or a Storyteller may invite characters to this faction.', 'beyond-elysium' ), 403 );
		}

		$character_id = (int) $request->get_param( 'character_id' );
		$character    = $character_id ? Character::find( $character_id ) : null;
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}
		if ( ! $can_manage && ( $character->status !== 'active' || (int) $character->is_npc !== 0 ) ) {
			return $this->error( 'invalid_candidate', __( 'You may only invite an active, non-NPC character.', 'beyond-elysium' ), 400 );
		}

		$id = Faction_Member::add( (int) $faction->id, $character_id, get_current_user_id() );
		if ( ! $id ) {
			return $this->error( 'already_member', __( 'This character already belongs to the faction.', 'beyond-elysium' ), 409 );
		}

		return $this->success( Faction_Member::find( (int) $id ), 201 );
	}

	/**
	 * Removes a character from a faction.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_member( $request ) {
		$resolved = $this->resolve_visible_faction( $request );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ $faction, $can_manage ] = $resolved;
		$character_id = (int) $request['character_id'];

		if ( ! $can_manage ) {
			if ( ! $this->is_leader( (int) $faction->id, $request['game_slug'] ) ) {
				return $this->error( 'ownership_denied', __( 'Only a leader or a Storyteller may remove a member.', 'beyond-elysium' ), 403 );
			}
			$target = Faction_Member::find_for( (int) $faction->id, $character_id );
			if ( $target && ! empty( $target->is_leader ) ) {
				return $this->error( 'cannot_remove_leader', __( 'A leader cannot remove themself or another leader.', 'beyond-elysium' ), 403 );
			}
		}

		if ( ! Faction_Member::remove( (int) $faction->id, $character_id ) ) {
			return $this->error( 'remove_failed', __( 'Could not remove this member - a faction needs at least one leader.', 'beyond-elysium' ), 400 );
		}

		return $this->success( null, 204 );
	}

	/**
	 * Sets a member's rank and/or leader flag.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_member( $request ) {
		$resolved = $this->resolve_visible_faction( $request );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ $faction, $can_manage ] = $resolved;
		$character_id = (int) $request['character_id'];

		$target = Faction_Member::find_for( (int) $faction->id, $character_id );
		if ( ! $target ) {
			return $this->error( 'member_not_found', __( 'This character is not a member of this faction.', 'beyond-elysium' ), 404 );
		}

		if ( ! $can_manage && ! $this->is_leader( (int) $faction->id, $request['game_slug'] ) ) {
			return $this->error( 'ownership_denied', __( 'Only a leader or a Storyteller may update a member.', 'beyond-elysium' ), 403 );
		}

		if ( $request->get_param( 'is_leader' ) !== null ) {
			if ( ! $can_manage ) {
				return $this->error( 'ownership_denied', __( 'Only a Storyteller may change a member\'s leader status.', 'beyond-elysium' ), 403 );
			}
			if ( ! Faction_Member::set_leader( (int) $faction->id, $character_id, (bool) $request->get_param( 'is_leader' ) ) ) {
				return $this->error( 'update_failed', __( 'Could not update this member - a faction needs at least one leader.', 'beyond-elysium' ), 400 );
			}
		}

		if ( $request->get_param( 'rank' ) !== null || ( is_array( $request->get_json_params() ) && array_key_exists( 'rank', $request->get_json_params() ) ) ) {
			$rank = $request->get_param( 'rank' );
			Faction_Member::set_rank( (int) $faction->id, $character_id, $rank !== null ? sanitize_text_field( (string) $rank ) : null );
		}

		$updated = Faction_Member::find_for( (int) $faction->id, $character_id );
		return $this->success( $updated ? $this->project_member( $updated ) : null );
	}

	// --- Positions ----------------------------------------------------------------

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_positions( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$can_manage  = Authorization::can( 'be_manage_factions' );
		$faction_id  = $request->get_param( 'faction_id' ) ? (int) $request->get_param( 'faction_id' ) : null;
		$positions   = Position::for_game( (int) $game->id, $faction_id );
		if ( ! $can_manage ) {
			$positions = array_values( Audience::filter( $positions, 'position', get_current_user_id(), $request['game_slug'], false ) );
		}

		return $this->success( array_map(
			fn( $position ) => $this->project_position( $position, $can_manage ),
			$positions
		) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_position( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$title = trim( (string) $request->get_param( 'title' ) );
		if ( $title === '' ) {
			return $this->error( 'invalid_param', __( 'Missing required field: title.', 'beyond-elysium' ), 400 );
		}

		$audience = $this->resolve_audience( $request, Position::AUDIENCE_VALUES );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		$id = Position::create( array_merge( [
			'game_id'       => (int) $game->id,
			'faction_id'    => $request->get_param( 'faction_id' ) ? (int) $request->get_param( 'faction_id' ) : null,
			'title'         => $title,
			'character_id'  => $request->get_param( 'character_id' ) ? (int) $request->get_param( 'character_id' ) : null,
			'holder_public' => $request->has_param( 'holder_public' ) ? (bool) $request->get_param( 'holder_public' ) : true,
			'notes'         => $request->get_param( 'notes' ) ? wp_kses_post( (string) $request->get_param( 'notes' ) ) : null,
			'created_by'    => get_current_user_id(),
		], $audience ) );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create this position.', 'beyond-elysium' ), 500 );
		}

		$created = Position::find( (int) $id );
		if ( ! $created ) {
			return $this->error( 'create_failed', __( 'Failed to create this position.', 'beyond-elysium' ), 500 );
		}

		return $this->success( $this->project_position( $created, true ), 201 );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_position( $request ) {
		$position = $this->resolve_position( $request );
		if ( is_wp_error( $position ) ) {
			return $position;
		}

		$data = [];
		if ( $request->has_param( 'title' ) ) {
			$data['title'] = (string) $request->get_param( 'title' );
		}
		if ( $request->has_param( 'faction_id' ) ) {
			$data['faction_id'] = $request->get_param( 'faction_id' ) ? (int) $request->get_param( 'faction_id' ) : null;
		}
		if ( $request->has_param( 'holder_public' ) ) {
			$data['holder_public'] = (bool) $request->get_param( 'holder_public' );
		}
		if ( $request->has_param( 'notes' ) ) {
			$value          = $request->get_param( 'notes' );
			$data['notes']  = $value ? wp_kses_post( (string) $value ) : null;
		}

		$audience = $this->resolve_audience( $request, Position::AUDIENCE_VALUES );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}
		$data = array_merge( $data, $audience );

		if ( ! empty( $data ) && ! Position::update( (int) $position->id, $data ) ) {
			return $this->error( 'update_failed', __( 'Failed to update this position.', 'beyond-elysium' ), 500 );
		}

		if ( $request->has_param( 'character_id' ) ) {
			$character_id = $request->get_param( 'character_id' ) ? (int) $request->get_param( 'character_id' ) : null;
			if ( ! Position::set_holder( (int) $position->id, $character_id ) ) {
				return $this->error( 'update_failed', __( 'Failed to change this position\'s holder.', 'beyond-elysium' ), 500 );
			}
		}

		$updated = Position::find( (int) $position->id );
		if ( ! $updated ) {
			return $this->error( 'update_failed', __( 'Failed to update this position.', 'beyond-elysium' ), 500 );
		}

		return $this->success( $this->project_position( $updated, true ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_position( $request ) {
		$position = $this->resolve_position( $request );
		if ( is_wp_error( $position ) ) {
			return $position;
		}

		Position::delete( (int) $position->id );
		return $this->success( null, 204 );
	}

	/**
	 * A position's own full holder history.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_position_history( $request ) {
		$position = $this->resolve_position( $request );
		if ( is_wp_error( $position ) ) {
			return $position;
		}

		return $this->success( array_map( static function ( $row ) {
			$character = $row->character_id ? Character::find( (int) $row->character_id ) : null;
			return [
				'id'             => (int) $row->id,
				'character_id'   => $row->character_id !== null ? (int) $row->character_id : null,
				'character_name' => $character->name ?? null,
				'started'        => $row->started,
				'ended'          => $row->ended,
			];
		}, Position_History::for_position( (int) $position->id ) ) );
	}

	/**
	 * The title preset groups.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_position_presets() {
		return $this->success( require __DIR__ . '/../Database/position-presets.php' );
	}

	// --- Shared helpers -------------------------------------------------------------

	/**
	 * Validates a requested `audience`/`audience_rules` pair against the given values.
	 *
	 * @param \WP_REST_Request $request
	 * @param string[]         $valid_audiences
	 * @return array{audience?:string,audience_rules?:?array}|\WP_Error
	 */
	private function resolve_audience( $request, array $valid_audiences ) {
		$data = [];

		$audience = $request->get_param( 'audience' );
		if ( $audience !== null ) {
			if ( ! in_array( $audience, $valid_audiences, true ) ) {
				return $this->error( 'invalid_param', sprintf( __( 'audience must be one of: %s.', 'beyond-elysium' ), implode( ', ', $valid_audiences ) ), 400 );
			}
			$data['audience'] = $audience;
		}

		if ( $request->has_param( 'audience_rules' ) ) {
			$rules = $request->get_param( 'audience_rules' );
			if ( $rules !== null ) {
				if ( ! is_array( $rules ) || empty( $rules['conditions'] ) || ! is_array( $rules['conditions'] ) ) {
					return $this->error( 'invalid_param', __( 'audience_rules must include a conditions array.', 'beyond-elysium' ), 400 );
				}
				$problem = Query_Engine::validate_conditions( $rules['conditions'] );
				if ( $problem !== null ) {
					return $this->error( 'invalid_param', $problem['message'], 400 );
				}
			}
			$data['audience_rules'] = $rules;
		}

		return $data;
	}

	/**
	 * Projects a faction row for the caller: everyone who can see it reads name, type, description, and status.
	 *
	 * @param object      $faction
	 * @param bool        $can_manage
	 * @param bool        $is_member
	 * @param object|null $game
	 * @return array
	 */
	private function project_faction( object $faction, bool $can_manage, bool $is_member, ?object $game = null ): array {
		St_Visibility::filter_faction( $faction, $game, $can_manage );

		$data = [
			'id'                   => (int) $faction->id,
			'game_id'              => (int) $faction->game_id,
			'parent_id'            => $faction->parent_id !== null ? (int) $faction->parent_id : null,
			'name'                 => $faction->name,
			'faction_type'         => $faction->faction_type,
			'description'          => $faction->description,
			'status'               => $faction->status,
			'created_via_proposal' => (bool) $faction->created_via_proposal,
			'audience'             => $faction->audience,
			'is_member'            => $is_member,
			'created_at'           => $faction->created_at,
			'updated_at'           => $faction->updated_at,
		];
		if ( $can_manage || $is_member ) {
			$data['goals'] = $faction->goals;
		}
		if ( $can_manage ) {
			$data['audience_rules'] = $faction->audience_rules;
		}
		return $data;
	}

	/**
	 * Projects a position row: everyone who can see it reads the title and whether it's held.
	 *
	 * @param object $position
	 * @param bool   $can_manage
	 * @return array
	 */
	private function project_position( object $position, bool $can_manage ): array {
		$holder_public = ! empty( $position->holder_public );
		$data          = [
			'id'         => (int) $position->id,
			'game_id'    => (int) $position->game_id,
			'faction_id' => $position->faction_id !== null ? (int) $position->faction_id : null,
			'title'      => $position->title,
			'since'      => $position->since,
			'audience'   => $position->audience,
			'held'       => $position->character_id !== null,
			'created_at' => $position->created_at,
			'updated_at' => $position->updated_at,
		];

		if ( $can_manage || $holder_public ) {
			$character           = $position->character_id ? Character::find( (int) $position->character_id ) : null;
			$data['character_id']   = $position->character_id !== null ? (int) $position->character_id : null;
			$data['character_name'] = $character->name ?? null;
		} else {
			$data['character_id']   = null;
			$data['character_name'] = null;
		}

		if ( $can_manage ) {
			$data['holder_public']  = $holder_public;
			$data['audience_rules'] = $position->audience_rules;
			$data['notes']          = $position->notes;
		}

		return $data;
	}

	/**
	 * @param string $game_slug
	 * @return int[]
	 */
	private function my_character_ids( string $game_slug ): array {
		return array_map(
			static fn( $c ) => (int) $c->id,
			Character::find_for_user( get_current_user_id(), $game_slug )
		);
	}

	/**
	 * @param int   $faction_id
	 * @param int[] $my_character_ids
	 * @return bool
	 */
	private function is_member( int $faction_id, array $my_character_ids ): bool {
		if ( empty( $my_character_ids ) ) {
			return false;
		}
		return (bool) array_intersect( $my_character_ids, Faction_Member::character_ids_for_faction( $faction_id ) );
	}

	/**
	 * @param int    $faction_id
	 * @param string $game_slug
	 * @return bool
	 */
	private function is_leader( int $faction_id, string $game_slug ): bool {
		foreach ( $this->my_character_ids( $game_slug ) as $character_id ) {
			$member = Faction_Member::find_for( $faction_id, $character_id );
			if ( $member && ! empty( $member->is_leader ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Looks up a faction by id, confirming it belongs to the URL's game.
	 *
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function resolve_faction( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$faction = Faction::find( (int) $request['id'] );
		if ( ! $faction || (int) $faction->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Faction not found in this game.', 'beyond-elysium' ), 404 );
		}
		return $faction;
	}

	/**
	 * Looks up a faction by id and resolves whether the current viewer may see it at all.
	 *
	 * @param \WP_REST_Request $request
	 * @return array{0:object,1:bool,2:bool}|\WP_Error [faction, can_manage, is_member]
	 */
	private function resolve_visible_faction( $request ) {
		$faction = $this->resolve_faction( $request );
		if ( is_wp_error( $faction ) ) {
			return $faction;
		}

		$can_manage = Authorization::can( 'be_manage_factions' );
		$my_ids     = $this->my_character_ids( $request['game_slug'] );
		$is_member  = $this->is_member( (int) $faction->id, $my_ids );

		if ( ! $can_manage && ! Audience::can_see( $faction, 'faction', get_current_user_id(), $request['game_slug'], false ) ) {
			return $this->error( 'not_found', __( 'Faction not found in this game.', 'beyond-elysium' ), 404 );
		}

		return [ $faction, $can_manage, $is_member ];
	}

	/**
	 * Looks up a position by id, confirming it belongs to the URL's game.
	 *
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function resolve_position( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$position = Position::find( (int) $request['id'] );
		if ( ! $position || (int) $position->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Position not found in this game.', 'beyond-elysium' ), 404 );
		}
		return $position;
	}

	/**
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
