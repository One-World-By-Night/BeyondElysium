<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Services\Audience;
use BeyondElysium\Services\Release_Engine;
use BeyondElysium\Services\Rumor_Generator;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 S4: rumors - held from birth with no email at generation (replacing the old
 * `RumorNotificationTest`'s now-obsolete premise that a commit itself emails the matched
 * player), audience derived from the target at creation, and delivery only through a
 * release batch (§3.2) exactly like any other held plot.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.4
 */
class RumorReleaseThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-rumor-release';
	private int $game_id;
	private array $captured_mail = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Rumor Release',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [ 'apr' => [ 'personal_rumors' => true ] ] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->captured_mail = [];
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
		$this->captured_mail[] = $atts;
		return true;
	}

	private function make_manager(): int {
		$hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $hst, 'hst' );
		return $hst;
	}

	/** @return array{0:int,1:int} [wp_user_id, character_id] */
	private function make_player_character( string $name ): array {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player_id, 'player' );
		$character_id = (int) Character::create( [
			'name'       => $name,
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'wp_user_id' => $player_id,
			'status'     => 'active',
			'created_by' => 1,
		] );
		return [ $player_id, $character_id ];
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function generate( bool $commit ): \WP_REST_Response {
		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/generate-rumors" );
		$request->set_param( 'game_date', '2026-01-01' );
		$request->set_param( 'commit', $commit );
		return $this->dispatch( $request );
	}

	private function find_rumor_plot( string $title ): ?object {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}be_plots WHERE game_id = %d AND title = %s",
			$this->game_id,
			$title
		) );
		return $id ? Plot::find( (int) $id ) : null;
	}

	public function test_generation_never_sends_mail_even_when_committed(): void {
		$this->make_player_character( 'Rumor Release Character' );

		$response = $this->generate( true );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( [], $this->captured_mail, 'generation itself must never send mail - only a release does (item 3)' );
	}

	public function test_a_generated_rumor_is_held_with_no_batch_and_invisible_to_a_non_manager(): void {
		[ $player_id, ] = $this->make_player_character( 'Rumor Release Character' );
		$this->generate( true );

		$plot = $this->find_rumor_plot( Rumor_Generator::PUBLIC_TITLE );
		$this->assertNotNull( $plot );
		$this->assertTrue( $plot->held, 'held from birth (item 2)' );
		$this->assertNull( $plot->release_batch_id, 'a draft has no batch yet' );

		wp_set_current_user( $player_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots" ) );
		$titles   = array_map( static fn( $p ) => $p->title, (array) $response->get_data() );
		$this->assertNotContains( Rumor_Generator::PUBLIC_TITLE, $titles );
	}

	public function test_public_knowledge_rumor_is_audience_everyone(): void {
		$this->generate( true );

		$plot = $this->find_rumor_plot( Rumor_Generator::PUBLIC_TITLE );
		$this->assertNotNull( $plot );
		$this->assertSame( Audience::EVERYONE, $plot->audience );
		$this->assertNull( $plot->audience_rules );
	}

	public function test_a_targeted_rumor_is_restricted_with_audience_derived_from_its_target(): void {
		$this->make_player_character( 'Targeted Rumor Character' );
		$this->generate( true );

		$plot = $this->find_rumor_plot( 'Targeted Rumor Character' );
		$this->assertNotNull( $plot, 'personal_rumors is enabled in setUp()' );
		$this->assertSame( Audience::RESTRICTED, $plot->audience );
		$this->assertSame( 'AND', $plot->audience_rules['logic'] ?? null );
		$this->assertEquals(
			\BeyondElysium\Services\Query_Engine::target_query_to_condition( $plot->target_query ),
			$plot->audience_rules['conditions'][0] ?? null
		);
	}

	public function test_a_released_rumor_reaches_the_matched_player_and_not_the_unmatched_one(): void {
		[ $matched_player, ]   = $this->make_player_character( 'Targeted Rumor Character' );
		[ $unmatched_player, ] = $this->make_player_character( 'Someone Else Entirely' );

		$this->generate( true );
		$plot = $this->find_rumor_plot( 'Targeted Rumor Character' );
		$this->assertNotNull( $plot );

		wp_set_current_user( $this->make_manager() );
		$batch_request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/release-batches" );
		$batch_request->set_param( 'name', 'Rumor Batch' );
		$batch_id = (int) $this->dispatch( $batch_request )->get_data()->id;

		$item_request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/release-batches/{$batch_id}/items" );
		$item_request->set_param( 'type', 'plot' );
		$item_request->set_param( 'id', $plot->id );
		$this->dispatch( $item_request );

		$this->captured_mail = [];
		Release_Engine::release( $batch_id );

		$recipients      = array_column( $this->captured_mail, 'to' );
		$matched_email   = get_userdata( $matched_player )->user_email;
		$unmatched_email = get_userdata( $unmatched_player )->user_email;
		$this->assertContains( $matched_email, $recipients );
		$this->assertNotContains( $unmatched_email, $recipients );
	}

	public function test_the_migration_keeps_todays_visibility(): void {
		delete_option( 'be_rumor_release_migrated' );

		[ $player_id, ] = $this->make_player_character( 'Pre 1.1.0 Rumor Character' );

		$plot_id = (int) Plot::create( [
			'game_id'    => $this->game_id,
			'title'      => 'A Pre-1.1.0 Rumor',
			'created_by' => 1,
			'audience'   => Audience::EVERYONE,
		] );
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'plot',
			'source_id'   => $plot_id,
			'target_type' => 'tag',
			'label'       => Rumor_Generator::RUMOR_LABEL,
			'created_by'  => 1,
		] );

		// Confirmed visible before the migration - today's actual, pre-1.1.0 behavior.
		$this->assertTrue( Audience::can_see( Plot::find( $plot_id ), 'plot', $player_id, $this->game_slug, false ) );

		Schema::migrate_rumors_to_release_batches();

		$plot = Plot::find( $plot_id );
		$this->assertTrue( $plot->held );
		$this->assertNotNull( $plot->release_batch_id );
		$this->assertSame( Audience::EVERYONE, $plot->audience, "the migration must never touch a rumor's existing audience" );
		$this->assertTrue(
			Audience::can_see( $plot, 'plot', $player_id, $this->game_slug, false ),
			'a rumor visible before 1.1.0 must still be visible after the migration'
		);
	}

	public function test_the_migration_runs_once_and_does_not_move_a_rumor_already_released_elsewhere(): void {
		delete_option( 'be_rumor_release_migrated' );

		$plot_id = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'A Pre-1.1.0 Rumor', 'created_by' => 1 ] );
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'plot',
			'source_id'   => $plot_id,
			'target_type' => 'tag',
			'label'       => Rumor_Generator::RUMOR_LABEL,
			'created_by'  => 1,
		] );

		Schema::migrate_rumors_to_release_batches();
		$first_batch_id = (int) Plot::find( $plot_id )->release_batch_id;

		// A Storyteller moves it to a different, later batch afterward.
		wp_set_current_user( $this->make_manager() );
		$new_batch_request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/release-batches" );
		$new_batch_request->set_param( 'name', 'A Later Batch' );
		$new_batch_id = (int) $this->dispatch( $new_batch_request )->get_data()->id;
		Plot::update( $plot_id, [ 'release_batch_id' => $new_batch_id ] );

		// A later upgrade re-runs migrate() - the one-time guard must not move it back.
		Schema::migrate_rumors_to_release_batches();

		$this->assertNotSame( $first_batch_id, $new_batch_id );
		$this->assertSame( $new_batch_id, (int) Plot::find( $plot_id )->release_batch_id );
	}
}
