<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\After_Game_Report;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 §3.14, A1: a player writes one after-game report per character per session, editable
 * until the session's own `reports_due_at`; Storytellers read and mark read, never edit.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.14
 */
class AfterGameReportThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-after-game-report';
	private int $game_id;
	private int $storyteller_id;
	private int $player_id;
	private int $character_id;
	private int $other_player_id;
	private int $other_character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'After-Game Report' ] );

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		$this->character_id = (int) Character::create( [
			'name' => 'Reporter', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id, 'created_by' => $this->storyteller_id,
		] );

		$this->other_player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->other_player_id, 'player' );
		$this->other_character_id = (int) Character::create( [
			'name' => 'Someone Else', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->other_player_id, 'created_by' => $this->storyteller_id,
		] );
	}

	private function make_session( array $overrides = [] ): int {
		return (int) Game_Session::create( array_merge( [
			'game_id' => $this->game_id, 'game_date' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
			'created_by' => $this->storyteller_id,
		], $overrides ) );
	}

	private function create_report( int $wp_user_id, int $session_id, int $character_id, array $fields = [] ) {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/reports" );
		$request->set_body_params( array_merge( [
			'character_id' => $character_id,
			'did'          => 'Did a thing.',
			'wants'        => 'Wants a thing.',
			'to_staff'     => 'A note for staff.',
		], $fields ) );
		return rest_get_server()->dispatch( $request );
	}

	private function update_report( int $wp_user_id, int $session_id, int $character_id, array $fields ) {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/sessions/{$session_id}/reports" );
		$request->set_body_params( array_merge( [ 'character_id' => $character_id ], $fields ) );
		return rest_get_server()->dispatch( $request );
	}

	private function get_reports( int $wp_user_id, int $session_id ) {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/sessions/{$session_id}/reports" );
		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Creating and editing your own report.
	// -------------------------------------------------------------------------

	public function test_a_player_can_file_a_report_for_their_own_character(): void {
		$session_id = $this->make_session();

		$response = $this->create_report( $this->player_id, $session_id, $this->character_id );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Did a thing.', $response->get_data()->did );
	}

	public function test_a_player_can_edit_their_own_report_before_it_closes(): void {
		$session_id = $this->make_session();
		$this->create_report( $this->player_id, $session_id, $this->character_id );

		$response = $this->update_report( $this->player_id, $session_id, $this->character_id, [ 'did' => 'Changed my mind.' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Changed my mind.', $response->get_data()->did );
	}

	public function test_filing_a_second_report_for_the_same_character_is_refused(): void {
		$session_id = $this->make_session();
		$this->create_report( $this->player_id, $session_id, $this->character_id );

		$response = $this->create_report( $this->player_id, $session_id, $this->character_id );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_a_player_cannot_file_a_report_for_someone_elses_character(): void {
		$session_id = $this->make_session();

		$response = $this->create_report( $this->player_id, $session_id, $this->other_character_id );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// A future session is refused; the window closes at reports_due_at.
	// -------------------------------------------------------------------------

	public function test_a_future_session_is_refused(): void {
		$session_id = $this->make_session( [ 'game_date' => gmdate( 'Y-m-d', strtotime( '+1 day' ) ) ] );

		$response = $this->create_report( $this->player_id, $session_id, $this->character_id );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_session_past_its_own_reports_due_at_refuses_a_new_report(): void {
		$session_id = $this->make_session( [ 'reports_due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ) ] );

		$response = $this->create_report( $this->player_id, $session_id, $this->character_id );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_editing_closes_at_the_same_due_time(): void {
		$session_id = $this->make_session();
		$this->create_report( $this->player_id, $session_id, $this->character_id );

		Game_Session::update( $session_id, [ 'reports_due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ) ] );

		$response = $this->update_report( $this->player_id, $session_id, $this->character_id, [ 'did' => 'Too late.' ] );
		$this->assertSame( 409, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Visibility: staff read all, other players 403/never see it.
	// -------------------------------------------------------------------------

	public function test_staff_can_read_every_report_at_a_session(): void {
		$session_id = $this->make_session();
		$this->create_report( $this->player_id, $session_id, $this->character_id );
		$this->create_report( $this->other_player_id, $session_id, $this->other_character_id );

		$response = $this->get_reports( $this->storyteller_id, $session_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data() );
	}

	public function test_a_player_only_ever_sees_their_own_report(): void {
		$session_id = $this->make_session();
		$this->create_report( $this->player_id, $session_id, $this->character_id );
		$this->create_report( $this->other_player_id, $session_id, $this->other_character_id );

		$response = $this->get_reports( $this->player_id, $session_id );

		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( $this->character_id, (int) $response->get_data()[0]->character_id );
	}

	// -------------------------------------------------------------------------
	// Staff mark read; they never edit.
	// -------------------------------------------------------------------------

	public function test_staff_can_mark_a_report_read(): void {
		$session_id = $this->make_session();
		$report_id  = (int) $this->create_report( $this->player_id, $session_id, $this->character_id )->get_data()->id;

		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/after-game-reports/{$report_id}/read" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotNull( $response->get_data()->read_at );
		$this->assertSame( $this->storyteller_id, (int) $response->get_data()->read_by );
	}

	public function test_a_player_cannot_mark_a_report_read(): void {
		$session_id = $this->make_session();
		$report_id  = (int) $this->create_report( $this->player_id, $session_id, $this->character_id )->get_data()->id;

		wp_set_current_user( $this->player_id );
		$request  = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/after-game-reports/{$report_id}/read" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Report XP, once.
	// -------------------------------------------------------------------------

	public function test_awarding_report_xp_lands_once_for_every_character_with_a_report(): void {
		$session_id = $this->make_session();
		$this->create_report( $this->player_id, $session_id, $this->character_id );
		$this->create_report( $this->other_player_id, $session_id, $this->other_character_id );

		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/award-report-xp" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['awarded_count'] );

		$character = Character::find( $this->character_id );
		$this->assertGreaterThan( 0, (int) $character->xp_earned );
	}

	public function test_a_second_award_is_refused_without_force(): void {
		$session_id = $this->make_session();
		$this->create_report( $this->player_id, $session_id, $this->character_id );

		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/award-report-xp" );
		rest_get_server()->dispatch( $request );

		$second = rest_get_server()->dispatch( $request );
		$this->assertSame( 409, $second->get_status() );
	}

	public function test_a_player_may_not_award_report_xp(): void {
		$session_id = $this->make_session();
		$this->create_report( $this->player_id, $session_id, $this->character_id );

		wp_set_current_user( $this->player_id );
		$request  = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/award-report-xp" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// A session with a report can't be deleted.
	// -------------------------------------------------------------------------

	public function test_a_session_with_a_report_cannot_be_deleted(): void {
		$session_id = $this->make_session();
		$this->create_report( $this->player_id, $session_id, $this->character_id );

		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'DELETE', "/be/v1/{$this->slug}/sessions/{$session_id}" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// After_Game_Report model unit-shape coverage via the real thread harness.
	// -------------------------------------------------------------------------

	public function test_last_report_date_reads_back_the_sessions_own_game_date(): void {
		$session_id = $this->make_session( [ 'game_date' => '2026-01-01' ] );
		$this->create_report( $this->player_id, $session_id, $this->character_id );

		$this->assertSame( '2026-01-01', After_Game_Report::last_report_date( $this->character_id ) );
	}
}
