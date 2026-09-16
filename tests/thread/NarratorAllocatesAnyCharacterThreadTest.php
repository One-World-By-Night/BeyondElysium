<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Owner ruling, 1.0.0-checklist.md item 19: a Narrator allocates actions for any
 * character in the chronicle, not just one they happen to own as a player. A Narrator
 * holds `be_manage_plots` (game-roles.php) - enough to call the allocate-actions route
 * for any character_id - but not `be_manage_characters`, so
 * `Characters_Controller::get_items()`'s D33 ownership filter forced the character
 * picker Action Allocator reads from down to their own characters only, usually none.
 * Fixed narrowly: the `wp_user_id` override alone (not `is_npc` visibility, not any
 * edit/delete/create path) also opens for a `be_manage_plots` holder.
 */
class NarratorAllocatesAnyCharacterThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-narrator-allocates-game';
	private int $narrator;
	private int $player;
	private int $character;
	private int $npc;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Narrator Allocates Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) $wpdb->insert_id;

		// A chronicle role only narrows a site-wide grant, never widens one (F-104) - be_manage_plots
		// is editor-and-above only (Capabilities::CAPS), so a usable Narrator account must be at
		// least an editor, exactly like every other Storyteller-tier role this test suite sets up.
		$this->narrator = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->player   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->narrator, 'narrator' );
		Game_Member::set_role( $game_id, $this->player, 'player' );

		$this->character = Character::create( [
			'name' => "Player's Character", 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player,
		] );
		$this->npc = Character::create( [
			'name' => 'A Storyteller NPC', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'is_npc' => 1,
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_narrator_sees_a_character_they_do_not_own_in_the_list(): void {
		wp_set_current_user( $this->narrator );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters" ) )->get_data();

		$this->assertSame( [ "Player's Character" ], array_column( $data, 'name' ) );
	}

	public function test_a_narrator_can_fetch_a_character_they_do_not_own_by_id(): void {
		wp_set_current_user( $this->narrator );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$this->character}" ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_narrator_still_cannot_edit_a_character_they_do_not_own(): void {
		wp_set_current_user( $this->narrator );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$this->character}" );
		$request->set_param( 'name', 'Renamed by a Narrator' );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status(), 'Seeing the roster for allocation must not grant edit rights.' );
	}

	public function test_a_narrator_still_does_not_see_npcs_by_default(): void {
		wp_set_current_user( $this->narrator );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters" ) )->get_data();

		$this->assertNotContains( 'A Storyteller NPC', array_column( $data, 'name' ), 'NPC visibility is a separate grant, untouched by this fix.' );
	}

	public function test_a_plain_player_is_still_restricted_to_their_own_character(): void {
		wp_set_current_user( $this->player );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters" ) )->get_data();

		$this->assertSame( [ "Player's Character" ], array_column( $data, 'name' ) );
	}
}
