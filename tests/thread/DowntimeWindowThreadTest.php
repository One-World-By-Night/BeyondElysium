<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Downtime_Window;
use BeyondElysium\Services\Release_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Downtime windows - enforcement on action entries and background uses, extensions, held-by-default answers, and the
 * Storyteller queue.
 */
class DowntimeWindowThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-downtime';
	private int $game_id;
	private int $player_id;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Downtime',
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
	 * Creates a session for a game date, with the given window fields, as a manager.
	 */
	private function make_session( string $game_date, ?string $opens = null, ?string $deadline = null ): int {
		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions" );
		$request->set_param( 'game_date', $game_date );
		if ( $opens !== null ) {
			$request->set_param( 'downtime_opens_at', $opens );
		}
		if ( $deadline !== null ) {
			$request->set_param( 'downtime_deadline_at', $deadline );
		}
		return (int) $this->dispatch( $request )->get_data()->id;
	}

	/**
	 * Creates the character's own action-allocation plot for a game date directly.
	 */
	private function make_action_plot( string $game_date ): int {
		$character = Character::find( $this->character_id );
		return (int) Action_Allocator::create_own_plot( $character, $game_date );
	}

	private function post_action( int $plot_id, string $content = 'I do a thing.' ) {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$request->set_param( 'entry_type', 'action' );
		$request->set_param( 'content', $content );
		return $this->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Entries_Controller::create_item() enforcement
	// -------------------------------------------------------------------------

	public function test_a_player_cannot_post_an_action_before_the_window_opens(): void {
		$plot_id = $this->make_action_plot( '2026-10-02' );
		$this->make_session( '2026-10-02', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );

		$response = $this->post_action( $plot_id );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'downtime_not_open', $response->as_error()->get_error_code() );
	}

	public function test_a_player_can_post_an_action_while_the_window_is_open(): void {
		$plot_id = $this->make_action_plot( '2026-10-02' );
		$this->make_session(
			'2026-10-02',
			gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS )
		);

		$response = $this->post_action( $plot_id );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_a_player_cannot_post_an_action_after_the_deadline(): void {
		$plot_id = $this->make_action_plot( '2026-10-02' );
		$this->make_session( '2026-10-02', null, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$response = $this->post_action( $plot_id );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'downtime_closed', $response->as_error()->get_error_code() );
	}

	public function test_a_characters_own_extension_reopens_a_closed_window(): void {
		$plot_id   = $this->make_action_plot( '2026-10-02' );
		$session_id = $this->make_session( '2026-10-02', null, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		wp_set_current_user( $this->make_manager() );
		$extend = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/downtime-extensions" );
		$extend->set_param( 'character_id', $this->character_id );
		$extend->set_param( 'until', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		$this->assertSame( 200, $this->dispatch( $extend )->get_status() );

		$response = $this->post_action( $plot_id );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_removing_an_extension_returns_the_character_to_the_sessions_own_deadline(): void {
		$plot_id    = $this->make_action_plot( '2026-10-02' );
		$session_id = $this->make_session( '2026-10-02', null, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		wp_set_current_user( $this->make_manager() );
		$extend = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/downtime-extensions" );
		$extend->set_param( 'character_id', $this->character_id );
		$extend->set_param( 'until', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		$this->dispatch( $extend );

		$remove = new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/sessions/{$session_id}/downtime-extensions/{$this->character_id}" );
		$this->assertSame( 204, $this->dispatch( $remove )->get_status() );

		$response = $this->post_action( $plot_id );
		$this->assertSame( 409, $response->get_status() );
	}

	public function test_a_manager_is_never_blocked_by_the_window(): void {
		$plot_id = $this->make_action_plot( '2026-10-02' );
		$this->make_session( '2026-10-02', null, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$request->set_param( 'entry_type', 'action' );
		$request->set_param( 'content', 'A Storyteller posting on someone else\'s behalf.' );

		$this->assertSame( 201, $this->dispatch( $request )->get_status() );
	}

	public function test_no_session_for_the_date_means_no_window_at_all(): void {
		$plot_id = $this->make_action_plot( '2026-10-02' );

		$response = $this->post_action( $plot_id );
		$this->assertSame( 201, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Apr_Controller background uses
	// -------------------------------------------------------------------------

	public function test_a_player_cannot_record_a_background_use_after_the_deadline(): void {
		$this->make_session( '2026-10-02', null, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/background-uses" );
		$request->set_param( 'game_date', '2026-10-02' );
		$request->set_param( 'name', 'Contacts' );
		$request->set_param( 'text', 'Ask around.' );

		$response = $this->dispatch( $request );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'downtime_closed', $response->as_error()->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Held-by-default answers
	// -------------------------------------------------------------------------

	public function test_a_storytellers_response_on_an_action_plot_is_held_by_default_and_hidden_from_the_player(): void {
		$plot_id = $this->make_action_plot( '2026-10-02' );

		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$request->set_param( 'entry_type', 'response' );
		$request->set_param( 'content', 'You find nothing.' );
		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( (bool) $response->get_data()->held );
		$this->assertNull( $response->get_data()->release_batch_id );

		wp_set_current_user( $this->player_id );
		$get = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$ids = array_map( static fn( $e ) => (int) $e->id, (array) $this->dispatch( $get )->get_data() );
		$this->assertNotContains( (int) $response->get_data()->id, $ids );
	}

	public function test_the_held_answer_becomes_visible_once_its_batch_is_released(): void {
		$plot_id = $this->make_action_plot( '2026-10-02' );

		wp_set_current_user( $this->make_manager() );
		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$create->set_param( 'entry_type', 'response' );
		$create->set_param( 'content', 'You find nothing.' );
		$entry_id = (int) $this->dispatch( $create )->get_data()->id;

		$batch = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/release-batches" );
		$batch->set_param( 'name', 'Downtime batch' );
		$batch_id = (int) $this->dispatch( $batch )->get_data()->id;

		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/release-batches/{$batch_id}/items" );
		$add->set_param( 'type', 'entry' );
		$add->set_param( 'id', $entry_id );
		$this->dispatch( $add );

		Release_Engine::release( $batch_id );

		wp_set_current_user( $this->player_id );
		$get = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$ids = array_map( static fn( $e ) => (int) $e->id, (array) $this->dispatch( $get )->get_data() );
		$this->assertContains( $entry_id, $ids );
	}

	public function test_a_storyteller_can_post_an_answer_immediately_with_held_false(): void {
		$plot_id = $this->make_action_plot( '2026-10-02' );

		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$request->set_param( 'entry_type', 'response' );
		$request->set_param( 'content', 'Posted right away.' );
		$request->set_param( 'held', false );
		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertFalse( (bool) $response->get_data()->held );
	}

	// -------------------------------------------------------------------------
	// The queue
	// -------------------------------------------------------------------------

	public function test_the_queue_lists_unanswered_before_answered(): void {
		$answered_plot   = $this->make_action_plot( '2026-10-02' );
		$this->post_action( $answered_plot );
		wp_set_current_user( $this->make_manager() );
		$respond = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$answered_plot}/entries" );
		$respond->set_param( 'entry_type', 'response' );
		$respond->set_param( 'content', 'Answered.' );
		$this->dispatch( $respond );

		$other_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $other_player, 'player' );
		$other_character = (int) Character::create( [
			'name'       => 'Isabel Cruz',
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'wp_user_id' => $other_player,
			'status'     => 'active',
			'created_by' => 1,
		] );
		$unanswered_plot = (int) Action_Allocator::create_own_plot( Character::find( $other_character ), '2026-10-02' );
		wp_set_current_user( $other_player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$unanswered_plot}/entries" );
		$request->set_param( 'entry_type', 'action' );
		$request->set_param( 'content', 'I do a thing too.' );
		$this->dispatch( $request );

		$rows = Downtime_Window::queue_for_date( $this->game_id, '2026-10-02' );
		$this->assertCount( 2, $rows );
		$this->assertFalse( $rows[0]['answered'] );
		$this->assertTrue( $rows[1]['answered'] );
	}

	public function test_the_queue_payload_carries_the_right_connections_for_two_plots(): void {
		$plot_a = $this->make_action_plot( '2026-10-02' );

		$other_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $other_player, 'player' );
		$npc_id = (int) Character::create( [
			'name'       => 'An NPC',
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'is_npc'     => true,
			'status'     => 'active',
			'created_by' => 1,
		] );
		$other_character_id = (int) Character::create( [
			'name'       => 'Isabel Cruz',
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'wp_user_id' => $other_player,
			'status'     => 'active',
			'created_by' => 1,
		] );
		$plot_b = (int) Action_Allocator::create_own_plot( Character::find( $other_character_id ), '2026-10-02' );

		$item_id = (int) World_Object::create( [
			'game_id'     => $this->game_id,
			'object_type' => 'item',
			'name'        => 'A Silver Dagger',
			'created_by'  => 1,
		] );
		$location_id = (int) World_Object::create( [
			'game_id'     => $this->game_id,
			'object_type' => 'location',
			'name'        => 'The Old Mill',
			'created_by'  => 1,
		] );

		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $plot_a,
			'target_type' => 'character', 'target_id' => $npc_id, 'label' => 'cast',
		] );
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $plot_a,
			'target_type' => 'world_object', 'target_id' => $item_id, 'label' => 'uses',
		] );
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $plot_b,
			'target_type' => 'world_object', 'target_id' => $location_id, 'label' => 'at',
		] );

		$rows = Downtime_Window::queue_for_date( $this->game_id, '2026-10-02' );
		$by_plot = [];
		foreach ( $rows as $row ) {
			$by_plot[ $row['plot_id'] ] = $row['connections'];
		}

		$this->assertCount( 2, $by_plot[ $plot_a ] );
		$this->assertContains( [ 'type' => 'npc', 'id' => $npc_id, 'name' => 'An NPC' ], $by_plot[ $plot_a ] );
		$this->assertContains( [ 'type' => 'item', 'id' => $item_id, 'name' => 'A Silver Dagger' ], $by_plot[ $plot_a ] );

		$this->assertCount( 1, $by_plot[ $plot_b ] );
		$this->assertSame( [ 'type' => 'location', 'id' => $location_id, 'name' => 'The Old Mill' ], $by_plot[ $plot_b ][0] );

		// The plot's own primary actor is never repeated inside its own connections list.
		foreach ( $by_plot[ $plot_b ] as $connection ) {
			$this->assertNotSame( $other_character_id, $connection['id'] );
		}
	}
}
