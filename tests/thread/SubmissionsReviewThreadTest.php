<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Submission;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Character_Exporter;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Listing, reviewing, verifying, accepting and refusing a waiting submission through the real REST server.
 */
class SubmissionsReviewThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-submissions-review';
	private int $game_id;
	private int $hst;
	private int $sender;
	private array $mail = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Submissions Review',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->hst = self::factory()->user->create( [ 'role' => 'editor', 'user_email' => 'review-hst@example.test' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		$this->sender = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'review-sender@example.test' ] );

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

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function post( string $route, array $params = [] ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * A waiting submission, created for real through the send-side REST route as $this->sender.
	 */
	private function real_waiting_submission( array $overrides = [] ): int {
		$src_id = Character::create( array_merge( [
			'name' => 'Review Test Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => 'thread-test-submissions-review-source',
			'status' => 'active',
		], $overrides ) );
		$xml = Character_Exporter::export( $src_id )['xml'];

		wp_set_current_user( $this->sender );
		$tmp = tempnam( sys_get_temp_dir(), 'be-submission-review-test' );
		file_put_contents( $tmp, $xml );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/submissions" );
		$request->set_file_params( [
			'file' => [ 'tmp_name' => $tmp, 'name' => 'sheet.gex', 'error' => 0, 'size' => strlen( $xml ), 'type' => 'application/octet-stream' ],
		] );
		$request->set_param( 'arrival', 'joining' );
		$response = $this->dispatch( $request );
		return (int) $response->get_data()['id'];
	}

	public function test_a_player_cannot_list_review_accept_or_refuse(): void {
		$id = $this->real_waiting_submission();
		wp_set_current_user( $this->sender );

		$this->assertSame( 403, $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/submissions" ) )->get_status() );
		$this->assertSame( 403, $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/submissions/{$id}/review" ) )->get_status() );
		$this->assertSame( 403, $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept" ) )->get_status() );
		$this->assertSame( 403, $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/refuse" ) )->get_status() );
	}

	public function test_another_chronicles_id_404s(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'thread-test-submissions-review-other', 'name' => 'Other',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );
		$id = $this->real_waiting_submission();

		// An administrator, not $this->hst.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/thread-test-submissions-review-other/submissions/{$id}/review" ) );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_review_shows_the_preview_and_the_sender(): void {
		$id = $this->real_waiting_submission();
		wp_set_current_user( $this->hst );

		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/submissions/{$id}/review" ) );
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'review-sender@example.test', $data['submission']['sender_email'] );
		$this->assertSame( 'Thread Test Submissions Review', $data['submission']['game_name'] );
		$this->assertSame( 1, $data['preview']['counts']['characters'] );
		$this->assertArrayHasKey( 'duplicates', $data['preview'] );
	}

	public function test_review_lists_it_in_the_waiting_list(): void {
		$this->real_waiting_submission();
		wp_set_current_user( $this->hst );

		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/submissions" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
	}

	public function test_accept_joining_makes_a_real_active_character_owned_by_the_sender(): void {
		$id = $this->real_waiting_submission();
		wp_set_current_user( $this->hst );

		$response = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept" ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$data      = $response->get_data();
		$character = Character::find( (int) $data['character']['id'] );
		$this->assertSame( $this->sender, (int) $character->wp_user_id );
		$this->assertSame( 'active', $character->status );
		$this->assertSame( 0, (int) $character->is_npc );
		$this->assertNull( $character->narrator );
		$this->assertNotNull( Character::plot_id( (int) $character->id ) );

		$this->assertTrue( Game_Member::find( $this->game_id, $this->sender ) !== null );

		$row = Submission::find_with_file( $id );
		$this->assertSame( 'accepted', $row->state );
		$this->assertNull( $row->parsed );
		$this->assertNull( $row->verification_source );

		$mailed_sender = array_filter( $this->mail, fn( $m ) => $m['to'] === 'review-sender@example.test' );
		$this->assertNotEmpty( $mailed_sender );
	}

	public function test_accept_records_the_sender_in_the_import_note(): void {
		$id = $this->real_waiting_submission();
		wp_set_current_user( $this->hst );
		$response = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept" ) );
		$character_id = (int) $response->get_data()['character']['id'];

		global $wpdb;
		$change = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . $wpdb->prefix . "be_character_changes WHERE character_id = %d AND change_type = 'import_note'",
			$character_id
		) );
		$this->assertNotNull( $change );
		$change_data = json_decode( $change->change_data, true );
		$this->assertSame( $this->sender, (int) $change_data['submitted_by'] );
		$this->assertStringContainsString( 'Sent in by', $change->notes );
	}

	public function test_accept_visiting_creates_a_real_visit_record(): void {
		$id = $this->real_waiting_submission();
		wp_set_current_user( $this->hst );

		$response = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept", [ 'arrival' => 'visiting' ] ) );
		$this->assertSame( 200, $response->get_status() );

		$character = Character::find( (int) $response->get_data()['character']['id'] );
		$transfer  = Transfer::find_open( $character->uuid, 'inbound' );
		$this->assertNotNull( $transfer );
		$this->assertSame( 'visiting', $transfer->state );
		$this->assertNotNull( $transfer->acknowledged_at );

		$badges = Transfer::open_states_for_game( $this->game_slug );
		$this->assertArrayHasKey( $character->uuid, $badges );
		$this->assertSame( 'visiting', $badges[ $character->uuid ]['state'] );
	}

	public function test_the_storytellers_arrival_choice_overrides_the_senders_own(): void {
		$id = $this->real_waiting_submission(); // sent as 'joining'
		wp_set_current_user( $this->hst );

		$response = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept", [ 'arrival' => 'visiting' ] ) );
		$this->assertSame( 'visiting', Submission::find( $id )->arrival );
	}

	/**
	 * create_item() already strips a file's uuid before storing it.
	 */
	public function test_a_uuid_in_the_file_can_never_overwrite_the_character_it_names(): void {
		$elsewhere_id = Character::create( [
			'name' => 'Someone Elses Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => 'thread-test-submissions-review-elsewhere',
			'status' => 'active',
		] );
		$elsewhere = Character::find( $elsewhere_id );

		$id  = $this->real_waiting_submission();
		$row = Submission::find_with_file( $id );
		$stored = json_decode( (string) $row->parsed, true );
		$stored['characters'][0]['uuid'] = $elsewhere->uuid;
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_character_submissions', [ 'parsed' => wp_json_encode( $stored ) ], [ 'id' => $id ] );

		wp_set_current_user( $this->hst );

		$blocked = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept" ) );
		$this->assertSame( 409, $blocked->get_status() );
		$this->assertSame( 'unresolved_duplicates', $blocked->as_error()->get_error_code() );

		// A hand-crafted overwrite attempt against another chronicle's character is refused outright.
		$overwrite_attempt = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Review Test Vampire' => 'overwrite' ] ],
		] ) );
		$this->assertSame( 409, $overwrite_attempt->get_status() );
		$this->assertSame( 'character_in_another_chronicle', $overwrite_attempt->as_error()->get_error_code() );

		// import_as_new succeeds as a genuinely separate character, uuid not inherited.
		$response = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Review Test Vampire' => 'import_as_new' ] ],
		] ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$new_character = Character::find( (int) $response->get_data()['character']['id'] );
		$this->assertNotSame( $elsewhere_id, (int) $new_character->id );
		$this->assertNotSame( $elsewhere->uuid, $new_character->uuid );
		$this->assertSame( 'Someone Elses Character', Character::find( $elsewhere_id )->name );
	}

	public function test_overwriting_the_senders_own_character_works(): void {
		$existing_id = Character::create( [
			'name' => 'Review Test Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'status' => 'active', 'wp_user_id' => $this->sender,
		] );

		$id = $this->real_waiting_submission();
		wp_set_current_user( $this->hst );
		$response = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Review Test Vampire' => 'overwrite' ] ],
		] ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $existing_id, (int) $response->get_data()['character']['id'] );
	}

	public function test_overwriting_another_players_character_is_refused(): void {
		$other_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Character::create( [
			'name' => 'Review Test Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'status' => 'active', 'wp_user_id' => $other_player,
		] );

		$id = $this->real_waiting_submission();
		wp_set_current_user( $this->hst );
		$response = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Review Test Vampire' => 'overwrite' ] ],
		] ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'overwrite_not_allowed', $response->as_error()->get_error_code() );
	}

	public function test_skipping_the_duplicate_is_refused_as_nothing_to_accept(): void {
		Character::create( [
			'name' => 'Review Test Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'status' => 'active', 'wp_user_id' => $this->sender,
		] );

		$id = $this->real_waiting_submission();
		wp_set_current_user( $this->hst );
		$response = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Review Test Vampire' => 'skip' ] ],
		] ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'nothing_to_accept', $response->as_error()->get_error_code() );
	}

	public function test_a_second_accept_of_the_same_submission_is_refused(): void {
		$id = $this->real_waiting_submission();
		wp_set_current_user( $this->hst );
		$this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept" ) );

		$response = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept" ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'submission_already_answered', $response->as_error()->get_error_code() );
	}

	public function test_a_restriction_added_after_sending_blocks_accept(): void {
		$id = $this->real_waiting_submission(); // a vampire
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'be_games',
			[ 'settings' => wp_json_encode( [ 'enabled_stacks' => [ 'werewolf' ] ] ) ],
			[ 'id' => $this->game_id ]
		);

		wp_set_current_user( $this->hst );
		$response = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/accept" ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'not_allowed', $response->as_error()->get_error_code() );
	}

	public function test_refuse_stores_the_note_and_emails_the_sender(): void {
		$id = $this->real_waiting_submission();
		wp_set_current_user( $this->hst );

		$response = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/submissions/{$id}/refuse", [
			'note' => 'This chronicle only takes hand-built sheets right now.',
		] ) );

		$this->assertSame( 200, $response->get_status() );
		$row = Submission::find_with_file( $id );
		$this->assertSame( 'refused', $row->state );
		$this->assertSame( 'This chronicle only takes hand-built sheets right now.', $row->answer_note );
		$this->assertNull( $row->parsed );

		$mailed_sender = array_filter( $this->mail, fn( $m ) => $m['to'] === 'review-sender@example.test' );
		$this->assertNotEmpty( $mailed_sender );
	}

	public function test_verification_reports_none_for_an_unverified_file(): void {
		$id = $this->real_waiting_submission();
		wp_set_current_user( $this->hst );

		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/submissions/{$id}/verification" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'none', $response->get_data()['status'] );
	}
}
