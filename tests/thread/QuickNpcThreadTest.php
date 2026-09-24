<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * An NPC is created as a quick or a full one: a Storyteller can create a quick NPC and upgrade it to full, an NPC
 * defaults to full, a player character is always full, and a player cannot flag a character quick or change its detail.
 */
class QuickNpcThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-quick-npc';
	private int $game_id;
	private int $storyteller_id;
	private int $player_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Quick NPC',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function create_character( int $as_user, array $params ): \WP_REST_Response {
		wp_set_current_user( $as_user );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Creation: Quick or Full, Storyteller only.
	// -------------------------------------------------------------------------

	public function test_a_storyteller_can_create_a_quick_npc(): void {
		$response = $this->create_character( $this->storyteller_id, [
			'name' => 'A Quick NPC', 'stack_slug' => 'vampire', 'is_npc' => true, 'npc_detail' => 'quick',
		] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'quick', $response->get_data()->npc_detail );
	}

	public function test_an_npc_defaults_to_full_when_npc_detail_is_omitted(): void {
		$response = $this->create_character( $this->storyteller_id, [
			'name' => 'A Default NPC', 'stack_slug' => 'vampire', 'is_npc' => true,
		] );

		$this->assertSame( 'full', $response->get_data()->npc_detail );
	}

	public function test_a_player_character_is_always_full_regardless_of_what_is_sent(): void {
		$response = $this->create_character( $this->player_id, [
			'name' => 'A Player Character', 'stack_slug' => 'vampire', 'npc_detail' => 'quick',
		] );

		$this->assertSame( 'full', $response->get_data()->npc_detail );
	}

	public function test_a_non_manager_cannot_flag_their_own_character_quick_even_as_an_npc(): void {
		// A non-manager's is_npc claim is never trusted either (pre-existing behavior).
		$response = $this->create_character( $this->player_id, [
			'name' => 'Not Really An NPC', 'stack_slug' => 'vampire', 'is_npc' => true, 'npc_detail' => 'quick',
		] );

		$this->assertFalse( $response->get_data()->is_npc );
		$this->assertSame( 'full', $response->get_data()->npc_detail );
	}

	// -------------------------------------------------------------------------
	// "Make Full NPC": Quick -> Full, Storyteller only.
	// -------------------------------------------------------------------------

	public function test_a_storyteller_can_upgrade_a_quick_npc_to_full(): void {
		$id = (int) Character::create( [
			'name' => 'Upgrade Me', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug, 'is_npc' => 1, 'npc_detail' => 'quick', 'created_by' => $this->storyteller_id,
		] );

		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$id}" );
		$request->set_param( 'npc_detail', 'full' );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'full', Character::find( $id )->npc_detail );
	}

	public function test_an_invalid_npc_detail_value_is_rejected(): void {
		$id = (int) Character::create( [
			'name' => 'Bad Value', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug, 'is_npc' => 1, 'created_by' => $this->storyteller_id,
		] );

		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$id}" );
		$request->set_param( 'npc_detail', 'medium' );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'full', Character::find( $id )->npc_detail );
	}

	public function test_a_player_cannot_change_npc_detail_on_their_own_character(): void {
		$id = (int) Character::create( [
			'name' => 'Mine', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_id, 'created_by' => $this->player_id,
		] );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$id}" );
		$request->set_param( 'npc_detail', 'quick' );
		$this->dispatch( $request );

		$this->assertSame( 'full', Character::find( $id )->npc_detail );
	}

	// -------------------------------------------------------------------------
	// Template resolution: npc_quick is a real, resolvable template.
	// -------------------------------------------------------------------------

	public function test_the_npc_quick_template_resolves_for_a_seeded_stack(): void {
		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/templates/resolve" );
		$request->set_param( 'stack_slug', 'vampire' );
		$request->set_param( 'template_type', 'npc_quick' );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$slugs = array_column( $response->get_data()['template']['layout']['sections'], 'block_slug' );
		$this->assertContains( 'npc-quick-stats', $slugs );
		$this->assertContains( 'npc-roleplaying-notes', $slugs );
	}
}
