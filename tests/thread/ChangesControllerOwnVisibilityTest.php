<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Real security fix, not a UI nicety: `be_view_characters` is granted to every real WP
 * role (Capabilities::CAPS) - before this, `Changes_Controller::get_items()` had no
 * ownership filter at all, so any logged-in player could read any OTHER player's full
 * character change history (every trait purchase, XP amount, and ST reviewer note) by
 * ID, the same D33-class gap `CharactersControllerOwnVisibilityTest` already closed for
 * the character sheet itself. Found while adding an on-screen "view history" surface for
 * this same data (0.99.X-Ideas.md "Character audit trail") - the route it would call had
 * never actually been checked against a second player's character.
 */
class ChangesControllerOwnVisibilityTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-changes-visibility-game';
	private int $player_a;
	private int $player_b;
	private int $character_a;
	private int $character_b;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Changes Visibility Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		$this->player_a = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->player_b = self::factory()->user->create( [ 'role' => 'subscriber' ] );

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

	private function changes_request( int $character_id ): WP_REST_Request {
		return new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$character_id}/changes" );
	}

	public function test_a_player_cannot_list_another_players_change_history(): void {
		wp_set_current_user( $this->player_a );
		$response = $this->dispatch( $this->changes_request( $this->character_b ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ownership_denied', $response->as_error()->get_error_code() );
	}

	public function test_a_player_can_still_list_their_own_change_history(): void {
		wp_set_current_user( $this->player_a );
		$response = $this->dispatch( $this->changes_request( $this->character_a ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_manager_can_list_any_characters_change_history(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$response = $this->dispatch( $this->changes_request( $this->character_b ) );

		$this->assertSame( 200, $response->get_status() );
	}
}
