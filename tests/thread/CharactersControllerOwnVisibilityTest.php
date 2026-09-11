<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Real security fix, not a UI nicety: `be_view_characters` is granted to every real WP
 * role (Capabilities::CAPS) - before this, `Characters_Controller::get_items()` and
 * `get_item()` had no ownership filter at all, so any logged-in player could read any
 * OTHER player's full character sheet, by browsing the list or by ID. Found by asking
 * the user directly to confirm the assumed "players only see their own character"
 * behavior actually held - it did not.
 */
class CharactersControllerOwnVisibilityTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-own-visibility-game';
	private int $player_a;
	private int $player_b;
	private int $character_a;
	private int $character_b;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Own Visibility Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) $wpdb->insert_id;

		$this->player_a = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->player_b = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		// player_a is the one every test below dispatches as; player_b is only ever the
		// OTHER character's owner, never the acting user, so only player_a needs a
		// membership row here.
		\BeyondElysium\Models\Game_Member::set_role( $game_id, $this->player_a, 'player' );

		$this->character_a = Character::create( [
			'name' => "Player A's Character", 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_a,
		] );
		$this->character_b = Character::create( [
			'name' => "Player B's Character", 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_b,
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_player_cannot_fetch_another_players_character_by_id(): void {
		wp_set_current_user( $this->player_a );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$this->character_b}" ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ownership_denied', $response->as_error()->get_error_code() );
	}

	public function test_a_player_only_sees_their_own_character_in_the_list(): void {
		wp_set_current_user( $this->player_a );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters" ) )->get_data();

		$this->assertSame( [ "Player A's Character" ], array_column( $data, 'name' ) );
	}

	public function test_a_player_can_still_fetch_their_own_character(): void {
		wp_set_current_user( $this->player_a );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$this->character_a}" ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_manager_still_sees_every_character(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters" ) )->get_data();

		$this->assertSame(
			[ "Player A's Character", "Player B's Character" ],
			array_column( $data, 'name' )
		);
	}

	public function test_a_manager_can_still_fetch_any_character_by_id(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$this->character_b}" ) );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_the_uuid_lookup_shortcut_also_respects_ownership(): void {
		$character = Character::find( $this->character_b );
		wp_set_current_user( $this->player_a );

		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters" );
		$request->set_param( 'uuid', $character->uuid );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame( [], $data, 'The uuid short-circuit path had its own separate ownership check to bypass.' );
	}
}
