<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Notifications;
use BeyondElysium\Core\User_Settings;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Notification_Queue;
use BeyondElysium\Models\Plot;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 S5: plot post notifications - who a new post reaches, the per-person preference
 * (immediate/daily/off), the daily digest, and that no email ever carries the post's own text.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.5
 */
class PlotPostNotificationThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-plot-notify';
	private int $game_id;
	private array $captured = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Plot Notify',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->captured = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ] );
		parent::tearDown();
	}

	/**
	 * @param mixed $pre_empty Unused - pre_wp_mail's own short-circuit value, always null here.
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

	private function make_staff( string $role ): int {
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $user, $role );
		return $user;
	}

	/** @return array{0:int,1:int} [wp_user_id, character_id] */
	private function make_player_character( string $name ): array {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player_id, 'player' );
		$character_id = (int) Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $player_id, 'status' => 'active', 'created_by' => 1,
		] );
		return [ $player_id, $character_id ];
	}

	private function make_plot( string $title = 'A Plot', ?int $assigned_to = null, string $audience = 'everyone' ): int {
		$plot_id = (int) Plot::create( [
			'game_id' => $this->game_id, 'title' => $title, 'created_by' => 1, 'audience' => $audience,
		] );
		if ( $assigned_to !== null ) {
			Plot::update( $plot_id, [ 'assigned_to' => $assigned_to ] );
		}
		return $plot_id;
	}

	private function connect( int $plot_id, int $character_id, string $label = 'plot_member' ): void {
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $plot_id,
			'target_type' => 'character', 'target_id' => $character_id, 'label' => $label, 'created_by' => 1,
		] );
	}

	private function post_action( int $plot_id, int $wp_user_id, string $content = 'I do a thing.' ) {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$request->set_param( 'entry_type', 'action' );
		$request->set_param( 'content', $content );
		return $this->dispatch( $request );
	}

	private function post_response( int $plot_id, int $wp_user_id, string $content = 'The answer.', ?string $audience = null, bool $held = false ) {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$request->set_param( 'entry_type', 'response' );
		$request->set_param( 'content', $content );
		if ( $audience !== null ) {
			$request->set_param( 'audience', $audience );
		}
		$request->set_param( 'held', $held );
		return $this->dispatch( $request );
	}

	private function recipients(): array {
		return array_column( $this->captured, 'to' );
	}

	// -------------------------------------------------------------------------

	public function test_a_players_post_on_an_assigned_plot_reaches_only_the_assignee(): void {
		$hst              = $this->make_staff( 'hst' );
		$ast              = $this->make_staff( 'ast' );
		[ $player_id, $character_id ] = $this->make_player_character( 'Poster' );
		$plot_id          = $this->make_plot( 'A Plot', $ast );

		$this->post_action( $plot_id, $player_id );

		$recipients = $this->recipients();
		$this->assertContains( get_userdata( $ast )->user_email, $recipients );
		$this->assertNotContains( get_userdata( $hst )->user_email, $recipients );
	}

	public function test_an_unassigned_players_post_reaches_hst_ast_and_narrator_not_players(): void {
		$hst      = $this->make_staff( 'hst' );
		$ast      = $this->make_staff( 'ast' );
		$narrator = $this->make_staff( 'narrator' );
		[ $player_id, ]       = $this->make_player_character( 'Poster' );
		[ $other_player_id, ] = $this->make_player_character( 'Bystander' );
		$plot_id  = $this->make_plot();

		$this->post_action( $plot_id, $player_id );

		$recipients = $this->recipients();
		$this->assertContains( get_userdata( $hst )->user_email, $recipients );
		$this->assertContains( get_userdata( $ast )->user_email, $recipients );
		$this->assertContains( get_userdata( $narrator )->user_email, $recipients );
		$this->assertNotContains( get_userdata( $other_player_id )->user_email, $recipients );
	}

	public function test_a_storyteller_post_reaches_the_involved_players_character_not_an_unrelated_member(): void {
		$hst = $this->make_staff( 'hst' );
		[ $involved_player, $involved_character ] = $this->make_player_character( 'Involved' );
		[ $unrelated_player, ]                    = $this->make_player_character( 'Unrelated' );
		$plot_id = $this->make_plot( 'A Plot', null, 'everyone' );
		$this->connect( $plot_id, $involved_character );

		$this->post_response( $plot_id, $hst );

		$recipients = $this->recipients();
		$this->assertContains( get_userdata( $involved_player )->user_email, $recipients );
		$this->assertNotContains( get_userdata( $unrelated_player )->user_email, $recipients, 'never the whole chronicle of an everyone plot' );
	}

	public function test_a_storytellers_only_reply_does_not_notify_a_connected_player_who_cannot_see_it(): void {
		$hst = $this->make_staff( 'hst' );
		[ $involved_player, $involved_character ] = $this->make_player_character( 'Involved' );
		$plot_id = $this->make_plot();
		$this->connect( $plot_id, $involved_character );

		$this->post_response( $plot_id, $hst, 'Secret ST chatter.', 'storytellers' );

		$this->assertNotContains( get_userdata( $involved_player )->user_email, $this->recipients() );
	}

	public function test_a_held_response_sends_no_notification_here(): void {
		[ $player_id, $character_id ] = $this->make_player_character( 'Actor' );
		wp_set_current_user( $this->make_staff( 'hst' ) );
		$plot_id = (int) \BeyondElysium\Services\Action_Allocator::create_own_plot( Character::find( $character_id ), '2026-10-02' );
		$this->post_action( $plot_id, $player_id );
		$this->captured = [];

		$hst = $this->make_staff( 'ast' );
		$this->post_response( $plot_id, $hst, 'Held for later.', null, true );

		$this->assertSame( [], $this->captured, 'a held post notifies through its own release batch, not here' );
	}

	public function test_a_note_sends_no_notification(): void {
		$hst = $this->make_staff( 'hst' );
		[ $involved_player, $involved_character ] = $this->make_player_character( 'Involved' );
		$plot_id = $this->make_plot();
		$this->connect( $plot_id, $involved_character );

		wp_set_current_user( $hst );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$request->set_param( 'entry_type', 'note' );
		$request->set_param( 'content', 'ST eyes only.' );
		$this->dispatch( $request );

		$this->assertSame( [], $this->captured );
	}

	public function test_off_preference_sends_nothing(): void {
		$ast = $this->make_staff( 'ast' );
		update_user_meta( $ast, User_Settings::PLOT_NOTIFY_META, 'off' );
		[ $player_id, ] = $this->make_player_character( 'Poster' );
		$plot_id = $this->make_plot( 'A Plot', $ast );

		$this->post_action( $plot_id, $player_id );

		$this->assertSame( [], $this->captured );
		$this->assertSame( [], Notification_Queue::for_user( $ast ) );
	}

	public function test_daily_preference_queues_and_the_digest_sends_one_mail_and_clears(): void {
		$ast = $this->make_staff( 'ast' );
		update_user_meta( $ast, User_Settings::PLOT_NOTIFY_META, 'daily' );
		[ $player_id, ] = $this->make_player_character( 'Poster' );
		$plot_id = $this->make_plot( 'A Plot', $ast );

		$this->post_action( $plot_id, $player_id );

		$this->assertSame( [], $this->captured, 'daily preference does not send immediately' );
		$this->assertCount( 1, Notification_Queue::for_user( $ast ) );

		Notifications::send_daily_digests();

		$this->assertCount( 1, $this->captured );
		$this->assertSame( get_userdata( $ast )->user_email, $this->captured[0]['to'] );
		$this->assertSame( [], Notification_Queue::for_user( $ast ), 'the digest deletes what it sent' );
	}

	public function test_the_global_opt_out_wins_over_any_preference(): void {
		$ast = $this->make_staff( 'ast' );
		update_user_meta( $ast, User_Settings::NOTIFICATIONS_OPT_OUT_META, '1' );
		update_user_meta( $ast, User_Settings::PLOT_NOTIFY_META, 'daily' );
		[ $player_id, ] = $this->make_player_character( 'Poster' );
		$plot_id = $this->make_plot( 'A Plot', $ast );

		$this->post_action( $plot_id, $player_id );

		$this->assertSame( [], $this->captured );
		$this->assertSame( [], Notification_Queue::for_user( $ast ) );
	}

	public function test_no_email_body_contains_the_posts_own_text(): void {
		$ast = $this->make_staff( 'ast' );
		[ $player_id, ] = $this->make_player_character( 'Poster' );
		$plot_id = $this->make_plot( 'A Plot', $ast );

		$secret = 'The Prince is secretly a Malkavian, do not tell anyone.';
		$this->post_action( $plot_id, $player_id, $secret );

		$this->assertNotEmpty( $this->captured );
		foreach ( $this->captured as $mail ) {
			$this->assertStringNotContainsString( $secret, $mail['message'] );
		}
	}
}
