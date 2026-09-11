<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * NPCs must never reach a non-manager, regardless of what the request asks for
 * (workflow-0.3.md Step 6f). Found with no server-side enforcement at all while
 * building CharacterList - `is_npc` was a plain pass-through filter.
 *
 * @see BE_PROCESS/workflow-0.3.md Step 6f
 */
class CharactersControllerNpcVisibilityTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-npc-game';
	private int $player_id;

	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test NPC Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) $wpdb->insert_id;

		// Owned by the player fixture below - a non-manager only ever sees their own
		// character (the visibility fix this file predates), so an unowned fixture would
		// be invisible to every "player" test case regardless of NPC status.
		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		\BeyondElysium\Models\Game_Member::set_role( $game_id, $this->player_id, 'player' );

		Character::create( [
			'name' => 'A Player Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'is_npc' => 0,
			'wp_user_id' => $this->player_id,
		] );
		Character::create( [
			'name' => 'An NPC', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'is_npc' => 1,
		] );
	}

	private function list_names( int $user_id, bool $request_npcs ): array {
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters" );
		if ( $request_npcs ) {
			$request->set_param( 'is_npc', 1 );
		}

		$data = rest_get_server()->dispatch( $request )->get_data();
		return array_column( $data, 'name' );
	}

	public function test_non_manager_never_sees_npcs_even_when_requested(): void {
		$this->assertSame( [ 'A Player Character' ], $this->list_names( $this->player_id, true ) );
	}

	public function test_non_manager_sees_pcs_by_default(): void {
		$this->assertSame( [ 'A Player Character' ], $this->list_names( $this->player_id, false ) );
	}

	public function test_manager_sees_npcs_when_requested(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->assertSame( [ 'An NPC' ], $this->list_names( $admin, true ) );
	}

	public function test_manager_does_not_see_npcs_unless_requested(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->assertSame( [ 'A Player Character' ], $this->list_names( $admin, false ) );
	}
}
