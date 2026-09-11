<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Decision 076 (2026-09-10): user report - "import > match players doesn't work. Always
 * says there's an email match even when the email doesn't exist in the system and leaves
 * the character unassigned - and there's no way to later assign them." The "always says a
 * match" half traced to `GEX_Xml_Parser::parse_string()` hardcoding `players: []` for
 * every XML file (that format carries no player identity data at all) - a parser
 * limitation, not a matching bug, fixed by making `ImportTool.tsx`'s message honest about
 * it rather than by "fixing" a matcher that was already correct on empty input.
 *
 * This file covers the other half: `wp_user_id` (the real WP-account link, distinct from
 * the already-editable free-text `player_name`) had no update path at all - `create_item()`
 * could set it, `update_item()` never could, for anyone, manager or not. So an
 * import-created character, or any character whose player changes, had no way to ever be
 * (re)assigned.
 */
class AssignPlayerTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-assign-player-game';
	private int $admin_id;
	private int $player_id;

	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Assign Player Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		$this->admin_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->player_id = self::factory()->user->create( [
			'role' => 'subscriber', 'display_name' => 'Real Player One', 'user_email' => 'realplayer1@example.test',
		] );
	}

	private function make_character( ?int $wp_user_id = null ): int {
		return Character::create( [
			'name' => 'Assign Test Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $wp_user_id,
		] );
	}

	public function test_a_manager_can_assign_a_real_player(): void {
		$character_id = $this->make_character();

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'wp_user_id', $this->player_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->player_id, (int) $response->get_data()->wp_user_id );
	}

	public function test_a_manager_can_unassign_by_sending_zero(): void {
		$character_id = $this->make_character( $this->player_id );

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'wp_user_id', 0 );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()->wp_user_id );
	}

	public function test_omitting_the_field_entirely_leaves_an_existing_assignment_untouched(): void {
		$character_id = $this->make_character( $this->player_id );

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'status', 'active' ); // Touches the request at all, just not wp_user_id.
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			$this->player_id,
			(int) $response->get_data()->wp_user_id,
			'a request that never mentions wp_user_id must not clear an existing assignment'
		);
	}

	public function test_a_nonexistent_user_id_is_rejected(): void {
		$character_id = $this->make_character();

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'wp_user_id', 999999 );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_a_non_manager_cannot_assign_a_player_even_to_their_own_character(): void {
		$character_id = $this->make_character( $this->player_id );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'wp_user_id', $this->admin_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'the request itself is not rejected - the field is just silently ignored, same as is_npc' );
		$this->assertSame(
			$this->player_id,
			(int) $response->get_data()->wp_user_id,
			'a non-manager must never be able to reassign a character, including their own'
		);
	}

	public function test_search_wp_users_requires_be_manage_characters(): void {
		wp_set_current_user( $this->player_id );
		$request  = new WP_REST_Request( 'GET', '/be/v1/wp-users' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_search_wp_users_finds_by_display_name_or_email(): void {
		wp_set_current_user( $this->admin_id );

		$name_request = new WP_REST_Request( 'GET', '/be/v1/wp-users' );
		$name_request->set_param( 'search', 'Real Player' );
		$by_name = rest_get_server()->dispatch( $name_request )->get_data();
		$this->assertNotEmpty( array_filter( $by_name, fn( $u ) => $u['id'] === $this->player_id ) );

		$email_request = new WP_REST_Request( 'GET', '/be/v1/wp-users' );
		$email_request->set_param( 'search', 'realplayer1@example.test' );
		$by_email = rest_get_server()->dispatch( $email_request )->get_data();
		$this->assertNotEmpty( array_filter( $by_email, fn( $u ) => $u['id'] === $this->player_id ) );
	}
}
