<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Submission;
use BeyondElysium\Services\Character_Exporter;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Sending a Grapevine file through the real REST server: `preview`, `create`, `withdraw` and `/my/submissions`.
 */
class SubmissionsSendThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-submissions';
	private int $game_id;
	private array $mail = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Submissions',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$hst = self::factory()->user->create( [ 'role' => 'editor', 'user_email' => 'submissions-hst@example.test' ] );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $hst, 'hst' );

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

	/**
	 * Exports a real database character to a real temp file, in the exact multipart shape the REST server expects an
	 * upload in (ImportControllerThreadTest's own established pattern).
	 */
	private function upload_request( string $route, string $xml, array $params = [], string $filename = 'sheet.gex' ): WP_REST_Request {
		$tmp = tempnam( sys_get_temp_dir(), 'be-submission-test' );
		file_put_contents( $tmp, $xml );

		$request = new WP_REST_Request( 'POST', $route );
		$request->set_file_params( [
			'file' => [
				'tmp_name' => $tmp,
				'name'     => $filename,
				'error'    => 0,
				'size'     => strlen( $xml ),
				'type'     => 'application/octet-stream',
			],
		] );
		// WP_REST_Request::set_param() returns void.
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	private function post( string $route, array $params = [] ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * A real, exported vampire character's XML, ready to upload.
	 */
	private function real_export( array $overrides = [] ): string {
		$id = Character::create( array_merge( [
			'name' => 'Submission Test Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => 'thread-test-submissions-source',
			'status' => 'active',
		], $overrides ) );
		return Character_Exporter::export( $id )['xml'];
	}

	public function test_preview_reports_a_real_characters_name_and_stack_and_stores_nothing(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$xml = $this->real_export();

		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions/preview", $xml ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data['characters'] );
		$this->assertSame( 'Submission Test Vampire', $data['characters'][0]['name'] );
		$this->assertSame( 'vampire', $data['characters'][0]['stack_slug'] );
		$this->assertTrue( $data['characters'][0]['allowed'] );
		$this->assertSame( 0, Submission::count_waiting( $this->game_id ) );
	}

	public function test_a_subscriber_with_no_membership_can_send_a_file(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$xml = $this->real_export();

		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $xml, [ 'arrival' => 'joining' ] ) );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'waiting', $data['state'] );
		$this->assertSame( 'joining', $data['arrival'] );
		$this->assertSame( 'Thread Test Submissions', $data['game_name'] );
		$this->assertNotEmpty( $this->mail );
	}

	public function test_a_real_chronicle_member_can_also_send_a_file(): void {
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $user, 'player' );
		wp_set_current_user( $user );
		$xml = $this->real_export();

		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $xml, [
			'arrival' => 'visiting', 'home_chronicle' => 'Kings of New York',
		] ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'visiting', $response->get_data()['arrival'] );
	}

	public function test_the_stored_parsed_data_holds_only_the_chosen_character_with_no_uuid(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$xml = $this->real_export();

		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $xml, [ 'arrival' => 'joining' ] ) );
		$id  = (int) $response->get_data()['id'];
		$row = Submission::find_with_file( $id );

		$stored = json_decode( (string) $row->parsed, true );
		$this->assertCount( 1, $stored['characters'] );
		$this->assertArrayNotHasKey( 'uuid', $stored['characters'][0] );
		$this->assertSame( [], $stored['players'] );
		$this->assertSame( [], $stored['items'] );
		$this->assertSame( [], $stored['locations'] );
		$this->assertSame( [], $stored['rotes'] );
	}

	public function test_signed_out_is_refused(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions/preview", $this->real_export() ) );

		$this->assertGreaterThanOrEqual( 401, $response->get_status() );
	}

	public function test_a_gv3_file_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions/preview", "\x02\x00GVBG" ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unsupported_format', $response->as_error()->get_error_code() );
	}

	public function test_a_non_exchange_file_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions/preview", 'not a grapevine file' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_format', $response->as_error()->get_error_code() );
	}

	public function test_a_file_over_5mb_is_refused_before_it_is_read(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$request = $this->upload_request( "/be/v1/{$this->game_slug}/submissions/preview", 'x' );
		$files   = $request->get_file_params();
		$files['file']['size'] = 6 * MB_IN_BYTES;
		$request->set_file_params( $files );

		$response = $this->dispatch( $request );

		$this->assertSame( 413, $response->get_status() );
	}

	public function test_several_characters_without_an_index_asks_which_one(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$first  = $this->real_export( [ 'name' => 'First Character' ] );
		$second = $this->real_export( [ 'name' => 'Second Character' ] );
		preg_match( '/<vampire\b.*<\/vampire>/s', $first, $m1 );
		preg_match( '/<vampire\b.*<\/vampire>/s', $second, $m2 );
		$combined = str_replace( $m1[0], $m1[0] . "\n  " . $m2[0], $first );

		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $combined, [ 'arrival' => 'joining' ] ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'choose_character', $response->as_error()->get_error_code() );

		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $combined, [
			'arrival' => 'joining', 'character_index' => 1,
		] ) );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Second Character', $response->get_data()['character_name'] );
	}

	public function test_a_disallowed_creature_type_is_refused(): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'be_games',
			[ 'settings' => wp_json_encode( [ 'enabled_stacks' => [ 'werewolf' ] ] ) ],
			[ 'id' => $this->game_id ]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$xml = $this->real_export(); // a vampire

		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $xml, [ 'arrival' => 'joining' ] ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_allowed', $response->as_error()->get_error_code() );
	}

	public function test_a_second_waiting_file_from_the_same_sender_is_refused(): void {
		$user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user );

		$this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $this->real_export(), [ 'arrival' => 'joining' ] ) );
		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $this->real_export(), [ 'arrival' => 'joining' ] ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'already_waiting', $response->as_error()->get_error_code() );
	}

	public function test_a_pending_hand_built_join_character_blocks_a_submission_and_the_reverse(): void {
		$user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user );

		$this->dispatch( $this->post( "/be/v1/{$this->game_slug}/characters", [ 'name' => 'Hand Built', 'stack_slug' => 'vampire' ] ) );

		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $this->real_export(), [ 'arrival' => 'joining' ] ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'join_already_requested', $response->as_error()->get_error_code() );

		// The reverse: a second user's waiting file blocks their own hand-built join attempt.
		$other = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $other );
		$this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $this->real_export(), [ 'arrival' => 'joining' ] ) );

		$response = $this->dispatch( $this->post( "/be/v1/{$this->game_slug}/characters", [ 'name' => 'Hand Built Two', 'stack_slug' => 'vampire' ] ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'join_already_requested', $response->as_error()->get_error_code() );
	}

	public function test_the_51st_waiting_file_is_refused(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			Submission::create( [
				'game_id' => $this->game_id, 'submitted_by' => 1000 + $i, 'arrival' => 'joining',
				'character_name' => "Filler $i", 'stack_slug' => 'vampire', 'source_file' => 'x.gex',
				'format' => 'XML', 'file_hash' => hash( 'sha256', (string) $i ),
				'parsed' => wp_json_encode( [ 'characters' => [] ] ), 'verification_source' => null,
			] );
		}
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $this->real_export(), [ 'arrival' => 'joining' ] ) );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'too_many_waiting', $response->as_error()->get_error_code() );
	}

	public function test_withdraw_by_the_sender_succeeds_and_by_anyone_else_404s(): void {
		$sender = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $sender );
		$id = (int) $this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $this->real_export(), [ 'arrival' => 'joining' ] ) )->get_data()['id'];

		$other = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $other );
		$denied = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/submissions/{$id}/withdraw" ) );
		$this->assertSame( 404, $denied->get_status() );

		wp_set_current_user( $sender );
		$ok = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/submissions/{$id}/withdraw" ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'withdrawn', $ok->get_data()->state );

		$again = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/submissions/{$id}/withdraw" ) );
		$this->assertSame( 409, $again->get_status() );
	}

	public function test_my_submissions_lists_only_the_callers_own_rows_with_the_chronicle_name(): void {
		$user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user );
		$this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $this->real_export(), [ 'arrival' => 'joining' ] ) );

		$other = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $other );
		$this->dispatch( $this->upload_request( "/be/v1/{$this->game_slug}/submissions", $this->real_export(), [ 'arrival' => 'joining' ] ) );

		wp_set_current_user( $user );
		$response = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/my/submissions' ) );

		$this->assertSame( 200, $response->get_status() );
		$rows = $response->get_data();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Thread Test Submissions', $rows[0]->game_name );
		$this->assertSame( $this->game_slug, $rows[0]->game_slug );
	}

	public function test_my_submissions_route_is_not_shadowed_by_a_chronicle_literally_named_my(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'my', 'name' => 'A Chronicle Literally Named My',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$response = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/my/submissions' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}
}
