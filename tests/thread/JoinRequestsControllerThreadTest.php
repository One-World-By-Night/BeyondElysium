<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Join_Request;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The applicant's own side of asking to join a chronicle (`/joinable`, `/{game}/join`) and a Storyteller's review of
 * it (the Players tab's join-requests routes).
 */
class JoinRequestsControllerThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-join-flow';
	private int $game_id;
	private int $hst;
	private int $applicant;
	private array $mail = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Join Flow' ] );
		$this->hst      = self::factory()->user->create( [ 'role' => 'editor', 'user_email' => 'join-flow-hst@example.test' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		$this->applicant = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Hopeful Applicant' ] );

		$this->mail = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		parent::tearDown();
	}

	public function capture_mail( $pre, $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	private function dispatch( int $as, string $method, string $route, array $body = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function ask( int $as = 0, string $message = 'I would love to play.' ) {
		return $this->dispatch( $as ?: $this->applicant, 'POST', "/be/v1/{$this->slug}/join", [ 'message' => $message ] );
	}

	// -------------------------------------------------------------------------
	// Joinable list
	// -------------------------------------------------------------------------

	public function test_joinable_lists_a_chronicle_the_caller_is_not_a_member_of(): void {
		$response = $this->dispatch( $this->applicant, 'GET', '/be/v1/joinable' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( [ 'slug' => $this->slug, 'name' => 'Join Flow' ], $response->get_data() );
	}

	public function test_joinable_excludes_a_chronicle_the_caller_already_belongs_to(): void {
		Game_Member::set_role( $this->game_id, $this->applicant, 'player' );

		$response = $this->dispatch( $this->applicant, 'GET', '/be/v1/joinable' );

		$this->assertNotContains( [ 'slug' => $this->slug, 'name' => 'Join Flow' ], $response->get_data() );
	}

	public function test_joinable_excludes_a_chronicle_with_join_requests_off(): void {
		Game::update( $this->slug, [ 'settings' => [ 'join_requests' => false ] ] );

		$response = $this->dispatch( $this->applicant, 'GET', '/be/v1/joinable' );

		$this->assertNotContains( [ 'slug' => $this->slug, 'name' => 'Join Flow' ], $response->get_data() );
	}

	// -------------------------------------------------------------------------
	// Asking
	// -------------------------------------------------------------------------

	public function test_asking_opens_a_waiting_request_the_storytellers_hear_about(): void {
		$response = $this->ask();

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'waiting', $response->get_data()->status );
		$this->assertSame( [ 'join-flow-hst@example.test' ], array_column( $this->mail, 'to' ) );
		$this->assertStringContainsString( 'I would love to play.', $this->mail[0]['message'] );
	}

	public function test_a_message_is_required(): void {
		$this->assertSame( 400, $this->ask( $this->applicant, '' )->get_status() );
	}

	public function test_a_demo_chronicle_sends_no_join_email(): void {
		Game::update( $this->slug, [ 'settings' => [ 'demo' => [ 'on' => true ] ] ] );

		$response = $this->ask();

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( [], $this->mail );
	}

	public function test_a_second_ask_is_refused(): void {
		$this->ask();

		$this->assertSame( 409, $this->ask()->get_status() );
	}

	public function test_asking_is_refused_once_join_requests_is_off(): void {
		Game::update( $this->slug, [ 'settings' => [ 'join_requests' => false ] ] );

		$response = $this->ask();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'join_requests_off', $response->as_error()->get_error_code() );
	}

	public function test_asking_again_and_again_after_withdrawing_is_capped(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 201, $this->ask()->get_status() );
			$this->assertSame( 204, $this->dispatch( $this->applicant, 'DELETE', "/be/v1/{$this->slug}/join" )->get_status() );
		}
		$this->mail = [];

		$response = $this->ask();

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( [], $this->mail );
	}

	public function test_an_existing_member_cannot_ask(): void {
		Game_Member::set_role( $this->game_id, $this->applicant, 'player' );

		$this->assertSame( 409, $this->ask()->get_status() );
	}

	public function test_get_item_reports_the_callers_own_waiting_request(): void {
		$this->ask();

		$response = $this->dispatch( $this->applicant, 'GET', "/be/v1/{$this->slug}/join" );

		$this->assertSame( 'waiting', $response->get_data()->status );
	}

	public function test_get_item_strips_an_st_marker_from_the_callers_own_message(): void {
		$this->ask( $this->applicant, 'Hi there. [ST]please seat me near the window[/ST] Thanks!' );

		$response = $this->dispatch( $this->applicant, 'GET', "/be/v1/{$this->slug}/join" );

		$this->assertStringNotContainsString( '[ST]', $response->get_data()->message );
		$this->assertStringNotContainsString( 'please seat me near the window', $response->get_data()->message );
	}

	public function test_get_item_is_null_with_no_waiting_request(): void {
		$response = $this->dispatch( $this->applicant, 'GET', "/be/v1/{$this->slug}/join" );

		$this->assertNull( $response->get_data() );
	}

	public function test_withdraw_closes_the_request_and_deletes_a_pending_character(): void {
		$this->ask();
		$id            = (int) Join_Request::find_waiting( $this->game_id, $this->applicant )->id;
		$character_id  = (int) $this->dispatch( $this->applicant, 'POST', "/be/v1/{$this->slug}/characters", [ 'name' => 'Started Then Withdrawn', 'stack_slug' => 'vampire' ] )->get_data()->id;

		$response = $this->dispatch( $this->applicant, 'DELETE', "/be/v1/{$this->slug}/join" );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( Character::find( $character_id ) );
		$this->assertNull( Join_Request::find_waiting( $this->game_id, $this->applicant ) );
		$this->assertSame( 'withdrawn', Join_Request::find( $id )->status );
	}

	// -------------------------------------------------------------------------
	// An applicant reaches the editor
	// -------------------------------------------------------------------------

	private function resolve_template( int $as ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/templates/resolve" );
		$request->set_param( 'stack_slug', 'vampire' );
		$request->set_param( 'template_type', 'sheet_full' );
		return rest_get_server()->dispatch( $request );
	}

	public function test_an_applicant_can_resolve_a_template_before_any_membership_exists(): void {
		$this->ask();

		$this->assertSame( 200, $this->resolve_template( $this->applicant )->get_status() );
	}

	public function test_a_stranger_with_no_request_at_all_still_resolves_a_template(): void {
		$stranger = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertSame( 200, $this->resolve_template( $stranger )->get_status(), 'allow_bootstrap already admits any signed-in account, matching the create route' );
	}

	// -------------------------------------------------------------------------
	// Starting a character after asking
	// -------------------------------------------------------------------------

	public function test_starting_a_character_after_asking_ties_it_with_no_second_email(): void {
		$this->ask();
		$this->mail = [];

		$response = $this->dispatch( $this->applicant, 'POST', "/be/v1/{$this->slug}/characters", [ 'name' => 'Tied Character', 'stack_slug' => 'vampire' ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( [], $this->mail, 'the ask already notified the storytellers once' );
		$waiting = Join_Request::find_waiting( $this->game_id, $this->applicant );
		$this->assertSame( (int) $response->get_data()->id, (int) $waiting->character_id );
	}

	public function test_starting_a_character_without_asking_is_refused_once_join_requests_is_off(): void {
		Game::update( $this->slug, [ 'settings' => [ 'join_requests' => false ] ] );

		$response = $this->dispatch( $this->applicant, 'POST', "/be/v1/{$this->slug}/characters", [ 'name' => 'Uninvited', 'stack_slug' => 'vampire' ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'join_requests_off', $response->as_error()->get_error_code() );
		$this->assertSame( [], $this->mail );
	}

	public function test_sending_a_joining_file_without_asking_is_refused_once_join_requests_is_off(): void {
		Game::update( $this->slug, [ 'settings' => [ 'join_requests' => false ] ] );
		Game::create( [ 'slug' => 'thread-join-flow-source', 'name' => 'Join Flow Source' ] );
		$source_id = Character::create( [
			'name' => 'Filed Joiner', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => 'thread-join-flow-source', 'status' => 'active',
		] );
		$xml = \BeyondElysium\Services\Character_Exporter::export( $source_id )['xml'];

		wp_set_current_user( $this->applicant );
		$tmp = tempnam( sys_get_temp_dir(), 'be-join-off' );
		file_put_contents( $tmp, $xml );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/submissions" );
		$request->set_file_params( [
			'file' => [ 'tmp_name' => $tmp, 'name' => 'sheet.gex', 'error' => 0, 'size' => strlen( $xml ), 'type' => 'application/octet-stream' ],
		] );
		$request->set_param( 'arrival', 'joining' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'join_requests_off', $response->as_error()->get_error_code() );
	}

	public function test_approving_a_request_that_carries_a_file_grants_nothing(): void {
		$this->ask();
		$id = (int) Join_Request::find_waiting( $this->game_id, $this->applicant )->id;
		Join_Request::tie_submission( $id, 999999 );

		$response = $this->dispatch( $this->hst, 'POST', "/be/v1/{$this->slug}/players/join-requests/{$id}/approve" );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( Game_Member::find( $this->game_id, $this->applicant ) );
	}

	public function test_a_second_character_after_the_first_is_tied_is_refused(): void {
		$this->ask();
		$this->dispatch( $this->applicant, 'POST', "/be/v1/{$this->slug}/characters", [ 'name' => 'First', 'stack_slug' => 'vampire' ] );

		$response = $this->dispatch( $this->applicant, 'POST', "/be/v1/{$this->slug}/characters", [ 'name' => 'Second', 'stack_slug' => 'vampire' ] );

		$this->assertSame( 409, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Reviewing
	// -------------------------------------------------------------------------

	public function test_the_players_tab_lists_a_waiting_request(): void {
		$this->ask();

		$response = $this->dispatch( $this->hst, 'GET', "/be/v1/{$this->slug}/players/join-requests" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Hopeful Applicant', $response->get_data()[0]['display_name'] );
		$this->assertSame( 'waiting', $response->get_data()[0]['status'] );
	}

	public function test_approving_a_message_only_request_grants_membership_and_emails_the_applicant(): void {
		$this->ask();
		$id = Join_Request::find_waiting( $this->game_id, $this->applicant )->id;
		$this->mail = [];

		$response = $this->dispatch( $this->hst, 'POST', "/be/v1/{$this->slug}/players/join-requests/{$id}/approve" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'approved', $response->get_data()->status );
		$this->assertSame( 'player', Game_Member::find( $this->game_id, $this->applicant )->role ?? null );
		$this->assertCount( 1, $this->mail );
	}

	public function test_approving_a_character_tied_request_activates_the_character(): void {
		$this->ask();
		$character_id = (int) $this->dispatch( $this->applicant, 'POST', "/be/v1/{$this->slug}/characters", [ 'name' => 'Up For Review', 'stack_slug' => 'vampire' ] )->get_data()->id;
		$id = Join_Request::find_waiting( $this->game_id, $this->applicant )->id;
		$this->mail = [];

		$response = $this->dispatch( $this->hst, 'POST', "/be/v1/{$this->slug}/players/join-requests/{$id}/approve" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'active', Character::find( $character_id )->status );
		$this->assertSame( 'player', Game_Member::find( $this->game_id, $this->applicant )->role ?? null );
		$this->assertCount( 1, $this->mail, 'exactly one approval email, not two' );
	}

	public function test_refusing_deletes_a_tied_pending_character_and_emails_the_note(): void {
		$this->ask();
		$character_id = (int) $this->dispatch( $this->applicant, 'POST', "/be/v1/{$this->slug}/characters", [ 'name' => 'Not This Time', 'stack_slug' => 'vampire' ] )->get_data()->id;
		$id = Join_Request::find_waiting( $this->game_id, $this->applicant )->id;
		$this->mail = [];

		$response = $this->dispatch( $this->hst, 'POST', "/be/v1/{$this->slug}/players/join-requests/{$id}/refuse", [ 'note' => 'Full right now.' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'refused', $response->get_data()->status );
		$this->assertNull( Character::find( $character_id ) );
		$this->assertNull( Game_Member::find( $this->game_id, $this->applicant ) );
		$this->assertStringContainsString( 'Full right now.', $this->mail[0]['message'] );
	}

	public function test_approving_twice_is_refused(): void {
		$this->ask();
		$id = Join_Request::find_waiting( $this->game_id, $this->applicant )->id;
		$this->dispatch( $this->hst, 'POST', "/be/v1/{$this->slug}/players/join-requests/{$id}/approve" );

		$response = $this->dispatch( $this->hst, 'POST', "/be/v1/{$this->slug}/players/join-requests/{$id}/approve" );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_a_player_cannot_review_join_requests(): void {
		$this->ask();
		$id = Join_Request::find_waiting( $this->game_id, $this->applicant )->id;
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );

		$this->assertSame( 403, $this->dispatch( $player, 'GET', "/be/v1/{$this->slug}/players/join-requests" )->get_status() );
		$this->assertSame( 403, $this->dispatch( $player, 'POST', "/be/v1/{$this->slug}/players/join-requests/{$id}/approve" )->get_status() );
	}
}
