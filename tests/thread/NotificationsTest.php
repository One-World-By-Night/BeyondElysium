<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\User_Settings;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Step 4, workflow-0.9.md (Decision 087). Exercises the real REST call sites
 * (`Changes_Controller::update_item()`/`batch_approve()`), not `Notifications` in
 * isolation - the thing actually worth proving is that the wiring only fires on a genuine
 * ST-initiated review, never on `Change_Engine::submit()`'s own internal auto-approve path.
 *
 * `pre_wp_mail` (WP 5.7+) short-circuits `wp_mail()` and hands back its args - real mail is
 * never attempted, and every assertion here reads the captured args instead of a live inbox.
 */
class NotificationsTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-notify-game';
	private int $character_id;
	private int $player_id;
	private int $st_id;
	private array $captured = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Notify Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		$this->player_id   = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'notify-player@example.test' ] );
		$this->character_id = Character::create( [
			'name' => 'Notify Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_id,
		] );
		Character::update_xp( $this->character_id, 10, 10 );

		$this->st_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->captured = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ] );
		parent::tearDown();
	}

	/**
	 * @param mixed $pre_empty Unused - `pre_wp_mail`'s own short-circuit value, always null here.
	 * @param array $atts
	 * @return true
	 */
	public function capture_mail( $pre_empty, $atts ) {
		$this->captured[] = $atts;
		return true;
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function submit_change_as( int $user_id, string $trait_name = 'Iron Will' ): int {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes" );
		$request->set_param( 'change_type', 'add_trait' );
		$request->set_param( 'category', 'met-merits' );
		$request->set_param( 'change_data', [ 'block_slug' => 'met-merits', 'trait' => [ 'name' => $trait_name ] ] );
		return (int) $this->dispatch( $request )->get_data()->id;
	}

	public function test_approving_a_change_emails_the_submitting_players_character_owner(): void {
		$change_id = $this->submit_change_as( $this->player_id );

		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_param( 'status', 'approved' );
		$this->assertSame( 200, $this->dispatch( $request )->get_status() );

		$this->assertCount( 1, $this->captured, 'exactly one mail for one approved change' );
		$this->assertSame( 'notify-player@example.test', $this->captured[0]['to'] );
		$this->assertStringContainsString( 'approved', $this->captured[0]['subject'] );
		$this->assertStringContainsString( 'Iron Will', $this->captured[0]['message'] );
	}

	public function test_rejecting_a_change_emails_the_submitting_players_character_owner(): void {
		$change_id = $this->submit_change_as( $this->player_id );

		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_param( 'status', 'rejected' );
		$request->set_param( 'notes', 'Not this time.' );
		$this->assertSame( 200, $this->dispatch( $request )->get_status() );

		$this->assertCount( 1, $this->captured );
		$this->assertStringContainsString( 'rejected', $this->captured[0]['subject'] );
	}

	/**
	 * The behavioral heart of 4d - Change_Engine::submit()'s own internal auto-approve
	 * call never runs through either Changes_Controller call site Notifications is wired
	 * into, so an auto-approved change must send nothing even though its final status is
	 * 'approved' just like a real ST review's.
	 */
	public function test_an_auto_approved_change_sends_no_mail(): void {
		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes" );
		$request->set_param( 'change_type', 'xp_earn' );
		$request->set_param( 'category', 'experience' );
		$request->set_param( 'change_data', [ 'amount' => 5, 'reason' => 'Session attendance' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 'approved', $response->get_data()->status, 'xp_earn submitted by a manager auto-approves' );
		$this->assertCount( 0, $this->captured, 'auto-approve is not a review action - Decision 087' );
	}

	public function test_batch_approving_two_changes_for_the_same_player_sends_one_mail(): void {
		// Two DIFFERENT traits - BE_PROCESS/0.99.2-workflow.md's "Resubmitting creates
		// duplicate pending changes" fix means two submissions of the exact same trait now
		// collapse into one pending row, which this test must not rely on to get two ids.
		$first  = $this->submit_change_as( $this->player_id, 'Iron Will' );
		$second = $this->submit_change_as( $this->player_id, 'Nerves of Steel' );

		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/changes/batch-approve" );
		$request->set_param( 'change_ids', [ $first, $second ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing( [ $first, $second ], $response->get_data()['approved'] ?? [] );
		$this->assertCount( 1, $this->captured, 'one player, one summary mail - never one per change' );
		$this->assertStringContainsString( 'Iron Will', $this->captured[0]['message'] );
		$this->assertStringContainsString( 'Nerves of Steel', $this->captured[0]['message'], 'both changes are listed in the one summary' );
	}

	public function test_a_player_who_opted_out_receives_no_mail(): void {
		update_user_meta( $this->player_id, User_Settings::NOTIFICATIONS_OPT_OUT_META, '1' );
		$change_id = $this->submit_change_as( $this->player_id );

		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_param( 'status', 'approved' );
		$this->dispatch( $request );

		$this->assertCount( 0, $this->captured );
	}

	public function test_a_game_with_notifications_disabled_sends_no_mail(): void {
		Game::update( $this->game_slug, [ 'notifications_enabled' => 0 ] );
		$change_id = $this->submit_change_as( $this->player_id );

		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_param( 'status', 'approved' );
		$this->dispatch( $request );

		$this->assertCount( 0, $this->captured );
	}

	public function test_a_manager_approving_their_own_submitted_change_receives_no_mail(): void {
		$manager_player_id = self::factory()->user->create( [ 'role' => 'administrator', 'user_email' => 'manager-player@example.test' ] );
		$own_character_id  = Character::create( [
			'name' => 'Manager Owned Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $manager_player_id,
		] );
		Character::update_xp( $own_character_id, 10, 10 );

		wp_set_current_user( $manager_player_id );
		$submit = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$own_character_id}/changes" );
		$submit->set_param( 'change_type', 'add_trait' );
		$submit->set_param( 'category', 'met-merits' );
		$submit->set_param( 'change_data', [ 'block_slug' => 'met-merits', 'trait' => [ 'name' => 'Iron Will' ] ] );
		$change_id = (int) $this->dispatch( $submit )->get_data()->id;

		// Same user reviews their own submission - still current_user, no wp_set_current_user needed.
		$approve = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$approve->set_param( 'status', 'approved' );
		$this->assertSame( 200, $this->dispatch( $approve )->get_status() );

		$this->assertCount( 0, $this->captured, 'reviewing your own submission is not the dead-end 4a closes' );
	}
}
