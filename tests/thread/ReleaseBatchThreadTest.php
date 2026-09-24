<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Release_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Release batches - the held/release_batch_id gate on plots and entries, scheduling, releasing, and the
 * one-email-per-player-per-batch notification.
 */
class ReleaseBatchThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-release-batches';
	private int $game_id;
	private int $player_id;
	private int $character_id;
	private array $captured_mail = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Release Batches',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		$this->character_id = (int) Character::create( [
			'name'       => 'Marcus Vitel',
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'wp_user_id' => $this->player_id,
			'status'     => 'active',
			'created_by' => 1,
		] );

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

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A manager-created, audience=everyone plot.
	 */
	private function make_plot(): int {
		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'A Rumor' );
		$request->set_param( 'audience', 'everyone' );
		return (int) $this->dispatch( $request )->get_data()->id;
	}

	private function make_entry( int $plot_id ): int {
		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$request->set_param( 'entry_type', 'response' );
		$request->set_param( 'content', 'A downtime answer.' );
		return (int) $this->dispatch( $request )->get_data()->id;
	}

	/** @return int The new batch's id. */
	private function make_batch( ?string $release_at = null ): int {
		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/release-batches" );
		$request->set_param( 'name', 'Batch ' . wp_generate_password( 6, false ) );
		if ( $release_at !== null ) {
			$request->set_param( 'release_at', $release_at );
		}
		return (int) $this->dispatch( $request )->get_data()->id;
	}

	private function add_item_to_batch( int $batch_id, string $type, int $item_id ): void {
		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/release-batches/{$batch_id}/items" );
		$request->set_param( 'type', $type );
		$request->set_param( 'id', $item_id );
		$this->dispatch( $request );
	}

	private function get_plots_as_player(): array {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots" );
		return (array) $this->dispatch( $request )->get_data();
	}

	private function get_entries_as_player( int $plot_id ): array {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		return (array) $this->dispatch( $request )->get_data();
	}

	// -------------------------------------------------------------------------
	// The gate: plots
	// -------------------------------------------------------------------------

	public function test_a_draft_held_plot_is_hidden_from_a_player(): void {
		$plot_id  = $this->make_plot();
		$batch_id = $this->make_batch(); // no release_at - stays draft
		$this->add_item_to_batch( $batch_id, 'plot', $plot_id );

		$ids = array_map( static fn( $p ) => (int) $p->id, $this->get_plots_as_player() );
		$this->assertNotContains( $plot_id, $ids );
	}

	public function test_a_plot_in_a_future_scheduled_batch_is_hidden_from_a_player(): void {
		$plot_id  = $this->make_plot();
		$batch_id = $this->make_batch( gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		$this->add_item_to_batch( $batch_id, 'plot', $plot_id );

		$ids = array_map( static fn( $p ) => (int) $p->id, $this->get_plots_as_player() );
		$this->assertNotContains( $plot_id, $ids );
	}

	public function test_a_past_due_scheduled_plot_is_visible_with_no_cron_run(): void {
		// Visibility never waits for cron.
		$plot_id  = $this->make_plot();
		$batch_id = $this->make_batch( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->add_item_to_batch( $batch_id, 'plot', $plot_id );

		$ids = array_map( static fn( $p ) => (int) $p->id, $this->get_plots_as_player() );
		$this->assertContains( $plot_id, $ids );
	}

	public function test_a_released_plot_is_visible(): void {
		$plot_id  = $this->make_plot();
		$batch_id = $this->make_batch();
		$this->add_item_to_batch( $batch_id, 'plot', $plot_id );
		Release_Engine::release( $batch_id );

		$ids = array_map( static fn( $p ) => (int) $p->id, $this->get_plots_as_player() );
		$this->assertContains( $plot_id, $ids );
	}

	public function test_a_manager_sees_every_held_plot_regardless_of_state(): void {
		$draft_id      = $this->make_plot();
		$future_id     = $this->make_plot();
		$past_due_id   = $this->make_plot();
		$released_id   = $this->make_plot();
		$this->add_item_to_batch( $this->make_batch(), 'plot', $draft_id );
		$this->add_item_to_batch( $this->make_batch( gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ), 'plot', $future_id );
		$this->add_item_to_batch( $this->make_batch( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), 'plot', $past_due_id );
		$released_batch = $this->make_batch();
		$this->add_item_to_batch( $released_batch, 'plot', $released_id );
		Release_Engine::release( $released_batch );

		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots" );
		$ids     = array_map( static fn( $p ) => (int) $p->id, (array) $this->dispatch( $request )->get_data() );

		foreach ( [ $draft_id, $future_id, $past_due_id, $released_id ] as $id ) {
			$this->assertContains( $id, $ids );
		}
	}

	// -------------------------------------------------------------------------
	// The gate: entries
	// -------------------------------------------------------------------------

	public function test_a_draft_held_entry_is_hidden_from_a_player(): void {
		$plot_id  = $this->make_plot();
		$entry_id = $this->make_entry( $plot_id );
		$batch_id = $this->make_batch();
		$this->add_item_to_batch( $batch_id, 'entry', $entry_id );

		$ids = array_map( static fn( $e ) => (int) $e->id, $this->get_entries_as_player( $plot_id ) );
		$this->assertNotContains( $entry_id, $ids );
	}

	public function test_a_past_due_scheduled_entry_is_visible_with_no_cron_run(): void {
		$plot_id  = $this->make_plot();
		$entry_id = $this->make_entry( $plot_id );
		$batch_id = $this->make_batch( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->add_item_to_batch( $batch_id, 'entry', $entry_id );

		$ids = array_map( static fn( $e ) => (int) $e->id, $this->get_entries_as_player( $plot_id ) );
		$this->assertContains( $entry_id, $ids );
	}

	public function test_a_released_entry_is_visible(): void {
		$plot_id  = $this->make_plot();
		$entry_id = $this->make_entry( $plot_id );
		$batch_id = $this->make_batch();
		$this->add_item_to_batch( $batch_id, 'entry', $entry_id );
		Release_Engine::release( $batch_id );

		$ids = array_map( static fn( $e ) => (int) $e->id, $this->get_entries_as_player( $plot_id ) );
		$this->assertContains( $entry_id, $ids );
	}

	// -------------------------------------------------------------------------
	// Release_Engine::release() - notifications
	// -------------------------------------------------------------------------

	public function test_releasing_a_batch_emails_every_reached_player_once(): void {
		$plot_id  = $this->make_plot();
		$batch_id = $this->make_batch();
		$this->add_item_to_batch( $batch_id, 'plot', $plot_id );

		Release_Engine::release( $batch_id );

		$this->assertCount( 1, $this->captured_mail );
		$this->assertStringContainsString( 'Marcus Vitel', $this->captured_mail[0]['subject'] );
	}

	public function test_releasing_the_same_batch_twice_sends_no_second_round_of_email(): void {
		$plot_id  = $this->make_plot();
		$batch_id = $this->make_batch();
		$this->add_item_to_batch( $batch_id, 'plot', $plot_id );

		Release_Engine::release( $batch_id );
		$this->captured_mail = [];
		Release_Engine::release( $batch_id );

		$this->assertCount( 0, $this->captured_mail );
	}

	public function test_a_held_entry_reaches_only_its_connected_characters_not_the_whole_chronicle(): void {
		// The plot itself is audience=everyone.
		$plot_id  = $this->make_plot();
		$entry_id = $this->make_entry( $plot_id );
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'plot',
			'source_id'   => $plot_id,
			'target_type' => 'character',
			'target_id'   => $this->character_id,
			'label'       => 'plot_member',
		] );

		$batch_id = $this->make_batch();
		$this->add_item_to_batch( $batch_id, 'entry', $entry_id );
		Release_Engine::release( $batch_id );

		$this->assertCount( 1, $this->captured_mail );
	}

	// -------------------------------------------------------------------------
	// REST: transitions
	// -------------------------------------------------------------------------

	public function test_release_now_marks_a_scheduled_batch_released_and_sends_mail(): void {
		$plot_id  = $this->make_plot();
		$batch_id = $this->make_batch( gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		$this->add_item_to_batch( $batch_id, 'plot', $plot_id );

		wp_set_current_user( $this->make_manager() );
		$request  = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/release-batches/{$batch_id}/release-now" );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'released', $response->get_data()->status );
		$this->assertCount( 1, $this->captured_mail );
	}

	public function test_unscheduling_a_not_yet_out_batch_returns_it_to_draft(): void {
		$batch_id = $this->make_batch( gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );

		wp_set_current_user( $this->make_manager() );
		$request  = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/release-batches/{$batch_id}" );
		$request->set_param( 'status', 'draft' );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'draft', $response->get_data()->status );
	}

	public function test_unscheduling_an_already_out_batch_is_refused(): void {
		$batch_id = $this->make_batch( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/release-batches/{$batch_id}" );
		$request->set_param( 'status', 'draft' );

		$this->assertSame( 409, $this->dispatch( $request )->get_status() );
	}

	public function test_deleting_a_draft_batch_returns_its_items_to_draft_not_removed(): void {
		$plot_id  = $this->make_plot();
		$batch_id = $this->make_batch();
		$this->add_item_to_batch( $batch_id, 'plot', $plot_id );

		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/release-batches/{$batch_id}" );
		$this->assertSame( 204, $this->dispatch( $request )->get_status() );

		$plot = Plot::find( $plot_id );
		$this->assertTrue( (bool) $plot->held );
		$this->assertNull( $plot->release_batch_id );
	}

	public function test_a_released_batch_cannot_be_deleted(): void {
		$batch_id = $this->make_batch();
		Release_Engine::release( $batch_id );

		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/release-batches/{$batch_id}" );
		$this->assertSame( 409, $this->dispatch( $request )->get_status() );
	}

	public function test_release_now_single_creates_fills_and_releases_in_one_call(): void {
		$plot_id = $this->make_plot();

		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/release-batches/release-now" );
		$request->set_param( 'items', [ [ 'type' => 'plot', 'id' => $plot_id ] ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'released', $response->get_data()->status );

		$plot = Plot::find( $plot_id );
		$this->assertTrue( (bool) $plot->held );
		$this->assertSame( (int) $response->get_data()->id, (int) $plot->release_batch_id );
		$this->assertCount( 1, $this->captured_mail );
	}

	public function test_removing_an_item_from_a_batch_returns_it_to_draft(): void {
		$entry_id = $this->make_entry( $this->make_plot() );
		$batch_id = $this->make_batch();
		$this->add_item_to_batch( $batch_id, 'entry', $entry_id );

		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/release-batches/{$batch_id}/items/entry/{$entry_id}" );
		$this->assertSame( 204, $this->dispatch( $request )->get_status() );

		$entry = Plot_Entry::find( $entry_id );
		$this->assertTrue( (bool) $entry->held );
		$this->assertNull( $entry->release_batch_id );
	}
}
