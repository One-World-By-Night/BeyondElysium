<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\User_Settings;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Notice;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Downtime_Reminders;
use WP_UnitTestCase;

/**
 * The daily run tells a player once, the day before downtime closes, that a character of theirs has sent nothing.
 */
class DowntimeReminderThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-downtime-reminder';
	private int $game_id;
	private int $hst;
	private int $player;
	private int $isolde;
	/** @var array<int,array<string,mixed>> */
	private array $mail = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Reminder Night' ] );
		$this->hst     = $this->member( 'hst', 'editor' );
		$this->player  = $this->member( 'player' );
		$this->isolde  = $this->character( 'Isolde Marchetti', $this->player );

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

	private function member( string $role, string $wp_role = 'subscriber' ): int {
		$user = self::factory()->user->create( [ 'role' => $wp_role ] );
		Game_Member::set_role( $this->game_id, $user, $role );
		return $user;
	}

	private function character( string $name, int $owner, array $extra = [] ): int {
		return (int) Character::create( array_merge( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_slug' => $this->slug,
			'wp_user_id' => $owner, 'status' => 'active', 'created_by' => 1,
		], $extra ) );
	}

	private function stamp( string $modifier ): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( $modifier ) );
	}

	private function session( string $closes, array $extra = [] ): int {
		return (int) Game_Session::create( array_merge( [
			'game_id' => $this->game_id, 'created_by' => $this->hst,
			'game_date' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
			'downtime_opens_at' => $this->stamp( '-2 days' ), 'downtime_deadline_at' => $this->stamp( $closes ),
		], $extra ) );
	}

	/**
	 * @return object[]
	 */
	private function reminders( int $user ): array {
		return Notice::of_kind( $this->game_id, $user, 'downtime_due' );
	}

	public function test_a_character_that_sent_nothing_is_reminded_by_notice_and_email(): void {
		$session = $this->session( '+30 hours' );

		$this->assertSame( 1, Downtime_Reminders::run() );

		$notices = $this->reminders( $this->player );
		$this->assertCount( 1, $notices );
		$this->assertSame( $session, $notices[0]->payload['session_id'] );
		$this->assertSame( $this->isolde, $notices[0]->payload['character_id'] );
		$this->assertSame( 'Isolde Marchetti', $notices[0]->payload['character_name'] );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( get_userdata( $this->player )->user_email, $this->mail[0]['to'] );
		$this->assertStringContainsString( 'Reminder Night', $this->mail[0]['subject'] );
		$this->assertStringContainsString( "Isolde Marchetti hasn't sent any actions", $this->mail[0]['message'] );
	}

	public function test_a_second_run_reminds_no_one_again(): void {
		$this->session( '+30 hours' );

		Downtime_Reminders::run();
		$this->assertSame( 0, Downtime_Reminders::run() );

		$this->assertCount( 1, $this->reminders( $this->player ) );
		$this->assertCount( 1, $this->mail );
	}

	public function test_each_game_night_is_reminded_once_on_its_own(): void {
		$this->session( '+30 hours' );
		$later = $this->session( '+40 hours', [ 'game_date' => gmdate( 'Y-m-d', strtotime( '+12 days' ) ) ] );

		$this->assertSame( 2, Downtime_Reminders::run() );

		$sessions = array_column( array_column( $this->reminders( $this->player ), 'payload' ), 'session_id' );
		$this->assertContains( $later, $sessions );
		$this->assertCount( 2, $sessions );
	}

	public function test_a_deadline_further_off_than_two_days_waits(): void {
		$this->session( '+4 days' );

		$this->assertSame( 0, Downtime_Reminders::run() );
		$this->assertSame( [], $this->reminders( $this->player ) );
	}

	public function test_a_character_that_sent_actions_is_not_reminded(): void {
		$session = $this->session( '+30 hours' );
		$plot    = (int) Action_Allocator::create_own_plot( Character::find( $this->isolde ), Game_Session::find( $session )->game_date );
		Plot_Entry::create( [ 'plot_id' => $plot, 'entry_type' => 'action', 'content' => 'I act.', 'author_id' => $this->player ] );

		$this->assertSame( 0, Downtime_Reminders::run() );
	}

	public function test_a_closed_window_a_past_night_and_a_night_with_no_window_are_left_alone(): void {
		$this->session( '-1 day' );
		$this->session( '+30 hours', [ 'game_date' => gmdate( 'Y-m-d', strtotime( '-3 days' ) ) ] );
		Game_Session::create( [ 'game_id' => $this->game_id, 'created_by' => $this->hst, 'game_date' => gmdate( 'Y-m-d', strtotime( '+9 days' ) ) ] );

		$this->assertSame( 0, Downtime_Reminders::run() );
	}

	public function test_an_extension_is_reminded_against_its_own_deadline(): void {
		$this->session( '+10 days', [ 'downtime_extensions' => [ (string) $this->isolde => $this->stamp( '+20 hours' ) ] ] );
		$other = $this->character( 'No Extension', $this->member( 'player' ) );

		$this->assertSame( 1, Downtime_Reminders::run() );

		$this->assertCount( 1, $this->reminders( $this->player ) );
		$this->assertSame( [], $this->reminders( (int) Character::find( $other )->wp_user_id ) );
	}

	public function test_an_extension_that_has_passed_is_not_reminded(): void {
		$this->session( '+30 hours', [ 'downtime_extensions' => [ (string) $this->isolde => $this->stamp( '-1 hour' ) ] ] );

		$this->assertSame( 0, Downtime_Reminders::run() );
	}

	public function test_npcs_inactive_characters_and_characters_with_no_player_are_not_reminded(): void {
		$this->session( '+30 hours' );
		Character::update_header( $this->isolde, [ 'status' => 'retired' ] );
		$this->character( 'Some NPC', $this->member( 'player' ), [ 'is_npc' => 1 ] );
		$this->character( 'Orphan', 0 );

		$this->assertSame( 0, Downtime_Reminders::run() );
	}

	public function test_an_opted_out_player_still_gets_the_notice_and_no_email(): void {
		update_user_meta( $this->player, User_Settings::NOTIFICATIONS_OPT_OUT_META, '1' );
		$this->session( '+30 hours' );

		Downtime_Reminders::run();

		$this->assertCount( 1, $this->reminders( $this->player ) );
		$this->assertSame( [], $this->mail );
	}

	public function test_a_demo_chronicle_gets_the_notice_and_no_email(): void {
		Game::update( $this->slug, [ 'settings' => [ 'demo' => [ 'on' => true ] ] ] );
		$this->session( '+30 hours' );

		Downtime_Reminders::run();

		$this->assertCount( 1, $this->reminders( $this->player ) );
		$this->assertSame( [], $this->mail );
	}

	public function test_the_daily_maintenance_run_sends_the_reminders(): void {
		$this->session( '+30 hours' );

		\BeyondElysium\Core\Maintenance::run();

		$this->assertCount( 1, $this->reminders( $this->player ) );
	}
}
