<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Mailer;
use BeyondElysium\Core\Notifications;
use BeyondElysium\Core\User_Settings;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Mail_Log;
use BeyondElysium\Models\Notification_Queue;
use BeyondElysium\Models\Plot;
use BeyondElysium\Services\Player_Invites;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The mail log: every email the plugin sends, fails to send, holds for a digest or decides not to send is recorded under
 * the chronicle it was about, readable by that chronicle's Storytellers and no one else, and kept for ninety days.
 */
class MailLogThreadTest extends WP_UnitTestCase {

	private object $game;
	private object $other;
	private array $captured = [];
	private bool $accepted = true;
	private int $sequence = 0;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game  = $this->make_game( 'Mail Log Game' );
		$this->other = $this->make_game( 'Other Mail Log Game' );

		$this->captured = [];
		$this->accepted = true;
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	/**
	 * @param mixed $pre_empty
	 * @param array $atts
	 * @return bool
	 */
	public function capture_mail( $pre_empty, $atts ) {
		$this->captured[] = $atts;
		return $this->accepted;
	}

	private function make_game( string $name, array $extra = [] ): object {
		$slug = 'thread-mail-log-' . wp_generate_password( 8, false );
		Game::create( array_merge( [ 'name' => $name, 'slug' => $slug ], $extra ) );
		return Game::find_by_slug( $slug );
	}

	private function make_member( object $game, string $role ): int {
		++$this->sequence;
		$user = self::factory()->user->create( [
			'role'         => $role === 'player' ? 'subscriber' : 'editor',
			'user_email'   => "mail-log-{$role}-{$this->sequence}@example.test",
			'display_name' => ucfirst( $role ) . " {$this->sequence}",
		] );
		Game_Member::set_role( (int) $game->id, $user, $role );
		return $user;
	}

	private function make_plot( object $game, string $title = 'A Plot', ?int $assigned_to = null ): int {
		$plot_id = (int) Plot::create( [ 'game_id' => $game->id, 'title' => $title, 'created_by' => 1, 'audience' => 'everyone' ] );
		if ( $assigned_to !== null ) {
			Plot::update( $plot_id, [ 'assigned_to' => $assigned_to ] );
		}
		return $plot_id;
	}

	private function post_action( object $game, int $plot_id, int $wp_user_id ): void {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$game->slug}/plots/{$plot_id}/entries" );
		$request->set_param( 'entry_type', 'action' );
		$request->set_param( 'content', 'I do a thing.' );
		rest_get_server()->dispatch( $request );
	}

	/**
	 * @return array<int,object>
	 */
	private function rows( ?object $game = null, array $filters = [] ): array {
		return Mail_Log::for_game( (int) ( $game ?? $this->game )->id, $filters, 100, 0 );
	}

	private function row_for( int $wp_user_id, ?object $game = null ): ?object {
		foreach ( $this->rows( $game ) as $row ) {
			if ( $row->wp_user_id === $wp_user_id ) {
				return $row;
			}
		}
		return null;
	}

	private function get( object $game, string $path = '', array $params = [] ) {
		$request = new WP_REST_Request( 'GET', "/be/v1/{$game->slug}/mail-log{$path}" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// What a row holds
	// -------------------------------------------------------------------------

	public function test_a_sent_email_is_recorded_with_who_what_and_the_result(): void {
		$user = self::factory()->user->create( [ 'user_email' => 'sam@example.test', 'display_name' => 'Sam Player' ] );

		$sent = Mailer::send( 'sam@example.test', 'A subject line', 'A body that is never stored.', [
			'game_id' => (int) $this->game->id, 'wp_user_id' => $user, 'kind' => 'plot_post', 'entity_type' => 'plot', 'entity_id' => 7,
		] );

		$this->assertTrue( $sent );
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'sent', $rows[0]->result );
		$this->assertSame( 'sam@example.test', $rows[0]->recipient_email );
		$this->assertSame( 'Sam Player', $rows[0]->recipient_name );
		$this->assertSame( $user, $rows[0]->wp_user_id );
		$this->assertSame( 'plot_post', $rows[0]->kind );
		$this->assertSame( 'A subject line', $rows[0]->subject );
		$this->assertSame( 'plot', $rows[0]->entity_type );
		$this->assertSame( 7, $rows[0]->entity_id );
		$this->assertStringNotContainsString( 'never stored', (string) wp_json_encode( $rows[0] ) );
	}

	public function test_a_refused_email_is_recorded_as_failed_with_the_mail_systems_message(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		add_filter( 'pre_wp_mail', static function () {
			do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', 'Could not connect to the mail host' ) );
			return false;
		} );

		$sent = Mailer::send( 'sam@example.test', 'Subject', 'Body', [ 'game_id' => (int) $this->game->id, 'kind' => 'plot_post' ] );

		$this->assertFalse( $sent );
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'failed', $rows[0]->result );
		$this->assertSame( 'host_refused', $rows[0]->reason );
		$this->assertStringContainsString( 'Could not connect to the mail host', $rows[0]->error );
	}

	public function test_an_email_of_a_kind_the_log_does_not_know_still_goes_and_writes_no_row(): void {
		$sent = Mailer::send( 'sam@example.test', 'Subject', 'Body', [ 'game_id' => (int) $this->game->id, 'kind' => 'nonsense' ] );

		$this->assertTrue( $sent );
		$this->assertCount( 1, $this->captured );
		$this->assertSame( [], $this->rows() );
	}

	// -------------------------------------------------------------------------
	// The plot post that started it
	// -------------------------------------------------------------------------

	public function test_an_unassigned_plot_post_is_recorded_for_every_staff_member_and_not_for_the_poster(): void {
		$hst      = $this->make_member( $this->game, 'hst' );
		$ast      = $this->make_member( $this->game, 'ast' );
		$narrator = $this->make_member( $this->game, 'narrator' );
		$poster   = $this->make_member( $this->game, 'player' );
		$plot_id  = $this->make_plot( $this->game, 'Raine Plot' );

		$this->post_action( $this->game, $plot_id, $poster );

		$this->assertCount( 3, $this->captured );
		$rows = $this->rows();
		$this->assertCount( 3, $rows );
		foreach ( [ $hst, $ast, $narrator ] as $staff ) {
			$row = $this->row_for( $staff );
			$this->assertNotNull( $row, "staff member {$staff} has a row" );
			$this->assertSame( 'sent', $row->result );
			$this->assertSame( 'plot_post', $row->kind );
			$this->assertSame( 'plot', $row->entity_type );
			$this->assertSame( $plot_id, $row->entity_id );
			$this->assertStringContainsString( 'Raine Plot', $row->subject );
		}
		$this->assertNull( $this->row_for( $poster ), 'the poster is not emailed about their own post' );
	}

	public function test_staff_left_out_of_a_plot_post_are_recorded_with_the_reason(): void {
		$opted_out = $this->make_member( $this->game, 'ast' );
		$no_plots  = $this->make_member( $this->game, 'ast' );
		$digest    = $this->make_member( $this->game, 'ast' );
		$normal    = $this->make_member( $this->game, 'hst' );
		update_user_meta( $opted_out, User_Settings::NOTIFICATIONS_OPT_OUT_META, '1' );
		update_user_meta( $no_plots, User_Settings::PLOT_NOTIFY_META, 'off' );
		update_user_meta( $digest, User_Settings::PLOT_NOTIFY_META, 'daily' );
		$poster  = $this->make_member( $this->game, 'player' );
		$plot_id = $this->make_plot( $this->game );

		$this->post_action( $this->game, $plot_id, $poster );

		$this->assertSame( [ 'skipped', 'opted_out' ], [ $this->row_for( $opted_out )->result, $this->row_for( $opted_out )->reason ] );
		$this->assertSame( [ 'skipped', 'preference_off' ], [ $this->row_for( $no_plots )->result, $this->row_for( $no_plots )->reason ] );
		$this->assertSame( [ 'queued', 'daily_digest' ], [ $this->row_for( $digest )->result, $this->row_for( $digest )->reason ] );
		$this->assertSame( 'sent', $this->row_for( $normal )->result );
		$this->assertCount( 1, $this->captured, 'only the one staff member with nothing in the way is emailed' );
	}

	public function test_a_queued_post_is_sent_in_a_digest_that_the_log_records_too(): void {
		$ast = $this->make_member( $this->game, 'ast' );
		update_user_meta( $ast, User_Settings::PLOT_NOTIFY_META, 'daily' );
		$poster  = $this->make_member( $this->game, 'player' );
		$plot_id = $this->make_plot( $this->game, 'A Plot', $ast );
		$this->post_action( $this->game, $plot_id, $poster );

		Notifications::send_daily_digests();

		$rows = $this->rows( null, [ 'wp_user_id' => $ast ] );
		$this->assertSame( [ 'digest', 'queued' ], [ $rows[0]->kind, $rows[1]->result === 'queued' ? 'queued' : '' ] );
		$this->assertSame( 'sent', $rows[0]->result );
		$this->assertSame( 'plot_post', $rows[1]->kind );
		$this->assertSame( [], Notification_Queue::for_user( $ast ) );
	}

	public function test_a_digest_covering_two_chronicles_is_recorded_once_under_each(): void {
		$user = self::factory()->user->create( [ 'user_email' => 'both@example.test' ] );
		Notification_Queue::create( $user, (int) $this->game->id, 'plot_post', [ 'game_name' => 'One', 'plot_title' => 'First', 'posted_by' => 'A', 'link' => 'x' ] );
		Notification_Queue::create( $user, (int) $this->other->id, 'plot_post', [ 'game_name' => 'Two', 'plot_title' => 'Second', 'posted_by' => 'B', 'link' => 'y' ] );

		Notifications::send_daily_digests();

		$this->assertCount( 1, $this->captured, 'one email' );
		$this->assertCount( 1, $this->rows( $this->game ) );
		$this->assertCount( 1, $this->rows( $this->other ) );
		$this->assertSame( 'digest', $this->rows( $this->other )[0]->kind );
	}

	// -------------------------------------------------------------------------
	// Every call site
	// -------------------------------------------------------------------------

	/**
	 * @return array<string,array{0:string,1:string,2:int}> Case => kind, entity type, entity id.
	 */
	public static function each_kind(): array {
		return [
			'release'             => [ 'release', 'release_batch', 41 ],
			'change outcome'      => [ 'change_outcome', '', 0 ],
			'visible'             => [ 'visible', '', 0 ],
			'join requested'      => [ 'join_requested', '', 0 ],
			'join requested old'  => [ 'join_requested', 'character', 0 ],
			'join answered'       => [ 'join_answered', 'join_request', 51 ],
			'secret told'         => [ 'secret_told', 'secret', 61 ],
			'submission received' => [ 'submission_received', 'submission', 71 ],
			'submission answered' => [ 'submission_answered', 'submission', 71 ],
			'transfer offered'    => [ 'transfer_offered', 'transfer', 81 ],
			'invite'              => [ 'invite', '', 0 ],
		];
	}

	/**
	 * @dataProvider each_kind
	 */
	public function test_every_kind_of_email_is_recorded_with_what_it_was_about( string $kind, string $entity_type, int $entity_id ): void {
		$hst    = $this->make_member( $this->game, 'hst' );
		$player = $this->make_member( $this->game, 'player' );
		$user   = get_userdata( $player );
		$cid    = (int) Character::create( [ 'name' => 'Logged', 'stack_slug' => 'vampire', 'owner_slug' => $this->game->slug, 'wp_user_id' => $player, 'created_by' => 1 ] );

		$label = $kind . '|' . $entity_type;
		switch ( $label ) {
			case 'release|release_batch':
				Notifications::enqueue_release( $player, $this->game, 41, [ 'Logged' ], 'rumor' );
				Notifications::flush_release();
				break;
			case 'change_outcome|':
				Notifications::enqueue( (object) [ 'status' => 'approved', 'category' => 'abilities', 'change_type' => 'add_trait', 'change_data' => [] ], Character::find( $cid ), $hst );
				Notifications::flush();
				break;
			case 'visible|':
				Notifications::notify_visible( $player, $this->game, 'plot', 'A thing', 'https://example.test/x' );
				Notifications::flush_visible();
				break;
			case 'join_requested|':
				Notifications::join_requested( $this->game, $user, 'Let me in.' );
				break;
			case 'join_requested|character':
				$entity_id = $cid;
				Notifications::join_requested_legacy( $this->game, Character::find( $cid ), $user );
				break;
			case 'join_answered|join_request':
				Notifications::join_answered( $this->game, (object) [ 'id' => 51, 'wp_user_id' => $player ], true, null );
				break;
			case 'secret_told|secret':
				Notifications::secret_told_staff( $this->game, (object) [ 'id' => 61, 'title' => 'Hidden' ], 'Teller', 'Hearer' );
				break;
			case 'submission_received|submission':
				Notifications::submission_received( $this->game, (object) [ 'id' => 71, 'character_name' => 'Newcomer', 'stack_slug' => 'vampire', 'arrival' => 'joining' ], $user );
				break;
			case 'submission_answered|submission':
				Notifications::submission_answered( $this->game, (object) [ 'id' => 71, 'submitted_by' => $player, 'character_name' => 'Newcomer', 'state' => 'accepted' ] );
				break;
			case 'transfer_offered|transfer':
				Notifications::transfer_offered( $this->game, (object) [ 'id' => 81, 'character_name' => 'Traveler', 'home_chronicle' => 'Home', 'home_site' => 'home.example.test' ] );
				break;
			case 'invite|':
				$this->assertTrue( Player_Invites::send_invitation( $this->game, 'Newcomer@Example.test', $hst ) );
				break;
		}

		$rows = array_values( array_filter( $this->rows(), static fn( $row ) => $row->kind === $kind ) );
		$this->assertNotEmpty( $rows, "a {$kind} email is recorded" );
		$this->assertSame( 'sent', $rows[0]->result );
		$this->assertSame( $entity_type, $rows[0]->entity_type );
		$this->assertSame( $entity_id, $rows[0]->entity_id );
		if ( $kind === 'invite' ) {
			$this->assertSame( 'newcomer@example.test', $rows[0]->recipient_email );
			$this->assertSame( 0, $rows[0]->wp_user_id );
		}
	}

	public function test_a_demo_chronicle_records_each_email_as_not_sent_because_it_is_a_demo(): void {
		$demo = $this->make_game( 'Demo Mail Log', [ 'settings' => [ 'demo' => [ 'on' => true, 'reset_hours' => 6 ] ] ] );
		$hst  = $this->make_member( $demo, 'hst' );
		$applicant = get_userdata( $this->make_member( $demo, 'player' ) );

		Notifications::join_requested( $demo, $applicant, 'Let me in.' );
		$this->assertFalse( Player_Invites::send_invitation( $demo, 'new@example.test', $hst ) );

		$this->assertSame( [], $this->captured, 'a demo chronicle sends nothing' );
		$rows = $this->rows( $demo );
		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'skipped', $row->result );
			$this->assertSame( 'demo', $row->reason );
		}
	}

	public function test_a_chronicle_with_email_switched_off_records_each_email_as_not_sent(): void {
		$quiet = $this->make_game( 'Quiet Mail Log', [ 'notifications_enabled' => 0 ] );
		$hst   = $this->make_member( $quiet, 'hst' );
		$applicant = get_userdata( $this->make_member( $quiet, 'player' ) );

		Notifications::join_requested( $quiet, $applicant, 'Let me in.' );

		$this->assertSame( [], $this->captured );
		$this->assertSame( [ 'skipped', 'chronicle_off' ], [ $this->row_for( $hst, $quiet )->result, $this->row_for( $hst, $quiet )->reason ] );
	}

	public function test_a_staff_member_with_no_email_address_is_recorded_as_not_sent(): void {
		$hst = $this->make_member( $this->game, 'hst' );
		global $wpdb;
		$wpdb->update( $wpdb->users, [ 'user_email' => '' ], [ 'ID' => $hst ] );
		clean_user_cache( $hst );
		$applicant = get_userdata( $this->make_member( $this->game, 'player' ) );

		Notifications::join_requested( $this->game, $applicant, 'Let me in.' );

		$this->assertSame( [], $this->captured );
		$this->assertSame( [ 'skipped', 'no_email' ], [ $this->row_for( $hst )->result, $this->row_for( $hst )->reason ] );
	}

	// -------------------------------------------------------------------------
	// Reading it
	// -------------------------------------------------------------------------

	public function test_a_chronicles_staff_read_its_log_and_nobody_elses(): void {
		$hst       = $this->make_member( $this->game, 'hst' );
		$other_hst = $this->make_member( $this->other, 'hst' );
		Mailer::send( 'a@example.test', 'Ours', 'x', [ 'game_id' => (int) $this->game->id, 'kind' => 'plot_post' ] );
		Mailer::send( 'b@example.test', 'Theirs', 'x', [ 'game_id' => (int) $this->other->id, 'kind' => 'plot_post' ] );

		wp_set_current_user( $hst );
		$response = $this->get( $this->game );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'Ours' ], array_column( $response->get_data(), 'subject' ) );
		$this->assertSame( 403, $this->get( $this->other )->get_status(), 'not another chronicle\'s log' );

		wp_set_current_user( $other_hst );
		$this->assertSame( [ 'Theirs' ], array_column( $this->get( $this->other )->get_data(), 'subject' ) );
		$this->assertSame( 403, $this->get( $this->game )->get_status() );
	}

	public function test_a_player_cannot_read_the_log(): void {
		$player = $this->make_member( $this->game, 'player' );
		Mailer::send( 'a@example.test', 'Ours', 'x', [ 'game_id' => (int) $this->game->id, 'kind' => 'plot_post' ] );

		wp_set_current_user( $player );
		$this->assertSame( 403, $this->get( $this->game )->get_status() );
		$this->assertSame( 403, $this->get( $this->game, '/options' )->get_status() );

		wp_set_current_user( 0 );
		$this->assertContains( $this->get( $this->game )->get_status(), [ 401, 403 ] );
	}

	public function test_the_route_filters_pages_and_labels_what_it_returns(): void {
		$hst    = $this->make_member( $this->game, 'hst' );
		$plot   = $this->make_plot( $this->game, 'The Long Night' );
		$sender = static fn( string $to, string $subject, string $kind, array $extra = [] ) => Mailer::send( $to, $subject, 'x', array_merge( [ 'game_id' => (int) $GLOBALS['be_mail_log_game'], 'kind' => $kind ], $extra ) );
		$GLOBALS['be_mail_log_game'] = $this->game->id;
		$sender( 'ann@example.test', 'Plot one', 'plot_post', [ 'name' => 'Ann Able', 'entity_type' => 'plot', 'entity_id' => $plot ] );
		$sender( 'bo@example.test', 'Plot two', 'plot_post', [ 'name' => 'Bo Baker', 'entity_type' => 'plot', 'entity_id' => $plot + 1 ] );
		$sender( 'cy@example.test', 'A digest', 'digest', [ 'name' => 'Cy Cook' ] );
		Mailer::skipped( [ 'game_id' => (int) $this->game->id, 'kind' => 'plot_post', 'name' => 'Di Dunn', 'email' => 'di@example.test' ], 'opted_out' );
		unset( $GLOBALS['be_mail_log_game'] );

		wp_set_current_user( $hst );
		$all = $this->get( $this->game );
		$this->assertSame( '4', $all->get_headers()['X-WP-Total'] );
		$this->assertSame( 'The Long Night', array_column( $all->get_data(), 'entity_label', 'subject' )['Plot one'] );
		$this->assertSame( 'Plot post', $all->get_data()[ array_search( 'Plot one', array_column( $all->get_data(), 'subject' ), true ) ]['kind_label'] );

		$this->assertCount( 2, $this->get( $this->game, '', [ 'kind' => 'plot_post', 'result' => 'sent' ] )->get_data() );
		$this->assertCount( 1, $this->get( $this->game, '', [ 'result' => 'skipped' ] )->get_data() );
		$this->assertSame( 'They turned off email from Beyond Elysium', $this->get( $this->game, '', [ 'result' => 'skipped' ] )->get_data()[0]['reason_label'] );
		$this->assertCount( 1, $this->get( $this->game, '', [ 'search' => 'bo@example' ] )->get_data() );
		$this->assertCount( 1, $this->get( $this->game, '', [ 'search' => 'Cy Cook' ] )->get_data() );
		$this->assertCount( 1, $this->get( $this->game, '', [ 'entity_type' => 'plot', 'entity_id' => $plot ] )->get_data() );

		$page = $this->get( $this->game, '', [ 'per_page' => 3, 'page' => 2 ] );
		$this->assertCount( 1, $page->get_data() );
		$this->assertSame( '4', $page->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $page->get_headers()['X-WP-TotalPages'] );
	}

	public function test_the_options_route_lists_the_kinds_results_periods_and_how_long_rows_are_kept(): void {
		wp_set_current_user( $this->make_member( $this->game, 'hst' ) );

		$data = $this->get( $this->game, '/options' )->get_data();

		$this->assertContains( 'plot_post', array_column( $data['kinds'], 'key' ) );
		$this->assertSame( [ 'sent', 'failed', 'skipped', 'queued' ], array_column( $data['results'], 'key' ) );
		$this->assertSame( [ 'day', 'week', 'month', 'all' ], array_column( $data['periods'], 'key' ) );
		$this->assertSame( 90, $data['retention_days'] );
	}

	// -------------------------------------------------------------------------
	// Keeping it
	// -------------------------------------------------------------------------

	public function test_rows_past_ninety_days_are_pruned_and_newer_ones_kept(): void {
		$old   = (int) Mail_Log::record( [ 'game_id' => (int) $this->game->id, 'kind' => 'plot_post', 'result' => 'sent', 'created_at' => wp_date( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ) ] );
		$young = (int) Mail_Log::record( [ 'game_id' => (int) $this->game->id, 'kind' => 'plot_post', 'result' => 'sent', 'created_at' => wp_date( 'Y-m-d H:i:s', time() - 89 * DAY_IN_SECONDS ) ] );

		Mail_Log::prune();

		$ids = array_column( $this->rows(), 'id' );
		$this->assertContains( $young, $ids );
		$this->assertNotContains( $old, $ids );
	}

	public function test_the_daily_maintenance_prunes_the_log(): void {
		Mail_Log::record( [ 'game_id' => (int) $this->game->id, 'kind' => 'plot_post', 'result' => 'sent', 'created_at' => wp_date( 'Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS ) ] );

		\BeyondElysium\Core\Maintenance::run();

		$this->assertSame( [], $this->rows() );
	}

	public function test_deleting_a_chronicle_deletes_its_log_and_leaves_the_others(): void {
		Mailer::send( 'a@example.test', 'Ours', 'x', [ 'game_id' => (int) $this->game->id, 'kind' => 'plot_post' ] );
		Mailer::send( 'b@example.test', 'Theirs', 'x', [ 'game_id' => (int) $this->other->id, 'kind' => 'plot_post' ] );

		Game::delete_with_content( $this->game->slug );

		$this->assertSame( [], $this->rows() );
		$this->assertCount( 1, $this->rows( $this->other ) );
	}

	public function test_the_log_table_holds_no_message_body(): void {
		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $wpdb->prefix . 'be_mail_log' );

		$this->assertNotContains( 'body', $columns );
		$this->assertNotContains( 'message', $columns );
		$this->assertNotContains( 'content', $columns );
	}
}
