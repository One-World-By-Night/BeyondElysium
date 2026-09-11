<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * workflow-0.9.md Step 0.5d - "if there is no account to connect a character to, we
 * should be able to add the email address so when they make an account we can say 'hey,
 * they have an account now - connect them!'" Never auto-linked (an unverified email match
 * would hand the character to whoever registers that address first) - only surfaced as
 * `pending_match` for a manager to confirm via the existing wp_user_id assignment path.
 *
 * Also covers the related, user-requested change to the player/character relationship:
 * "player_name should follow wp_user_id" - once a real account is attached, the stored
 * free-text player_name is no longer authoritative.
 */
class CharacterPendingPlayerEmailTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-pending-email-game';
	private int $player_id;
	private int $admin_id;

	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Pending Email Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->admin_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function character( array $overrides = [] ): int {
		return Character::create( array_merge( [
			'name' => 'Pending Test Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		], $overrides ) );
	}

	public function test_a_manager_can_set_a_pending_email_on_an_unassigned_character(): void {
		$character_id = $this->character();

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'pending_player_email', 'future.player@example.test' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$found = Character::find( $character_id );
		$this->assertSame( 'future.player@example.test', $found->pending_player_email );
	}

	public function test_a_non_manager_cannot_set_a_pending_email(): void {
		$character_id = $this->character( [ 'wp_user_id' => $this->player_id ] );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'pending_player_email', 'sneaky@example.test' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$found = Character::find( $character_id );
		$this->assertNull( $found->pending_player_email, 'a non-manager must never be able to set this, even on their own character' );
	}

	public function test_an_invalid_email_is_rejected(): void {
		$character_id = $this->character();

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'pending_player_email', 'not-an-email' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_pending_match_surfaces_when_a_real_account_now_has_that_email_but_is_never_auto_linked(): void {
		$matching_user = self::factory()->user->create( [
			'role' => 'subscriber', 'user_email' => 'now.registered@example.test',
		] );
		$character_id = $this->character( [ 'pending_player_email' => 'now.registered@example.test' ] );

		wp_set_current_user( $this->admin_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $matching_user, $data->pending_match['id'] );
		$this->assertNull( $data->wp_user_id, 'a pending match is surfaced for confirmation, never auto-linked' );
	}

	public function test_pending_match_is_not_surfaced_to_a_non_manager(): void {
		self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'now.registered2@example.test' ] );
		$character_id = $this->character( [
			'wp_user_id' => $this->player_id,
			'pending_player_email' => 'now.registered2@example.test',
		] );

		wp_set_current_user( $this->player_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( property_exists( $data, 'pending_match' ), 'pending_match must not be surfaced to a non-manager at all' );
	}

	public function test_player_name_follows_wp_user_id_once_a_real_account_is_attached(): void {
		wp_update_user( [ 'ID' => $this->player_id, 'display_name' => 'Real Account Name' ] );
		$character_id = $this->character( [ 'player_name' => 'Some Stale Typed Name', 'wp_user_id' => $this->player_id ] );

		wp_set_current_user( $this->admin_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 'Real Account Name', $data->player_name );
	}

	public function test_assigning_wp_user_id_clears_a_stale_stored_player_name_and_pending_email(): void {
		$character_id = $this->character( [
			'player_name' => 'Stale Free Text', 'pending_player_email' => 'someone@example.test',
		] );

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'wp_user_id', $this->player_id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$found = Character::find( $character_id );
		$this->assertNull( $found->player_name, 'the stale stored value is cleaned up, not just masked at read time' );
		$this->assertNull( $found->pending_player_email, 'a real assignment makes the pending wait moot' );
	}

	public function test_player_name_stays_real_editable_free_text_with_no_account_attached(): void {
		$character_id = $this->character();

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'player_name', 'A Paper Character, No Login' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'A Paper Character, No Login', $response->get_data()->player_name );
	}
}
