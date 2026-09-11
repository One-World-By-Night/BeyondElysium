<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Step 1.5e, workflow-0.9.md - the operator surface for `be_game_members`. Before this
 * route existed the only way to grant, change or remove a chronicle role was calling
 * `Game_Member::set_role()`/`remove()` directly. Gated `be_manage_games` throughout -
 * chronicle membership is an HST-and-above act (Step 1.5b: `be_manage_games` is never
 * grantable per-game), matching how creating/deleting a chronicle already works.
 */
class GameMembersControllerTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-game-members-game';
	private int $game_id;
	private int $admin_id;
	private int $target_user_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Game Members Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->admin_id       = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->target_user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_manager_can_list_members_with_names_and_emails_enriched(): void {
		Game_Member::set_role( $this->game_id, $this->target_user_id, 'player' );
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/members" ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'player', $data[0]->role );
		$this->assertSame( get_userdata( $this->target_user_id )->user_email, $data[0]->user_email );
	}

	public function test_a_manager_can_add_a_member(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/members" );
		$request->set_param( 'wp_user_id', $this->target_user_id );
		$request->set_param( 'role', 'narrator' );
		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$member = Game_Member::find( $this->game_id, $this->target_user_id );
		$this->assertNotNull( $member );
		$this->assertSame( 'narrator', $member->role );
	}

	public function test_posting_again_changes_the_role_rather_than_erroring(): void {
		Game_Member::set_role( $this->game_id, $this->target_user_id, 'player' );
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/members" );
		$request->set_param( 'wp_user_id', $this->target_user_id );
		$request->set_param( 'role', 'ast' );
		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'ast', Game_Member::find( $this->game_id, $this->target_user_id )->role );
	}

	public function test_an_invalid_role_is_rejected(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/members" );
		$request->set_param( 'wp_user_id', $this->target_user_id );
		$request->set_param( 'role', 'not-a-real-role' );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( Game_Member::find( $this->game_id, $this->target_user_id ) );
	}

	public function test_a_nonexistent_wp_user_id_is_rejected(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/members" );
		$request->set_param( 'wp_user_id', 999999 );
		$request->set_param( 'role', 'player' );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_manager_can_remove_a_member(): void {
		Game_Member::set_role( $this->game_id, $this->target_user_id, 'player' );
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/members/{$this->target_user_id}" ) );
		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( Game_Member::find( $this->game_id, $this->target_user_id ) );
	}

	public function test_a_non_manager_is_denied_on_every_route(): void {
		Game_Member::set_role( $this->game_id, $this->target_user_id, 'hst' );
		wp_set_current_user( $this->target_user_id );

		$this->assertSame( 403, $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/members" ) )->get_status(), 'even an HST of this chronicle needs be_manage_games, not membership, to manage members' );

		$post = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/members" );
		$post->set_param( 'wp_user_id', $this->target_user_id );
		$post->set_param( 'role', 'hst' );
		$this->assertSame( 403, $this->dispatch( $post )->get_status() );

		$this->assertSame( 403, $this->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/members/{$this->target_user_id}" ) )->get_status() );
	}

	public function test_a_nonexistent_game_slug_is_a_404_not_a_membership_denial(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/thread-test-nonexistent-game/members' ) );
		$this->assertSame( 404, $response->get_status() );
	}
}
