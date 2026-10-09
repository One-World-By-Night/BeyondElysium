<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `be_view_characters` is granted to every real WP role (Capabilities::CAPS), so a player's change history is scoped
 * to their own characters.
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

	private function add_host_note(): void {
		\BeyondElysium\Models\Change::create( [
			'character_id' => $this->character_a,
			'change_type'  => 'visit_note',
			'category'     => 'visit',
			'change_data'  => [ 'note' => 'Host storytellers only: plays fast and loose.' ],
			'xp_cost'      => 0,
			'status'       => 'pending',
			'submitted_by' => 0,
		] );
		\BeyondElysium\Models\Change::create( [
			'character_id' => $this->character_a,
			'change_type'  => 'xp_earn',
			'category'     => 'experience',
			'change_data'  => [ 'amount' => 2 ],
			'xp_cost'      => 0,
			'status'       => 'pending',
			'submitted_by' => $this->player_a,
		] );
	}

	private function types_in( $response ): array {
		return array_map( static fn( $change ) => is_array( $change ) ? $change['change_type'] : $change->change_type, (array) $response->get_data() );
	}

	public function test_a_player_never_sees_a_host_storytellers_note_on_their_own_character(): void {
		$this->add_host_note();
		wp_set_current_user( $this->player_a );

		$history = $this->dispatch( $this->changes_request( $this->character_a ) );
		$this->assertSame( [ 'xp_earn' ], $this->types_in( $history ) );
		$this->assertSame( '1', (string) $history->get_headers()['X-WP-Total'] );

		$in_game = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/my/changes" ) );
		$this->assertSame( [ 'xp_earn' ], $this->types_in( $in_game ) );

		$across = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/my/changes' ) );
		$this->assertStringNotContainsString( 'fast and loose', (string) wp_json_encode( $across->get_data() ) );
	}

	public function test_a_manager_still_sees_a_host_storytellers_note(): void {
		$this->add_host_note();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$history = $this->dispatch( $this->changes_request( $this->character_a ) );
		$this->assertContains( 'visit_note', $this->types_in( $history ) );
	}

	public function test_a_manager_can_list_any_characters_change_history(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$response = $this->dispatch( $this->changes_request( $this->character_b ) );

		$this->assertSame( 200, $response->get_status() );
	}
}
