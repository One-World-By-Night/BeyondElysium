<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Notifications;
use BeyondElysium\Core\User_Settings;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Mail_Log;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Who hears when a player sends changes that wait: a staff address or each HST and AST, each at the pace they chose.
 */
class StaffEmailThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-staff-email';
	private int $game_id;
	private int $character_id;
	private int $player_id;
	private int $hst_id;
	private int $ast_id;
	private int $narrator_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => 'Staff Email Chronicle',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$make = function ( string $role, string $wp_role, string $name ): int {
			$id = self::factory()->user->create( [ 'role' => $wp_role, 'user_email' => "{$name}@example.org", 'display_name' => ucfirst( $name ) ] );
			Game_Member::set_role( $this->game_id, $id, $role );
			return $id;
		};
		$this->player_id   = $make( 'player', 'subscriber', 'player' );
		$this->hst_id      = $make( 'hst', 'editor', 'hst' );
		$this->ast_id      = $make( 'ast', 'editor', 'ast' );
		$this->narrator_id = $make( 'narrator', 'editor', 'narrator' );

		$this->character_id = Character::create( [
			'name' => 'Isolde Marchetti', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'vampire-merits' => [] ],
		] );
		Character::update_xp( $this->character_id, 20, 20 );
		reset_phpmailer_instance();
	}

	private function game(): object {
		return Game::find_by_slug( $this->slug );
	}

	private function settings( array $settings ): void {
		Game::update( $this->slug, [ 'settings' => $settings ] );
	}

	/** @param string[] $names */
	private function send( array $names, ?string $note = null ) {
		wp_set_current_user( $this->player_id );
		$changes = [];
		foreach ( $names as $name ) {
			$changes[] = [
				'change_type' => 'add_trait',
				'category'    => 'vampire-merits',
				'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => $name ] ],
				'notes'       => $note,
			];
		}
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes/submit" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'changes' => $changes ] ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	/** @return array<int,array<string,mixed>> the mails sent whose subject holds the words */
	private function sent( string $subject_has = 'waiting' ): array {
		$mailer = tests_retrieve_phpmailer_instance();
		return array_values( array_filter(
			$mailer->mock_sent,
			static fn( $mail ): bool => stripos( (string) $mail['subject'], $subject_has ) !== false
		) );
	}

	/** @return string[] */
	private function recipients( string $subject_has = 'waiting' ): array {
		$to = [];
		foreach ( $this->sent( $subject_has ) as $mail ) {
			foreach ( $mail['to'] as $address ) {
				$to[] = $address[0];
			}
		}
		sort( $to );
		return $to;
	}

	// --- Each HST and AST, the default ------------------------------------------------------------------------

	public function test_with_no_address_and_no_choice_each_hst_and_ast_hears_at_once(): void {
		$this->send( [ 'Daredevil', 'Lucky' ] );

		$this->assertSame( [ 'ast@example.org', 'hst@example.org' ], $this->recipients() );
		$mail = $this->sent()[0];
		$this->assertSame( '[Beyond Elysium] Staff Email Chronicle: 2 changes from Isolde Marchetti are waiting', $mail['subject'] );
		$this->assertStringContainsString( 'Daredevil', $mail['body'] );
		$this->assertStringContainsString( 'Lucky', $mail['body'] );
		$this->assertStringContainsString( 'approval-queue', $mail['body'] );
	}

	public function test_the_players_note_is_in_the_email(): void {
		$this->send( [ 'Daredevil' ], 'Bought at the last game.' );

		$this->assertStringContainsString( 'Note from the player: Bought at the last game.', $this->sent()[0]['body'] );
	}

	public function test_a_narrator_and_the_sender_are_not_told(): void {
		$this->send( [ 'Daredevil' ] );

		$this->assertNotContains( 'narrator@example.org', $this->recipients() );
		$this->assertNotContains( 'player@example.org', $this->recipients() );
	}

	public function test_work_that_applies_whole_emails_no_one(): void {
		$this->settings( [ 'auto_approve' => true ] );

		$this->send( [ 'Common Sense' ] );

		$this->assertSame( [], $this->sent() );
	}

	// --- Each Storyteller's own choice ------------------------------------------------------------------------

	public function test_a_storyteller_who_chose_never_gets_nothing_and_the_other_still_does(): void {
		update_user_meta( $this->ast_id, User_Settings::WAITING_NOTIFY_META, 'never' );

		$this->send( [ 'Daredevil' ] );

		$this->assertSame( [ 'hst@example.org' ], $this->recipients() );
	}

	public function test_a_storyteller_who_chose_once_a_day_gets_it_in_the_digest_and_not_at_once(): void {
		update_user_meta( $this->hst_id, User_Settings::WAITING_NOTIFY_META, 'daily' );

		$this->send( [ 'Daredevil', 'Lucky' ] );
		$this->assertSame( [ 'ast@example.org' ], $this->recipients(), 'only the one who chose each submission is told now' );

		reset_phpmailer_instance();
		Notifications::send_daily_digests();

		$digest = $this->sent( 'Waiting in' );
		$this->assertCount( 1, $digest );
		$this->assertSame( 'hst@example.org', $digest[0]['to'][0][0] );
		$this->assertSame( '[Beyond Elysium] Waiting in Staff Email Chronicle: 2 changes from 1 characters', $digest[0]['subject'] );
		$this->assertStringContainsString( '- Isolde Marchetti: 2 changes', $digest[0]['body'] );
	}

	public function test_the_digest_is_not_sent_on_a_day_nothing_waits(): void {
		update_user_meta( $this->hst_id, User_Settings::WAITING_NOTIFY_META, 'daily' );

		Notifications::send_daily_digests();

		$this->assertSame( [], $this->sent( 'Waiting in' ) );
	}

	// --- A staff address ---------------------------------------------------------------------------------------

	public function test_a_staff_address_hears_at_each_submission_and_a_storyteller_with_no_choice_does_not(): void {
		$this->settings( [ 'staff_email' => 'staff@example.org', 'staff_email_frequency' => 'each' ] );

		$this->send( [ 'Daredevil' ] );

		$this->assertSame( [ 'staff@example.org' ], $this->recipients() );
		$this->assertStringContainsString( 'Staff Email Chronicle staff', $this->sent()[0]['body'] );
	}

	public function test_a_storyteller_who_chose_each_submission_hears_as_well_as_the_address(): void {
		$this->settings( [ 'staff_email' => 'staff@example.org', 'staff_email_frequency' => 'each' ] );
		update_user_meta( $this->hst_id, User_Settings::WAITING_NOTIFY_META, 'each' );

		$this->send( [ 'Daredevil' ] );

		$this->assertSame( [ 'hst@example.org', 'staff@example.org' ], $this->recipients() );
	}

	public function test_an_address_set_to_once_a_day_gets_the_digest_and_nothing_at_once(): void {
		$this->settings( [ 'staff_email' => 'staff@example.org', 'staff_email_frequency' => 'daily' ] );

		$this->send( [ 'Daredevil' ] );
		$this->assertSame( [], $this->sent() );

		Notifications::send_daily_digests();

		$this->assertSame( [ 'staff@example.org' ], $this->recipients( 'Waiting in' ) );
	}

	public function test_other_staff_mail_follows_the_staff_address(): void {
		$this->settings( [ 'staff_email' => 'staff@example.org', 'staff_email_frequency' => 'each' ] );
		$applicant = get_userdata( $this->player_id );

		Notifications::join_requested( $this->game(), $applicant, 'Please let me in.' );

		$this->assertSame( [ 'staff@example.org' ], $this->recipients( 'Request to join' ) );
	}

	public function test_other_staff_mail_goes_to_each_hst_and_ast_with_no_address(): void {
		$applicant = get_userdata( $this->player_id );

		Notifications::join_requested( $this->game(), $applicant, 'Please let me in.' );

		$this->assertSame( [ 'ast@example.org', 'hst@example.org' ], $this->recipients( 'Request to join' ) );
	}

	public function test_a_secret_told_follows_the_address_too(): void {
		$this->settings( [ 'staff_email' => 'staff@example.org' ] );

		Notifications::secret_told_staff( $this->game(), (object) [ 'id' => 1, 'title' => 'The Vault' ], 'Isolde', 'Corwin' );

		$this->assertSame( [ 'staff@example.org' ], $this->recipients( 'Secret told in' ) );
	}

	// --- What is held back, and the log ----------------------------------------------------------------------

	public function test_a_chronicle_with_notifications_off_sends_nothing_and_the_log_says_why(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_games', [ 'notifications_enabled' => 0 ], [ 'id' => $this->game_id ] );
		$this->settings( [ 'staff_email' => 'staff@example.org' ] );

		$this->send( [ 'Daredevil' ] );

		$this->assertSame( [], $this->sent() );
		$rows = Mail_Log::for_game( $this->game_id, [ 'kind' => 'changes_waiting' ] );
		$this->assertNotEmpty( $rows );
		$this->assertSame( Mail_Log::SKIPPED, $rows[0]->result );
		$this->assertSame( Mail_Log::REASON_CHRONICLE_OFF, $rows[0]->reason );
	}

	public function test_the_mail_log_records_each_email_sent(): void {
		$this->send( [ 'Daredevil' ] );

		$rows = Mail_Log::for_game( $this->game_id, [ 'kind' => 'changes_waiting' ] );
		$this->assertCount( 2, $rows );
		$this->assertSame( Mail_Log::SENT, $rows[0]->result );
	}

	public function test_a_storyteller_who_opted_out_of_email_is_skipped_and_logged(): void {
		update_user_meta( $this->ast_id, User_Settings::NOTIFICATIONS_OPT_OUT_META, '1' );

		$this->send( [ 'Daredevil' ] );

		$this->assertSame( [ 'hst@example.org' ], $this->recipients() );
		$skipped = array_filter( Mail_Log::for_game( $this->game_id, [ 'kind' => 'changes_waiting' ] ), static fn( $row ) => $row->result === Mail_Log::SKIPPED );
		$this->assertCount( 1, $skipped );
	}

	// --- The settings ----------------------------------------------------------------------------------------

	private function save_setup( array $params ) {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/chronicle-setup" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_chronicle_setup_saves_the_staff_address_and_pace_and_can_clear_them(): void {
		$saved = $this->save_setup( [ 'staff_email' => ' Staff@Example.org ', 'staff_email_frequency' => 'daily' ] );

		$this->assertSame( 200, $saved->get_status(), wp_json_encode( $saved->get_data() ) );
		$this->assertSame( 'Staff@Example.org', $this->game()->settings->staff_email );
		$this->assertSame( 'daily', $this->game()->settings->staff_email_frequency );

		$this->assertSame( 200, $this->save_setup( [ 'staff_email' => '' ] )->get_status() );
		$this->assertSame( '', $this->game()->settings->staff_email );
	}

	public function test_chronicle_setup_refuses_a_bad_address_or_pace(): void {
		$this->assertSame( 400, $this->save_setup( [ 'staff_email' => 'not an address' ] )->get_status() );
		$this->assertSame( 400, $this->save_setup( [ 'staff_email_frequency' => 'hourly' ] )->get_status() );
	}

	public function test_the_checklist_has_a_staff_email_row_that_reads_ok_once_an_address_is_set(): void {
		$row = fn() => array_values( array_filter(
			\BeyondElysium\Services\Setup_Status::rows( $this->game() ),
			static fn( $r ) => $r['id'] === 'staff_email'
		) )[0];

		$this->assertSame( 'info', $row()['status'] );
		$this->settings( [ 'staff_email' => 'staff@example.org' ] );
		$this->assertSame( 'ok', $row()['status'] );
	}

	private function bell( int $as ) {
		wp_set_current_user( $as );
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/notices" ) )->get_data();
	}

	public function test_a_storyteller_chooses_how_often_in_the_bell_and_a_player_has_nothing_to_choose(): void {
		$this->assertNull( $this->bell( $this->player_id )['settings'] );
		$this->assertSame( 'each', $this->bell( $this->hst_id )['settings']['effective'] );

		wp_set_current_user( $this->hst_id );
		$save = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/notices/settings" );
		$save->set_param( 'waiting_notify', 'daily' );
		$this->assertSame( 200, rest_get_server()->dispatch( $save )->get_status() );

		$this->assertSame( 'daily', $this->bell( $this->hst_id )['settings']['waiting_notify'] );
		$this->assertSame( 'daily', get_user_meta( $this->hst_id, User_Settings::WAITING_NOTIFY_META, true ) );
	}

	public function test_a_player_may_not_save_the_choice_and_a_bad_choice_is_refused(): void {
		wp_set_current_user( $this->player_id );
		$save = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/notices/settings" );
		$save->set_param( 'waiting_notify', 'each' );
		$this->assertSame( 403, rest_get_server()->dispatch( $save )->get_status() );

		wp_set_current_user( $this->hst_id );
		$bad = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/notices/settings" );
		$bad->set_param( 'waiting_notify', 'hourly' );
		$this->assertSame( 400, rest_get_server()->dispatch( $bad )->get_status() );
	}

	public function test_a_storyteller_with_an_address_set_reads_nothing_as_what_applies(): void {
		$this->settings( [ 'staff_email' => 'staff@example.org' ] );

		$settings = $this->bell( $this->hst_id )['settings'];

		$this->assertSame( 'never', $settings['effective'] );
		$this->assertTrue( $settings['staff_address'] );
	}
}
