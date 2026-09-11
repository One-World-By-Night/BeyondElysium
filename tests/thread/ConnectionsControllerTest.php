<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Both endpoints of a connection must exist and belong to the game before creation
 * (workflow-0.5.md Step 3h) - a dangling or cross-game connection is invisible
 * corruption, not a validation nicety.
 *
 * @see BE_PROCESS/workflow-0.5.md Step 3.2
 */
class ConnectionsControllerTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-connections-game';
	private int $character_id;

	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Connections Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		$this->character_id = Character::create( [
			'name' => 'Connection Test Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_manager_can_create_connection_between_two_real_characters(): void {
		$other_id = Character::create( [
			'name' => 'Second Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/connections" );
		$request->set_param( 'source_type', 'character' );
		$request->set_param( 'source_id', $this->character_id );
		$request->set_param( 'target_type', 'character' );
		$request->set_param( 'target_id', $other_id );
		$request->set_param( 'label', 'sister' );

		$this->assertSame( 201, $this->dispatch( $request )->get_status() );
	}

	public function test_connection_to_nonexistent_character_is_refused(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/connections" );
		$request->set_param( 'source_type', 'character' );
		$request->set_param( 'source_id', $this->character_id );
		$request->set_param( 'target_type', 'character' );
		$request->set_param( 'target_id', 999999 );

		$this->assertSame( 400, $this->dispatch( $request )->get_status() );
	}

	public function test_connection_to_character_in_another_game_is_refused(): void {
		$other_game_char = Character::create( [
			'name' => 'Foreign Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => 'thread-test-connections-other-game',
		] );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/connections" );
		$request->set_param( 'source_type', 'character' );
		$request->set_param( 'source_id', $this->character_id );
		$request->set_param( 'target_type', 'character' );
		$request->set_param( 'target_id', $other_game_char );

		$this->assertSame( 400, $this->dispatch( $request )->get_status() );
	}

	public function test_tag_target_does_not_require_a_target_id(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/connections" );
		$request->set_param( 'source_type', 'character' );
		$request->set_param( 'source_id', $this->character_id );
		$request->set_param( 'target_type', 'tag' );
		$request->set_param( 'label', 'Black Hand' );

		$this->assertSame( 201, $this->dispatch( $request )->get_status() );
	}

	public function test_player_cannot_create_connections(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $player );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/connections" );
		$request->set_param( 'source_type', 'character' );
		$request->set_param( 'source_id', $this->character_id );
		$request->set_param( 'target_type', 'tag' );
		$request->set_param( 'label', 'attempted' );

		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	public function test_bidirectional_entity_query_param(): void {
		$other_id = Character::create( [
			'name' => 'Third Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/connections" );
		$create->set_param( 'source_type', 'character' );
		$create->set_param( 'source_id', $other_id );
		$create->set_param( 'target_type', 'character' );
		$create->set_param( 'target_id', $this->character_id );
		$this->dispatch( $create );

		$query = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/connections" );
		$query->set_param( 'entity_type', 'character' );
		$query->set_param( 'entity_id', $this->character_id );
		$data = $this->dispatch( $query )->get_data();

		$this->assertCount( 1, $data );
	}
}
