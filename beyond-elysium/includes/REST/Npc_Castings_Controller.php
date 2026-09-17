<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Npc_Casting;
use BeyondElysium\Services\Pdf_Signer;
use BeyondElysium\Services\Pdf_Writer;
use BeyondElysium\Services\Sheet_Document;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for NPC casting (1.1.0 §3.8) - a chronicle member loaned "how to play this
 * character tonight" for one session. CRUD is `be_manage_characters` only; the brief and its
 * PDF are readable by the cast member themselves (while their access window is open) or a
 * manager, matching `Sheets_Controller::get_pdf()`'s own manager-or-owner shape.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.8
 */
class Npc_Castings_Controller extends Base_Controller {

	protected $rest_base = 'castings';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/castings', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		// Registered before the numeric id route so neither literal path is ever shadowed -
		// same convention as Plots_Controller's own /my/plots.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/castings/my-upcoming', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_my_upcoming' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		// Every chronicle member, any role, name and id only - who a casting may name, since
		// the owner ruled anyone can be cast. Deliberately not /{game}/members: that path
		// already belongs to Game_Members_Controller's own be_manage_games-only full roster,
		// which an ordinary HST/AST could never reach at all.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/castings/members', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_eligible_members' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/castings/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/castings/(?P<id>\d+)/brief', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_brief' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/castings/(?P<id>\d+)/brief.pdf', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_brief_pdf' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		add_filter( 'rest_pre_serve_request', [ $this, 'serve_pdf_bytes' ], 10, 4 );
	}

	/**
	 * Every casting for one session - a manager sees them all, anyone else sees only their own.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$session_id = (int) $request->get_param( 'session_id' );
		if ( ! $session_id ) {
			return $this->error( 'invalid_request', __( 'session_id is required.', 'beyond-elysium' ), 400 );
		}
		$session = Game_Session::find( $session_id );
		if ( ! $session || (int) $session->game_id !== (int) $game->id ) {
			return $this->error( 'session_not_found', __( 'Session not found in this game.', 'beyond-elysium' ), 404 );
		}

		$can_manage = Authorization::can( 'be_manage_characters' );
		$wp_user_id = get_current_user_id();

		$castings = array_values( array_filter(
			Npc_Casting::for_session( $session_id ),
			static fn( $c ) => $can_manage || (int) $c->wp_user_id === $wp_user_id
		) );

		return $this->success( $castings );
	}

	/**
	 * Casts a chronicle member to play an NPC for a session. 400 `not_an_npc`/`not_a_member`,
	 * 409 `already_cast` when this NPC already has a casting at this session.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$session_id = (int) $request->get_param( 'session_id' );
		$session    = $session_id ? Game_Session::find( $session_id ) : null;
		if ( ! $session || (int) $session->game_id !== (int) $game->id ) {
			return $this->error( 'session_not_found', __( 'Session not found in this game.', 'beyond-elysium' ), 404 );
		}

		$character_id = (int) $request->get_param( 'character_id' );
		$character    = $character_id ? Character::find( $character_id ) : null;
		if ( ! $character || $character->owner_slug !== $request['game_slug'] || ! $character->is_npc ) {
			return $this->error( 'not_an_npc', __( 'character_id must name an NPC in this game.', 'beyond-elysium' ), 400 );
		}

		$wp_user_id = (int) $request->get_param( 'wp_user_id' );
		if ( ! $wp_user_id || ! Game_Member::find( (int) $game->id, $wp_user_id ) ) {
			return $this->error( 'not_a_member', __( 'wp_user_id must be a member of this chronicle.', 'beyond-elysium' ), 400 );
		}

		if ( Npc_Casting::already_cast( $session_id, $character_id ) ) {
			return $this->error( 'already_cast', __( 'This NPC is already cast at this session.', 'beyond-elysium' ), 409 );
		}

		$id = Npc_Casting::create( [
			'game_id'      => (int) $game->id,
			'session_id'   => $session_id,
			'character_id' => $character_id,
			'wp_user_id'   => $wp_user_id,
			'brief'        => $request->get_param( 'brief' ) ? wp_kses_post( (string) $request->get_param( 'brief' ) ) : null,
		] );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create this casting.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Npc_Casting::find( (int) $id ), 201 );
	}

	/**
	 * Updates a casting's cast member and/or brief.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$casting = $this->resolve_casting( $request );
		if ( is_wp_error( $casting ) ) {
			return $casting;
		}

		$data = [];
		if ( $request->has_param( 'wp_user_id' ) ) {
			$wp_user_id = (int) $request->get_param( 'wp_user_id' );
			if ( ! $wp_user_id || ! Game_Member::find( (int) $casting->game_id, $wp_user_id ) ) {
				return $this->error( 'not_a_member', __( 'wp_user_id must be a member of this chronicle.', 'beyond-elysium' ), 400 );
			}
			$data['wp_user_id'] = $wp_user_id;
		}
		if ( $request->has_param( 'brief' ) ) {
			$data['brief'] = $request->get_param( 'brief' ) ? wp_kses_post( (string) $request->get_param( 'brief' ) ) : null;
		}

		Npc_Casting::update( (int) $casting->id, $data );
		return $this->success( Npc_Casting::find( (int) $casting->id ) );
	}

	/**
	 * Removes a casting.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$casting = $this->resolve_casting( $request );
		if ( is_wp_error( $casting ) ) {
			return $casting;
		}

		Npc_Casting::delete( (int) $casting->id );
		return $this->success( null, 204 );
	}

	/**
	 * Every chronicle member, any role, name and id only - the casting screen's own member
	 * picker (1.1.0 §3.8), broader than `GET /my/queue/staff`'s hst/ast/narrator-only roster.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_eligible_members( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$members = array_map( static function ( $member ) {
			$user = get_userdata( (int) $member->wp_user_id );
			return [
				'id'   => (int) $member->wp_user_id,
				'name' => $user ? $user->display_name : (string) $member->wp_user_id,
				'role' => (string) $member->role,
			];
		}, Game_Member::for_game( (int) $game->id ) );

		return $this->success( array_values( $members ) );
	}

	/**
	 * A chronicle member's own upcoming castings (today or later) - the player Dashboard's
	 * "You're playing {NPC}" card. Broader than `GET /my/queue`'s own `castings` section,
	 * which is gated to staff capabilities a plain cast player very often does not hold.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_my_upcoming( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		return $this->success( Npc_Casting::upcoming_for_user(
			get_current_user_id(),
			(int) $game->id,
			current_time( 'Y-m-d' )
		) );
	}

	/**
	 * The casting brief: the NPC's real and public name, the session's date/time/place, the
	 * resolved `npc_quick`/`npc_full` sections (Storyteller-only blocks removed except
	 * `npc-roleplaying-notes`), and the casting's own `brief` text - never XP, status, change
	 * history, connections, secrets, or the real player. Readable by the cast member while
	 * their access window is open (casting through the day after the session), or a manager
	 * at any time; everyone else 403s, matching every other visibility check in this plugin
	 * (denied, not "not found," since a manager confirms the casting itself already exists).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_brief( $request ) {
		$result = $this->resolve_readable_brief( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		[ $casting, $game ] = $result;

		$document = Sheet_Document::for_casting( (int) $casting->id, $game->slug );
		if ( $document === null ) {
			return $this->error( 'brief_unavailable', __( 'This casting brief could not be built.', 'beyond-elysium' ), 500 );
		}

		return $this->success( $document );
	}

	/**
	 * The same brief, as a PDF - "Casting brief" titled, signed exactly like a character sheet
	 * (stamped UNSIGNED when signing is off).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_brief_pdf( $request ) {
		$result = $this->resolve_readable_brief( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		[ $casting, $game ] = $result;

		$document = Sheet_Document::for_casting( (int) $casting->id, $game->slug );
		if ( $document === null ) {
			return $this->error( 'brief_unavailable', __( 'This casting brief could not be built.', 'beyond-elysium' ), 500 );
		}

		$signed   = Pdf_Signer::should_sign()['ok'];
		$bytes    = Pdf_Writer::write( [ $document ], $game, $signed );
		$filename = sanitize_file_name( (string) $document['title'] ) . ( $signed ? '' : '-unsigned' ) . '.pdf';

		return $this->success( [ 'bytes' => $bytes, 'filename' => $filename ] );
	}

	/**
	 * Resolves the casting a `brief`/`brief.pdf` request names, and refuses (403) unless the
	 * current user is either a manager or the cast member with their access window still open.
	 *
	 * @param \WP_REST_Request $request
	 * @return array{0:object,1:object}|\WP_Error
	 */
	private function resolve_readable_brief( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$casting = Npc_Casting::find( (int) $request['id'] );
		if ( ! $casting || (int) $casting->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Casting not found in this game.', 'beyond-elysium' ), 404 );
		}

		$can_manage = Authorization::can( 'be_manage_characters' );
		if ( ! $can_manage && Npc_Casting::active_for( get_current_user_id(), (int) $casting->id ) === null ) {
			return $this->error( 'ownership_denied', __( 'You do not have permission to view this casting brief.', 'beyond-elysium' ), 403 );
		}

		return [ $casting, $game ];
	}

	/**
	 * Looks up the casting a `PUT`/`DELETE` request names, confirmed to belong to the URL's
	 * game - the shared resolve step `update_item()`/`delete_item()` both need.
	 *
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function resolve_casting( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$casting = Npc_Casting::find( (int) $request['id'] );
		if ( ! $casting || (int) $casting->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Casting not found in this game.', 'beyond-elysium' ), 404 );
		}
		return $casting;
	}

	/**
	 * Intercepts the normal JSON-serialize-and-serve step for exactly `get_brief_pdf()`'s
	 * route, matched by callback identity - identical mechanism to
	 * `Sheets_Controller::serve_pdf_bytes()`.
	 *
	 * @param bool              $served
	 * @param \WP_REST_Response $result
	 * @param \WP_REST_Request  $request
	 * @param \WP_REST_Server   $server
	 * @return bool
	 */
	public function serve_pdf_bytes( $served, $result, $request, $server ) {
		$attributes = $request->get_attributes();
		if ( ( $attributes['callback'] ?? null ) !== [ $this, 'get_brief_pdf' ] ) {
			return $served;
		}

		$data = $result->get_data();
		if ( ! is_array( $data ) || ! isset( $data['bytes'], $data['filename'] ) ) {
			return $served;
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $data['filename'] . '"' );
		echo $data['bytes']; // phpcs:ignore -- raw binary PDF bytes, not HTML output.
		return true;
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
