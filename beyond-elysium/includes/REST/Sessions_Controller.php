<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\After_Game_Report;
use BeyondElysium\Models\Attendance;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Spotlight;
use BeyondElysium\Services\St_Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a chronicle's game sessions (1.1.0 §3.1): the calendar of game nights,
 * sign-in attendance, and awarding attendance XP once a session's roster is settled.
 */
class Sessions_Controller extends Base_Controller {

	protected $rest_base = 'sessions';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/sessions', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_sessions' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/session-settings', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_settings' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/sessions/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_sessions' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_sessions' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/sessions/(?P<id>\d+)/attendance', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_attendance' ],
				'permission_callback' => $this->permission( 'be_manage_sessions' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_attendance' ],
				'permission_callback' => $this->permission( 'be_manage_sessions' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/sessions/(?P<id>\d+)/attendance/(?P<attendance_id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_attendance' ],
				'permission_callback' => $this->permission( 'be_manage_sessions' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/sessions/(?P<id>\d+)/award-attendance-xp', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'award_attendance_xp' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/sessions/(?P<id>\d+)/downtime-extensions', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_downtime_extension' ],
				'permission_callback' => $this->permission( 'be_manage_apr' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/sessions/(?P<id>\d+)/downtime-extensions/(?P<character_id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_downtime_extension' ],
				'permission_callback' => $this->permission( 'be_manage_apr' ),
			],
		] );

		// After-game reports (1.1.0 §3.14, A1) - broad on purpose (be_edit_own_characters, the
		// widest role-granted capability in this plugin): a player files their own report;
		// get_reports()/create_report()/update_report() each do the real ownership check
		// themselves, the same broad-route-narrow-handler shape Changes_Controller uses.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/sessions/(?P<id>\d+)/reports', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_reports' ],
				'permission_callback' => $this->permission( 'be_edit_own_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_report' ],
				'permission_callback' => $this->permission( 'be_edit_own_characters' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_report' ],
				'permission_callback' => $this->permission( 'be_edit_own_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/after-game-reports/(?P<report_id>\d+)/read', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'mark_report_read' ],
				'permission_callback' => $this->permission_any( [ 'be_manage_plots', 'be_manage_characters' ] ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/sessions/(?P<id>\d+)/award-report-xp', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'award_report_xp' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/spotlight', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_spotlight' ],
				'permission_callback' => $this->permission_any( [ 'be_manage_plots', 'be_manage_characters' ] ),
			],
		] );
	}

	/**
	 * Lists a chronicle's sessions, soonest first, optionally narrowed to a date range - the
	 * calendar view's own `from`/`to` params. Every member sees the calendar; the XP-awarded
	 * fields are stripped for anyone without be_manage_sessions.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$can_manage = Authorization::check_request( 'be_manage_sessions', $request );
		$sessions   = Game_Session::for_game(
			(int) $game->id,
			$request->get_param( 'from' ) ?: null,
			$request->get_param( 'to' ) ?: null
		);
		foreach ( $sessions as $session ) {
			$this->prepare_session( $session, $can_manage, $game );
		}

		return $this->success( $sessions );
	}

	/**
	 * Creates a new session. game_date is required and must be unique within the chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$game_date = $request->get_param( 'game_date' );
		if ( ! $game_date ) {
			return $this->error( 'invalid_param', __( 'Missing required field: game_date.', 'beyond-elysium' ), 400 );
		}
		if ( Game_Session::find_by_date( (int) $game->id, $game_date ) ) {
			return $this->error( 'duplicate_date', __( 'A session already exists on this date.', 'beyond-elysium' ), 409 );
		}

		$data = [
			'game_id'    => (int) $game->id,
			'game_date'  => $game_date,
			'start_time' => $request->get_param( 'start_time' ) ? sanitize_text_field( $request->get_param( 'start_time' ) ) : null,
			'place'      => $request->get_param( 'place' ) ? sanitize_text_field( $request->get_param( 'place' ) ) : null,
			'notes'      => $request->get_param( 'notes' ) ? wp_kses_post( $request->get_param( 'notes' ) ) : null,
			'created_by' => get_current_user_id(),
		];
		$data = array_merge( $data, $this->resolve_apr_gated_fields( $request ) );

		$id = Game_Session::create( $data );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create this session.', 'beyond-elysium' ), 500 );
		}

		$session = Game_Session::find( (int) $id );
		if ( ! $session ) {
			throw new \RuntimeException( 'Sessions_Controller::create_item() failed to read back its own insert.' );
		}
		$this->prepare_session( $session, true, $game );
		return $this->success( $session, 201 );
	}

	/**
	 * Updates an existing session. The four downtime fields (downtime_opens_at,
	 * downtime_deadline_at, downtime_extensions, default_batch_id) additionally require
	 * be_manage_apr - present but silently dropped for a caller who only holds
	 * be_manage_sessions, the same "don't trust a field this caller can't set" rule this
	 * codebase applies elsewhere rather than erroring on it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$session = Game_Session::find( (int) $request['id'] );
		if ( ! $session || (int) $session->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Session not found in this game.', 'beyond-elysium' ), 404 );
		}

		$data = [];
		if ( $request->get_param( 'game_date' ) !== null ) {
			$data['game_date'] = $request->get_param( 'game_date' );
		}
		if ( $request->get_param( 'start_time' ) !== null ) {
			$data['start_time'] = sanitize_text_field( $request->get_param( 'start_time' ) );
		}
		if ( $request->get_param( 'place' ) !== null ) {
			$data['place'] = sanitize_text_field( $request->get_param( 'place' ) );
		}
		if ( $request->get_param( 'notes' ) !== null ) {
			$data['notes'] = wp_kses_post( $request->get_param( 'notes' ) );
		}
		if ( $request->get_param( 'reports_due_at' ) !== null ) {
			$data['reports_due_at'] = $request->get_param( 'reports_due_at' );
		}
		$data = array_merge( $data, $this->resolve_apr_gated_fields( $request ) );

		if ( isset( $data['game_date'] ) && $data['game_date'] !== $session->game_date ) {
			$existing = Game_Session::find_by_date( (int) $game->id, $data['game_date'] );
			if ( $existing && (int) $existing->id !== (int) $session->id ) {
				return $this->error( 'duplicate_date', __( 'A session already exists on this date.', 'beyond-elysium' ), 409 );
			}
		}

		if ( ! Game_Session::update( (int) $session->id, $data ) && ! empty( $data ) ) {
			return $this->error( 'update_failed', __( 'Failed to update this session.', 'beyond-elysium' ), 500 );
		}

		$updated = Game_Session::find( (int) $session->id );
		if ( ! $updated ) {
			return $this->error( 'not_found', __( 'Session not found in this game.', 'beyond-elysium' ), 404 );
		}
		$this->prepare_session( $updated, true, $game );
		return $this->success( $updated );
	}

	/**
	 * Deletes a session, refused with 409 if it already has real data (currently attendance
	 * only - see Game_Session::is_in_use()).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$session = Game_Session::find( (int) $request['id'] );
		if ( ! $session || (int) $session->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Session not found in this game.', 'beyond-elysium' ), 404 );
		}
		if ( Game_Session::is_in_use( (int) $session->id ) ) {
			return $this->error( 'session_in_use', __( 'This session already has attendance or an NPC casting recorded - change its date instead of deleting it.', 'beyond-elysium' ), 409 );
		}

		Game_Session::delete( (int) $session->id );
		return $this->success( null, 204 );
	}

	/**
	 * Lists everyone recorded present at a session.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_attendance( $request ) {
		$session = $this->resolve_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		return $this->success( Attendance::for_session( (int) $session->id ) );
	}

	/**
	 * Records a sign-in: a real, active, non-NPC character in this chronicle, or a visitor by
	 * name. A character already signed in at this session is refused with 409.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_attendance( $request ) {
		$session = $this->resolve_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$character_id = $request->get_param( 'character_id' );
		$visitor_name = $request->get_param( 'visitor_name' );

		if ( $character_id ) {
			$character = Character::find( (int) $character_id );
			if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
				return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
			}
			if ( $character->status !== 'active' || (int) $character->is_npc !== 0 ) {
				return $this->error( 'invalid_candidate', __( 'Only an active, non-NPC character may be signed in.', 'beyond-elysium' ), 400 );
			}
			if ( in_array( (int) $character_id, Attendance::character_ids_for_session( (int) $session->id ), true ) ) {
				return $this->error( 'already_signed_in', __( 'This character is already signed in at this session.', 'beyond-elysium' ), 409 );
			}
		} elseif ( ! $visitor_name ) {
			return $this->error( 'invalid_param', __( 'Provide either character_id or visitor_name.', 'beyond-elysium' ), 400 );
		}

		$id = Attendance::record( (int) $session->id, (int) $session->game_id, [
			'character_id'      => $character_id ? (int) $character_id : null,
			'visitor_name'      => $visitor_name ? sanitize_text_field( $visitor_name ) : null,
			'visitor_chronicle' => $request->get_param( 'visitor_chronicle' ) ? sanitize_text_field( $request->get_param( 'visitor_chronicle' ) ) : null,
			'recorded_by'       => get_current_user_id(),
		] );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to record this sign-in.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Attendance::find( (int) $id ), 201 );
	}

	/**
	 * Removes one attendance row from a session.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_attendance( $request ) {
		$session = $this->resolve_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$attendance = Attendance::find( (int) $request['attendance_id'] );
		if ( ! $attendance || (int) $attendance->session_id !== (int) $session->id ) {
			return $this->error( 'not_found', __( 'This sign-in was not found at this session.', 'beyond-elysium' ), 404 );
		}

		Attendance::remove( (int) $attendance->id );
		return $this->success( null, 204 );
	}

	/**
	 * Awards attendance XP once to every character signed in at a session. Refused with 409 if
	 * already awarded, unless force is set.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function award_attendance_xp( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$session = Game_Session::find( (int) $request['id'] );
		if ( ! $session || (int) $session->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Session not found in this game.', 'beyond-elysium' ), 404 );
		}
		if ( $session->attendance_xp_awarded_at && ! $request->get_param( 'force' ) ) {
			return $this->error( 'already_awarded', __( 'Attendance XP has already been awarded for this session.', 'beyond-elysium' ), 409 );
		}

		$settings = (array) ( $game->settings ?? [] );
		$sessions_settings = (array) ( $settings['sessions'] ?? [] );
		$amount   = $request->get_param( 'amount' ) !== null
			? (int) $request->get_param( 'amount' )
			: (int) ( $sessions_settings['attendance_xp'] ?? 1 );

		$character_ids = Attendance::character_ids_for_session( (int) $session->id );
		$awarded_count = Change_Engine::bulk_award_xp(
			$character_ids,
			$amount,
			sprintf(
				/* translators: %s: the session's game date, Y-m-d */
				__( 'Attended %s', 'beyond-elysium' ),
				$session->game_date
			),
			get_current_user_id()
		);

		Game_Session::update( (int) $session->id, [
			'attendance_xp_awarded_at' => current_time( 'mysql' ),
			'attendance_xp_awarded_by' => get_current_user_id(),
		] );

		return $this->success( [
			'awarded_count' => $awarded_count,
			'amount'        => $amount,
		] );
	}

	/**
	 * Lists after-game reports for a session (1.1.0 §3.14, A1) - staff see every report; a
	 * player sees only their own.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_reports( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$session = $this->resolve_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$can_manage = Authorization::check_request( 'be_manage_plots', $request )
			|| Authorization::check_request( 'be_manage_characters', $request );

		$reports = After_Game_Report::for_session( (int) $session->id );
		if ( ! $can_manage ) {
			$reports = array_values( array_filter( $reports, static function ( $report ) {
				return (int) $report->wp_user_id === get_current_user_id();
			} ) );
		}
		foreach ( $reports as $report ) {
			St_Visibility::filter_report( $report, $game, $can_manage );
		}

		return $this->success( $reports );
	}

	/**
	 * Creates a report for the caller's own character at this session. The session's game_date
	 * must be on or before today, and the report can't already exist for this character (409).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_report( $request ) {
		$session = $this->resolve_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$character = $this->resolve_own_character( $request );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		$closed = $this->report_window_error( $session );
		if ( is_wp_error( $closed ) ) {
			return $closed;
		}

		if ( After_Game_Report::find_for_session( (int) $session->id, (int) $character->id ) ) {
			return $this->error( 'already_exists', __( 'A report for this character already exists at this session - edit it instead.', 'beyond-elysium' ), 409 );
		}

		$id = After_Game_Report::create( [
			'game_id'      => (int) $session->game_id,
			'session_id'   => (int) $session->id,
			'character_id' => (int) $character->id,
			'wp_user_id'   => get_current_user_id(),
			'did'          => $request->get_param( 'did' ) ? wp_kses_post( $request->get_param( 'did' ) ) : null,
			'wants'        => $request->get_param( 'wants' ) ? wp_kses_post( $request->get_param( 'wants' ) ) : null,
			'to_staff'     => $request->get_param( 'to_staff' ) ? wp_kses_post( $request->get_param( 'to_staff' ) ) : null,
		] );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to save this report.', 'beyond-elysium' ), 500 );
		}

		return $this->success( After_Game_Report::find( (int) $id ), 201 );
	}

	/**
	 * Updates the caller's own report for this session, until the session's reports_due_at.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_report( $request ) {
		$session = $this->resolve_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$character = $this->resolve_own_character( $request );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		$report = After_Game_Report::find_for_session( (int) $session->id, (int) $character->id );
		if ( ! $report || (int) $report->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'not_found', __( 'No report exists yet for this character at this session.', 'beyond-elysium' ), 404 );
		}

		$closed = $this->report_window_error( $session );
		if ( is_wp_error( $closed ) ) {
			return $closed;
		}

		$data = [];
		foreach ( [ 'did', 'wants', 'to_staff' ] as $field ) {
			if ( $request->get_param( $field ) !== null ) {
				$data[ $field ] = wp_kses_post( $request->get_param( $field ) );
			}
		}

		After_Game_Report::update( (int) $report->id, $data );
		return $this->success( After_Game_Report::find( (int) $report->id ) );
	}

	/**
	 * Marks a report read by a Storyteller. The report's own words are never editable through
	 * this route or any other - staff read and mark, they never write.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mark_report_read( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$report = After_Game_Report::find( (int) $request['report_id'] );
		if ( ! $report || (int) $report->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Report not found in this game.', 'beyond-elysium' ), 404 );
		}

		After_Game_Report::mark_read( (int) $report->id, get_current_user_id() );
		return $this->success( After_Game_Report::find( (int) $report->id ) );
	}

	/**
	 * Awards report XP once to every character with a report at a session - identical shape to
	 * award_attendance_xp(), just keyed off who filed a report instead of who signed in.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function award_report_xp( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$session = Game_Session::find( (int) $request['id'] );
		if ( ! $session || (int) $session->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Session not found in this game.', 'beyond-elysium' ), 404 );
		}
		if ( $session->report_xp_awarded_at && ! $request->get_param( 'force' ) ) {
			return $this->error( 'already_awarded', __( 'Report XP has already been awarded for this session.', 'beyond-elysium' ), 409 );
		}

		$settings          = (array) ( $game->settings ?? [] );
		$sessions_settings = (array) ( $settings['sessions'] ?? [] );
		$amount            = $request->get_param( 'amount' ) !== null
			? (int) $request->get_param( 'amount' )
			: (int) ( $sessions_settings['report_xp'] ?? 1 );

		$character_ids = After_Game_Report::character_ids_for_session( (int) $session->id );
		$awarded_count = Change_Engine::bulk_award_xp(
			$character_ids,
			$amount,
			sprintf(
				/* translators: %s: the session's game date, Y-m-d */
				__( 'Report for %s', 'beyond-elysium' ),
				$session->game_date
			),
			get_current_user_id()
		);

		Game_Session::update( (int) $session->id, [
			'report_xp_awarded_at' => current_time( 'mysql' ),
			'report_xp_awarded_by' => get_current_user_id(),
		] );

		return $this->success( [
			'awarded_count' => $awarded_count,
			'amount'        => $amount,
		] );
	}

	/**
	 * The spotlight check (1.1.0 §3.14, A2) - every active, non-NPC character's own attention
	 * profile, flagged first then least recent attention.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_spotlight( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$settings = $game->settings ? (array) $game->settings : [];
		return $this->success( Spotlight::for_game( (int) $game->id, $request['game_slug'], $settings ) );
	}

	/**
	 * Resolves the caller's own character named by the request's character_id param, refusing
	 * one they don't own (a manager may still only ever file a report as themselves here - this
	 * route is a player-authorship route, not a staff-on-behalf-of one).
	 *
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function resolve_own_character( $request ) {
		$character_id = (int) $request->get_param( 'character_id' );
		if ( ! $character_id ) {
			return $this->error( 'invalid_param', __( 'character_id is required.', 'beyond-elysium' ), 400 );
		}
		$character = Character::find( $character_id );
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'invalid_param', __( 'character_id must be a real character in this game.', 'beyond-elysium' ), 400 );
		}
		if ( (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You may only file a report for your own character.', 'beyond-elysium' ), 403 );
		}
		return $character;
	}

	/**
	 * Refuses a session in the future, or one past its own reports_due_at.
	 *
	 * @param object $session
	 * @return true|\WP_Error
	 */
	private function report_window_error( object $session ) {
		if ( $session->game_date > current_time( 'Y-m-d' ) ) {
			return $this->error( 'session_in_future', __( 'A report can only be filed for a session on or before today.', 'beyond-elysium' ), 400 );
		}
		if ( $session->reports_due_at !== null && current_time( 'mysql' ) > $session->reports_due_at ) {
			return $this->error( 'reports_closed', __( 'Reports for this session are no longer open.', 'beyond-elysium' ), 409 );
		}
		return true;
	}

	/**
	 * Sets one character's own downtime deadline for this session, replacing (not adding to)
	 * the session's own deadline for them alone (1.1.0 §3.3).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_downtime_extension( $request ) {
		$session = $this->resolve_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$character_id = (int) $request->get_param( 'character_id' );
		$until        = (string) $request->get_param( 'until' );
		if ( ! $character_id || $until === '' ) {
			return $this->error( 'invalid_param', __( 'character_id and until are required.', 'beyond-elysium' ), 400 );
		}
		$character = Character::find( $character_id );
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		$extensions                        = is_array( $session->downtime_extensions ) ? $session->downtime_extensions : [];
		$extensions[ (string) $character_id ] = $until;

		Game_Session::update( (int) $session->id, [ 'downtime_extensions' => $extensions ] );
		return $this->success( $extensions );
	}

	/**
	 * Removes one character's downtime extension, returning them to the session's own
	 * deadline.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_downtime_extension( $request ) {
		$session = $this->resolve_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$extensions = is_array( $session->downtime_extensions ) ? $session->downtime_extensions : [];
		unset( $extensions[ (string) ( (int) $request['character_id'] ) ] );

		Game_Session::update( (int) $session->id, [ 'downtime_extensions' => $extensions ] );
		return $this->success( null, 204 );
	}

	/**
	 * Updates this chronicle's session-related settings (attendance_xp, report_xp,
	 * spotlight_days), merged into settings.sessions - the rest of settings is preserved.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_settings( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$incoming = [];
		foreach ( [ 'attendance_xp', 'report_xp', 'spotlight_days' ] as $key ) {
			if ( $request->get_param( $key ) !== null ) {
				$incoming[ $key ] = (int) $request->get_param( $key );
			}
		}

		$settings             = $game->settings ? (array) $game->settings : [];
		$current              = isset( $settings['sessions'] ) ? (array) $settings['sessions'] : [];
		$settings['sessions'] = array_merge( $current, $incoming );

		if ( ! Game::update( $request['game_slug'], [ 'settings' => $settings ] ) ) {
			return $this->error( 'update_failed', __( 'Failed to update session settings.', 'beyond-elysium' ), 500 );
		}

		$updated = Game::find_by_slug( $request['game_slug'] );
		if ( ! $updated ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		$updated_settings = $updated->settings ? (array) $updated->settings : [];
		return $this->success( isset( $updated_settings['sessions'] ) ? (array) $updated_settings['sessions'] : [] );
	}

	/**
	 * Reads the four downtime fields from the request, gated on be_manage_apr in addition to
	 * this controller's own be_manage_sessions - present but held by a caller without it are
	 * silently dropped rather than erroring.
	 *
	 * @param \WP_REST_Request $request
	 * @return array
	 */
	private function resolve_apr_gated_fields( $request ): array {
		if ( ! Authorization::check_request( 'be_manage_apr', $request ) ) {
			return [];
		}

		$data = [];
		if ( $request->get_param( 'downtime_opens_at' ) !== null ) {
			$data['downtime_opens_at'] = $request->get_param( 'downtime_opens_at' );
		}
		if ( $request->get_param( 'downtime_deadline_at' ) !== null ) {
			$data['downtime_deadline_at'] = $request->get_param( 'downtime_deadline_at' );
		}
		if ( $request->get_param( 'default_batch_id' ) !== null ) {
			$data['default_batch_id'] = (int) $request->get_param( 'default_batch_id' );
		}
		if ( $request->has_param( 'downtime_extensions' ) ) {
			$data['downtime_extensions'] = $request->get_param( 'downtime_extensions' );
		}
		return $data;
	}

	/**
	 * Resolves a session named by a nested attendance route's {id}, confirming it belongs to
	 * the game named in the URL.
	 *
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function resolve_session( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$session = Game_Session::find( (int) $request['id'] );
		if ( ! $session || (int) $session->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Session not found in this game.', 'beyond-elysium' ), 404 );
		}
		return $session;
	}

	/**
	 * Strips the XP-awarded fields and any [ST]-marked notes text from a session for any
	 * viewer without be_manage_sessions.
	 *
	 * @param object $session
	 * @param bool   $can_manage
	 * @param object $game
	 * @return void
	 */
	private function prepare_session( $session, bool $can_manage, $game ): void {
		St_Visibility::filter_session( $session, $game, $can_manage );
		if ( ! $can_manage ) {
			unset(
				$session->attendance_xp_awarded_at,
				$session->attendance_xp_awarded_by,
				$session->report_xp_awarded_at,
				$session->report_xp_awarded_by
			);
		}
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
