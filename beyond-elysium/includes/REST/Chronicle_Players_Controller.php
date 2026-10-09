<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Player_Invite;
use BeyondElysium\Services\Chronicle_Players;
use BeyondElysium\Services\Player_Invites;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a chronicle's players, for the chronicle's Storytellers: the players and their characters,
 * invites by email, linking characters to a player, and join requests. Every route answers on every chronicle;
 * `asc_role_path` tells the caller whether accessSchema is linked, for wording only.
 */
class Chronicle_Players_Controller extends Base_Controller {

	protected $rest_base = 'players';

	/**
	 * Registers the chronicle player routes.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
				'args'                => [
					'wp_user_id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
				],
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/(?P<wp_user_id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/invites', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_invites' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_invite' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
				'args'                => [
					'email'         => [ 'type' => 'string', 'required' => true ],
					'character_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'default' => [] ],
					'send_email'    => [ 'type' => 'boolean', 'default' => true ],
				],
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/invites/(?P<id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_invite' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/(?P<wp_user_id>\d+)/characters', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'link_characters' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
				'args'                => [
					'character_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'required' => true ],
				],
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/(?P<wp_user_id>\d+)/characters/(?P<character_id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'unlink_character' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/join-requests', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_join_requests' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		foreach ( [ 'approve', 'refuse' ] as $action ) {
			register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . "/join-requests/(?P<id>\\d+)/{$action}", [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, "{$action}_join_request" ],
					'permission_callback' => $this->permission( 'be_manage_characters' ),
					'args'                => $action === 'refuse' ? [ 'note' => [ 'type' => 'string' ] ] : [],
				],
			] );
		}
	}

	/**
	 * The chronicle's players, by display name, each with their linked characters, and the chronicle's accessSchema player
	 * path.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$players = [];
		foreach ( Game_Member::for_game( (int) $game->id ) as $member ) {
			if ( $member->role !== 'player' ) {
				continue;
			}
			$user      = get_userdata( (int) $member->wp_user_id );
			$players[] = [
				'wp_user_id'   => (int) $member->wp_user_id,
				'display_name' => $user ? $user->display_name : null,
				'since'        => (string) $member->created_at,
				'characters'   => self::characters_of( $game, (int) $member->wp_user_id ),
			];
		}
		usort( $players, static fn( $a, $b ) => strcasecmp( (string) $a['display_name'], (string) $b['display_name'] ) );

		return $this->success( [
			'players'        => $players,
			'asc_role_path'  => Authorization::asc_role_path( $game, 'player' ),
			'join_link'      => Chronicle_Players::join_link( $game ),
		] );
	}

	/**
	 * Makes an existing account a player in the chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$result = Chronicle_Players::add( $game, (int) $request->get_param( 'wp_user_id' ) );
		if ( $result['status'] === 'no_account' ) {
			return $this->error( 'no_account', __( 'That account does not exist. They need to sign in through OWbN once first.', 'beyond-elysium' ), 404 );
		}

		return $this->success( $result, $result['status'] === 'added' ? 201 : 200 );
	}

	/**
	 * Takes a player out of the chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$result = Chronicle_Players::remove( $game, (int) $request['wp_user_id'] );
		if ( $result['status'] === 'no_account' ) {
			return $this->error( 'no_account', __( 'That account does not exist.', 'beyond-elysium' ), 404 );
		}
		if ( $result['status'] === 'staff' ) {
			return $this->error( 'staff_member', __( 'Only players are removed here. A staff role is changed on Chronicle Access.', 'beyond-elysium' ), 409 );
		}

		return $this->success( $result );
	}

	/**
	 * The chronicle's open invites: each email, the characters waiting for it, who invited and when.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_invites( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$invites = [];
		foreach ( Player_Invite::open_for_game( (int) $game->id ) as $invite ) {
			$inviter   = (int) $invite->invited_by > 0 ? get_userdata( (int) $invite->invited_by ) : false;
			$invites[] = [
				'id'         => (int) $invite->id,
				'email'      => (string) $invite->email,
				'invited_by' => $inviter ? $inviter->display_name : null,
				'invited_at' => (string) $invite->invited_at,
				'characters' => self::named( Player_Invites::held_character_ids( $game, (string) $invite->email ) ),
			];
		}

		return $this->success( $invites );
	}

	/**
	 * Invites an email to play with the given characters: an account with that email is made a player and linked now;
	 * otherwise the invite waits, with an invitation emailed when asked.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_invite( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$email = trim( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return $this->error( 'invalid_email', __( 'Enter the player\'s full email address.', 'beyond-elysium' ), 400 );
		}

		$character_ids = array_map( 'intval', (array) $request->get_param( 'character_ids' ) );
		$result        = Player_Invites::invite( $game, $email, $character_ids, get_current_user_id() );

		if ( $result['status'] === 'invited' ) {
			$result['email_sent'] = $request->get_param( 'send_email' )
				? Player_Invites::send_invitation( $game, $email, get_current_user_id() )
				: false;
		}

		return $this->success( $result, $result['status'] === 'invited' ? 201 : 200 );
	}

	/**
	 * Cancels an open invite; its characters stop waiting for the email.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_invite( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		if ( ! Player_Invites::cancel( $game, (int) $request['id'] ) ) {
			return $this->error( 'invite_not_found', __( 'No open invite with that id in this chronicle.', 'beyond-elysium' ), 404 );
		}
		return $this->success( [ 'cancelled' => true ] );
	}

	/**
	 * Links many of the chronicle's characters to one of its members; a character linked to someone else is skipped and
	 * named.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function link_characters( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$wp_user_id = (int) $request['wp_user_id'];
		if ( ! Game_Member::find( (int) $game->id, $wp_user_id ) ) {
			return $this->error( 'not_member', __( 'That account is not a member of this chronicle.', 'beyond-elysium' ), 404 );
		}

		return $this->success( Player_Invites::link_characters(
			$game,
			$wp_user_id,
			array_map( 'intval', (array) $request->get_param( 'character_ids' ) ),
			null,
			get_current_user_id()
		) );
	}

	/**
	 * Unlinks one of the chronicle's characters from the member it is linked to.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function unlink_character( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		if ( ! Player_Invites::unlink_character( $game, (int) $request['wp_user_id'], (int) $request['character_id'] ) ) {
			return $this->error( 'not_linked', __( 'That character is not linked to that account in this chronicle.', 'beyond-elysium' ), 404 );
		}
		return $this->success( [ 'unlinked' => true ] );
	}

	/**
	 * Every join request on this chronicle, each with the applicant's display name and the character or file it
	 * carries, waiting first.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_join_requests( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$rows = array_map( function ( $row ) {
			$user      = get_userdata( (int) $row->wp_user_id );
			$character = ! empty( $row->character_id ) ? \BeyondElysium\Models\Character::find( (int) $row->character_id ) : null;
			return [
				'id'            => (int) $row->id,
				'wp_user_id'    => (int) $row->wp_user_id,
				'display_name'  => $user ? $user->display_name : null,
				'message'       => (string) $row->message,
				'status'        => (string) $row->status,
				'created_at'    => (string) $row->created_at,
				'character'     => $character ? [ 'id' => (int) $character->id, 'name' => (string) $character->name ] : null,
				'submission_id' => $row->submission_id !== null ? (int) $row->submission_id : null,
				'note'          => $row->note !== null ? (string) $row->note : null,
			];
		}, \BeyondElysium\Models\Join_Request::for_game( (int) $game->id ) );

		usort( $rows, static fn( $a, $b ) => $a['status'] === $b['status'] ? 0 : ( $a['status'] === 'waiting' ? -1 : 1 ) );

		return $this->success( $rows );
	}

	/**
	 * Approves a waiting join request: grants membership, activates an attached character or accepts an attached file,
	 * and emails the applicant.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function approve_join_request( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$join_request = \BeyondElysium\Models\Join_Request::find( (int) $request['id'] );
		if ( ! $join_request || (int) $join_request->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Join request not found.', 'beyond-elysium' ), 404 );
		}
		if ( $join_request->status !== 'waiting' ) {
			return $this->error( 'already_answered', __( 'This request was already answered.', 'beyond-elysium' ), 409 );
		}
		$id = (int) $join_request->id;

		if ( ! empty( $join_request->submission_id ) ) {
			return $this->error(
				'review_the_file_instead',
				__( 'This request carries a Grapevine file - review and accept it from Import, which closes this request too.', 'beyond-elysium' ),
				400
			);
		}

		Chronicle_Players::add( $game, (int) $join_request->wp_user_id );

		if ( ! empty( $join_request->character_id ) ) {
			\BeyondElysium\Models\Character::update_header( (int) $join_request->character_id, [ 'status' => 'active' ] );
			// update_header() approves and emails the request tied to the character it just activated.
			return $this->success( \BeyondElysium\Models\Join_Request::find( $id ) );
		}

		\BeyondElysium\Models\Join_Request::approve( $id, get_current_user_id() );
		\BeyondElysium\Core\Notifications::join_answered( $game, $join_request, true, null );

		return $this->success( \BeyondElysium\Models\Join_Request::find( $id ) );
	}

	/**
	 * Refuses a waiting join request, with an optional note, deleting a pending character started for it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function refuse_join_request( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$join_request = \BeyondElysium\Models\Join_Request::find( (int) $request['id'] );
		if ( ! $join_request || (int) $join_request->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Join request not found.', 'beyond-elysium' ), 404 );
		}
		if ( $join_request->status !== 'waiting' ) {
			return $this->error( 'already_answered', __( 'This request was already answered.', 'beyond-elysium' ), 409 );
		}
		$id = (int) $join_request->id;

		$note = $request->get_param( 'note' ) ? sanitize_textarea_field( (string) $request->get_param( 'note' ) ) : null;

		if ( ! empty( $join_request->character_id ) ) {
			$character = \BeyondElysium\Models\Character::find( (int) $join_request->character_id );
			if ( $character && $character->status === 'pending' ) {
				\BeyondElysium\Models\Character::delete( (int) $character->id );
			}
		}
		if ( ! empty( $join_request->submission_id ) ) {
			$submission = \BeyondElysium\Models\Submission::find( (int) $join_request->submission_id );
			if ( $submission && $submission->state === 'waiting' ) {
				\BeyondElysium\Models\Submission::transition( (int) $submission->id, 'refused', [
					'answered_by' => get_current_user_id(),
					'answer_note' => $note,
				] );
			}
		}

		\BeyondElysium\Models\Join_Request::refuse( $id, get_current_user_id(), $note );
		\BeyondElysium\Core\Notifications::join_answered( $game, $join_request, false, $note );

		return $this->success( \BeyondElysium\Models\Join_Request::find( $id ) );
	}

	/**
	 * A member's characters in the chronicle, by name.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private static function characters_of( object $game, int $wp_user_id ): array {
		return array_map(
			static fn( $row ) => [ 'id' => (int) $row->id, 'name' => (string) $row->name ],
			Manager::get_results(
				'SELECT id, name FROM ' . Manager::table( 'characters' ) . ' WHERE owner_slug = %s AND wp_user_id = %d ORDER BY name ASC',
				(string) $game->slug,
				$wp_user_id
			)
		);
	}

	/**
	 * Characters by id, each with its name.
	 *
	 * @param int[] $ids
	 * @return array<int,array{id:int,name:string}>
	 */
	private static function named( array $ids ): array {
		$named = [];
		foreach ( $ids as $id ) {
			$character = \BeyondElysium\Models\Character::find( $id );
			if ( $character ) {
				$named[] = [ 'id' => (int) $id, 'name' => (string) $character->name ];
			}
		}
		return $named;
	}

	/**
	 * Resolves a game by its slug.
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
