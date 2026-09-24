<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Staff_Queue;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Staff assignment (`plots.assigned_to`/`characters.assigned_to`, restricted to a real hst/ast/narrator member of the
 * chronicle) and My Queue's four sections.
 */
class StaffQueueThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-staff-queue';
	private int $game_id;
	private int $player_id;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Staff Queue',
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

	private function make_manager( string $role = 'hst' ): int {
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $user, $role );
		return $user;
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function make_action_plot( string $game_date ): int {
		$character = Character::find( $this->character_id );
		return (int) Action_Allocator::create_own_plot( $character, $game_date );
	}

	private function make_plot( string $title = 'A Plot' ): int {
		return (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => $title, 'created_by' => 1, 'audience' => 'everyone' ] );
	}

	// -------------------------------------------------------------------------
	// Assignment validation
	// -------------------------------------------------------------------------

	public function test_a_plot_cannot_be_assigned_to_a_non_staff_member(): void {
		$plot_id = $this->make_plot();
		wp_set_current_user( $this->make_manager() );

		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$request->set_param( 'assigned_to', $this->player_id );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_assignee', $response->as_error()->get_error_code() );
	}

	public function test_a_plot_can_be_assigned_to_a_narrator(): void {
		$plot_id  = $this->make_plot();
		$narrator = $this->make_manager( 'narrator' );
		wp_set_current_user( $this->make_manager() );

		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$request->set_param( 'assigned_to', $narrator );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $narrator, (int) Plot::find( $plot_id )->assigned_to );
	}

	public function test_an_npc_can_be_assigned_but_a_pc_cannot(): void {
		$manager = $this->make_manager();
		$npc_id  = (int) Character::create( [
			'name' => 'A Random Ghoul', 'stack_slug' => 'vampire', 'owner_slug' => $this->game_slug,
			'status' => 'active', 'is_npc' => true, 'created_by' => 1,
		] );

		wp_set_current_user( $manager );
		$npc_request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$npc_id}" );
		$npc_request->set_param( 'assigned_to', $manager );
		$this->dispatch( $npc_request );
		$this->assertSame( $manager, (int) Character::find( $npc_id )->assigned_to );

		$pc_request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$this->character_id}" );
		$pc_request->set_param( 'assigned_to', $manager );
		$this->dispatch( $pc_request );
		$this->assertNull( Character::find( $this->character_id )->assigned_to, 'assigned_to is silently ignored on a PC, not rejected' );
	}

	// -------------------------------------------------------------------------
	// My Queue sections
	// -------------------------------------------------------------------------

	public function test_downtime_lists_an_unanswered_action_plot_assigned_to_me(): void {
		$manager = $this->make_manager();
		$plot_id = $this->make_action_plot( '2026-10-02' );
		Plot::update( $plot_id, [ 'assigned_to' => $manager ] );

		wp_set_current_user( $this->player_id );
		$action_request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$action_request->set_param( 'entry_type', 'action' );
		$action_request->set_param( 'content', 'I go looking for trouble.' );
		$this->dispatch( $action_request );

		$rows = Staff_Queue::downtime( $manager, $this->game_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( $plot_id, $rows[0]['plot_id'] );
	}

	public function test_downtime_excludes_an_already_answered_plot(): void {
		$manager = $this->make_manager();
		$plot_id = $this->make_action_plot( '2026-10-02' );
		Plot::update( $plot_id, [ 'assigned_to' => $manager ] );

		wp_set_current_user( $this->player_id );
		$action_request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$action_request->set_param( 'entry_type', 'action' );
		$action_request->set_param( 'content', 'I go looking for trouble.' );
		$this->dispatch( $action_request );

		wp_set_current_user( $manager );
		$response_request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$response_request->set_param( 'entry_type', 'response' );
		$response_request->set_param( 'content', 'You find some.' );
		$response_request->set_param( 'held', false );
		$this->dispatch( $response_request );

		$this->assertSame( [], Staff_Queue::downtime( $manager, $this->game_id ) );
	}

	public function test_plots_lists_an_assigned_plot_waiting_on_my_reply(): void {
		$manager = $this->make_manager();
		$plot_id = $this->make_plot( 'A Mystery' );
		Plot::update( $plot_id, [ 'assigned_to' => $manager ] );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$request->set_param( 'entry_type', 'action' );
		$request->set_param( 'content', 'What is going on here?' );
		$this->dispatch( $request );

		$rows = Staff_Queue::plots( $manager, $this->game_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( $plot_id, $rows[0]['plot_id'] );
	}

	public function test_plots_excludes_a_plot_i_have_already_replied_to(): void {
		$manager = $this->make_manager();
		$plot_id = $this->make_plot( 'A Mystery' );
		Plot::update( $plot_id, [ 'assigned_to' => $manager ] );

		wp_set_current_user( $this->player_id );
		$action_request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$action_request->set_param( 'entry_type', 'action' );
		$action_request->set_param( 'content', 'What is going on here?' );
		$this->dispatch( $action_request );

		wp_set_current_user( $manager );
		$reply_request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$reply_request->set_param( 'entry_type', 'resolution' );
		$reply_request->set_param( 'content', 'Here is what is going on.' );
		$this->dispatch( $reply_request );

		$this->assertSame( [], Staff_Queue::plots( $manager, $this->game_id ) );
	}

	public function test_unassigned_counts_unowned_unanswered_downtime_and_plots(): void {
		$plot_id = $this->make_action_plot( '2026-10-02' );
		wp_set_current_user( $this->player_id );
		$action_request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$action_request->set_param( 'entry_type', 'action' );
		$action_request->set_param( 'content', 'I do a thing.' );
		$this->dispatch( $action_request );

		$other_plot_id = $this->make_plot( 'Unowned' );
		$post_request   = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$other_plot_id}/entries" );
		$post_request->set_param( 'entry_type', 'action' );
		$post_request->set_param( 'content', 'Anybody there?' );
		$this->dispatch( $post_request );

		$counts = Staff_Queue::unassigned( $this->game_id );
		$this->assertSame( 1, $counts['downtime'] );
		$this->assertSame( 1, $counts['plots'] );
	}

	public function test_a_player_cannot_reach_my_queue(): void {
		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/my/queue" ) );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_manager_reaches_my_queue_with_all_four_sections(): void {
		wp_set_current_user( $this->make_manager() );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/my/queue" ) );

		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		$this->assertArrayHasKey( 'downtime', $data );
		$this->assertArrayHasKey( 'plots', $data );
		$this->assertArrayHasKey( 'castings', $data );
		$this->assertArrayHasKey( 'unassigned', $data );
	}
}
