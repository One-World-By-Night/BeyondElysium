<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The player dashboard's two routes: `GET /{game_slug}/my/characters` and `GET /{game_slug}/my/changes`.
 */
class PlayerDashboardRoutesTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-playerdash-game';
	private int $player_a;
	private int $player_b;
	private int $character_a;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Player Dashboard Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [ 'st_comment_start' => '[ST]', 'st_comment_end' => '[/ST]' ] ),
		] );

		$this->player_a    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->player_b    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->character_a = Character::create( [
			'name' => 'Player A Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_a,
			'notes' => 'Public part. [ST]Secretly a spy.[/ST] More public part.',
		] );
		Character::create( [
			'name' => 'Player B Character', 'stack_slug' => 'werewolf',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_b,
		] );
		Character::update_xp( $this->character_a, 10, 10 );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_my_characters_returns_only_the_current_users_own(): void {
		wp_set_current_user( $this->player_a );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/my/characters" );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$names = array_map( static fn( $c ) => $c->name, $response->get_data() );
		$this->assertSame( [ 'Player A Character' ], $names );
	}

	public function test_my_characters_strips_st_only_text_for_a_non_manager(): void {
		wp_set_current_user( $this->player_a );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/my/characters" );
		$response = $this->dispatch( $request );

		$notes = $response->get_data()[0]->notes;
		$this->assertStringNotContainsString( 'Secretly a spy', $notes, 'a player must never see [ST]-marked text on even their own character' );
		$this->assertStringContainsString( 'Public part.', $notes );
	}

	public function test_my_characters_does_not_strip_text_for_a_manager(): void {
		$st = self::factory()->user->create( [ 'role' => 'administrator' ] );
		// The manager plays their own character in this same chronicle.
		Character::create( [
			'name' => 'Manager Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $st,
			'notes' => 'Visible. [ST]Also visible to STs.[/ST]',
		] );

		wp_set_current_user( $st );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/my/characters" );
		$response = $this->dispatch( $request );

		$this->assertStringContainsString( 'Also visible to STs', $response->get_data()[0]->notes );
	}

	public function test_my_changes_returns_only_the_current_users_own_pending_changes(): void {
		wp_set_current_user( $this->player_a );
		$submit = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_a}/changes" );
		$submit->set_param( 'change_type', 'add_trait' );
		$submit->set_param( 'category', 'vampire-merits' );
		$submit->set_param( 'change_data', [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Iron Will' ] ] );
		$this->dispatch( $submit );

		wp_set_current_user( $this->player_b );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/my/changes" );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, $response->get_data(), "player B must not see player A's pending changes" );

		wp_set_current_user( $this->player_a );
		$mine = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/my/changes" ) )->get_data();
		$this->assertCount( 1, $mine );
		$this->assertSame( 'Player A Character', $mine[0]->character_name );
		$this->assertSame( 'st', $mine[0]->approval_level );
	}
}
