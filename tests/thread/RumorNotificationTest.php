<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * "Rumor delivery" (0.99.X-Ideas.md): a generated rumor's target_query resolved
 * recipients, then nothing notified them. Exercises the real REST route
 * (`Plots_Controller::generate_rumors()`), not `Notifications::enqueue_rumor()` in
 * isolation - the thing worth proving is that a real commit reaches a real matched
 * player's inbox, and that preview mode never does.
 *
 * `pre_wp_mail` (WP 5.7+) short-circuits `wp_mail()` and hands back its args - real mail
 * is never attempted, matching NotificationsTest's own pattern.
 *
 * @see BE_PROCESS/0.99.2-workflow.md "Rumor delivery"
 */
class RumorNotificationTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-rumor-notify-game';
	private int $player_id;
	private int $st_id;
	private array $captured = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Rumor Notify Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'rumor-player@example.test' ] );
		Character::create( [
			'name' => 'Rumor Test Character', 'stack_slug' => 'vampire', 'status' => 'active',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_id,
		] );

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

	private function generate_request( bool $commit ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/generate-rumors" );
		$request->set_param( 'game_date', '2026-01-01' );
		$request->set_param( 'commit', $commit );
		return $request;
	}

	public function test_committing_generation_emails_the_matched_player(): void {
		wp_set_current_user( $this->st_id );

		$response = $this->dispatch( $this->generate_request( true ) );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		// Public Knowledge (target_query: null) reaches every character, including
		// the one seeded here - exactly one player, so exactly one summary email.
		$this->assertCount( 1, $this->captured, 'exactly one summary email for one matched player' );
		$this->assertSame( 'rumor-player@example.test', $this->captured[0]['to'] );
		$this->assertStringContainsString( 'rumor', strtolower( $this->captured[0]['subject'] ) );
		$this->assertStringContainsString( 'Public Knowledge', $this->captured[0]['message'] );
	}

	public function test_preview_mode_never_sends_mail(): void {
		wp_set_current_user( $this->st_id );

		$response = $this->dispatch( $this->generate_request( false ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$this->assertCount( 0, $this->captured, 'preview must stay side-effect-free' );
	}

	public function test_regenerating_the_same_date_does_not_email_twice(): void {
		wp_set_current_user( $this->st_id );

		$this->dispatch( $this->generate_request( true ) );
		$this->captured = [];

		// Every title from the first pass is already present at this date, so the
		// second pass generates (and therefore notifies about) nothing new.
		$this->dispatch( $this->generate_request( true ) );

		$this->assertCount( 0, $this->captured, 'no new rumors on the second pass means no new mail' );
	}
}
