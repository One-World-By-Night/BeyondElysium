<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\User_Settings;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Route permission callbacks were chronicle-scoped.
 */
class ChronicleScopedHandlerChecksThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-scoped-checks';
	private int $game_id;
	private int $storyteller_elsewhere;
	private int $hst;
	private int $granted_player;
	private int $own_character;
	private int $other_character;
	private int $granted_character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) Game::find_by_slug( $this->slug )->id;

		// An editor site-wide (as every HST is) who is only a PLAYER in this chronicle.
		$this->storyteller_elsewhere = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_elsewhere, 'player' );

		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		$other_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $other_player, 'player' );

		$this->granted_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->granted_player, 'player' );
		update_user_meta( $this->granted_player, User_Settings::CUSTOMIZE_SHEET_META, '1' );

		// A real seeded stack - Change_Engine::submit() refuses a character whose stack does not exist.
		$this->own_character = Character::create( [
			'name' => 'Own Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->storyteller_elsewhere,
		] );
		$this->other_character = Character::create( [
			'name' => 'Other Player Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $other_player,
		] );
		$this->granted_character = Character::create( [
			'name' => 'Granted Player Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->granted_player,
		] );
	}

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_storyteller_from_elsewhere_sees_only_their_own_character_here(): void {
		wp_set_current_user( $this->storyteller_elsewhere );

		$data = $this->dispatch( 'GET', "/be/v1/{$this->slug}/characters" )->get_data();

		$this->assertSame( [ 'Own Character' ], array_column( $data, 'name' ) );
	}

	public function test_a_storyteller_from_elsewhere_cannot_open_another_players_sheet_here(): void {
		wp_set_current_user( $this->storyteller_elsewhere );

		$response = $this->dispatch( 'GET', "/be/v1/{$this->slug}/characters/{$this->other_character}" );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_storyteller_from_elsewhere_cannot_grant_themselves_xp_here(): void {
		wp_set_current_user( $this->storyteller_elsewhere );

		$response = $this->dispatch( 'POST', "/be/v1/{$this->slug}/characters/{$this->own_character}/changes", [
			'change_type' => 'xp_adjust',
			'category'    => 'experience',
			'change_data' => [ 'amount' => 50 ],
		] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()->status );
		$this->assertSame( 0, (int) Character::find( $this->own_character )->xp_earned );
	}

	public function test_a_storyteller_from_elsewhere_cannot_flag_their_character_an_npc_here(): void {
		wp_set_current_user( $this->storyteller_elsewhere );

		$this->dispatch( 'PUT', "/be/v1/{$this->slug}/characters/{$this->own_character}", [ 'is_npc' => true ] );

		$this->assertFalse( Character::find( $this->own_character )->is_npc );
	}

	public function test_this_chronicles_own_storyteller_still_sees_every_sheet(): void {
		wp_set_current_user( $this->hst );

		$data = $this->dispatch( 'GET', "/be/v1/{$this->slug}/characters" )->get_data();

		$this->assertSame(
			[ 'Granted Player Character', 'Other Player Character', 'Own Character' ],
			array_column( $data, 'name' )
		);
	}

	public function test_a_player_granted_sheet_customization_can_style_their_own_sheet(): void {
		wp_set_current_user( $this->granted_player );

		$response = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/characters/{$this->granted_character}/sheet-style", [
			'font_family' => 'Georgia, serif',
		] );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_player_granted_sheet_customization_cannot_style_someone_elses_sheet(): void {
		wp_set_current_user( $this->granted_player );

		$response = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/characters/{$this->other_character}/sheet-style", [
			'font_family' => 'Georgia, serif',
		] );

		$this->assertSame( 403, $response->get_status() );
	}
}
