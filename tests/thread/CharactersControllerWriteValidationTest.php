<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Character writes are validated: `is_npc` is capability-gated on create and update and is a real boolean over REST, an
 * invalid status or malformed start date is rejected, and a portrait must be a real attachment.
 */
class CharactersControllerWriteValidationTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-char-write-game';
	private int $player_id;

	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Char Write Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) $wpdb->insert_id;

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		\BeyondElysium\Models\Game_Member::set_role( $game_id, $this->player_id, 'player' );
	}

	/**
	 * A Storyteller of this test's chronicle.
	 */
	private function storyteller(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$game    = \BeyondElysium\Models\Game::find_by_slug( $this->game_slug );
		\BeyondElysium\Models\Game_Member::set_role( (int) $game->id, $user_id, 'hst' );
		return $user_id;
	}

	public function test_a_player_cannot_set_is_npc_on_create(): void {
		wp_set_current_user( $this->player_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters" );
		$request->set_param( 'name', 'Sneaky NPC Attempt' );
		$request->set_param( 'stack_slug', 'vampire' );
		$request->set_param( 'is_npc', true );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( 0, (int) $data->is_npc, 'A non-manager must never be able to set is_npc, even on a character they own.' );
	}

	public function test_a_player_cannot_set_is_npc_on_update(): void {
		$character_id = Character::create( [
			'name' => 'Owned Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $this->player_id, 'is_npc' => 0,
		] );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'is_npc', true );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( 0, (int) $data->is_npc, 'A non-manager editing their own character must never be able to flip is_npc.' );
	}

	public function test_a_manager_can_set_is_npc_on_update(): void {
		$admin_id     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$character_id = Character::create( [
			'name' => 'Manager-Owned Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'is_npc' => 0,
		] );

		wp_set_current_user( $admin_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'is_npc', true );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( 1, (int) $data->is_npc, 'A manager must still be able to set is_npc - only non-managers are blocked.' );
	}

	public function test_is_npc_is_a_real_boolean_over_rest_not_a_truthy_string(): void {
		$admin_id     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$character_id = Character::create( [
			'name' => 'Boolean Shape Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'is_npc' => 0,
		] );

		wp_set_current_user( $admin_id );
		$get_request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$data        = rest_get_server()->dispatch( $get_request )->get_data();

		$this->assertIsBool( $data->is_npc, 'is_npc must be a real bool, not the truthy string "0" that $wpdb returns for every tinyint column.' );
		$this->assertFalse( $data->is_npc );

		$update_request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$update_request->set_param( 'is_npc', true );
		$updated = rest_get_server()->dispatch( $update_request )->get_data();

		$this->assertIsBool( $updated->is_npc );
		$this->assertTrue( $updated->is_npc );
	}

	public function test_an_invalid_status_is_rejected_on_create(): void {
		wp_set_current_user( $this->player_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters" );
		$request->set_param( 'name', 'Bad Status Character' );
		$request->set_param( 'stack_slug', 'vampire' );
		$request->set_param( 'status', 'deceased-but-fabulous' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_an_invalid_status_is_rejected_on_update(): void {
		$character_id = Character::create( [
			'name' => 'Status Test Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $this->player_id,
		] );

		// Status is a Storyteller's to set.
		wp_set_current_user( $this->storyteller() );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'status', 'not-a-real-status' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_a_malformed_start_date_is_rejected(): void {
		$character_id = Character::create( [
			'name' => 'Date Test Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $this->player_id,
		] );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'start_date', 'next tuesday' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_a_valid_status_and_date_are_accepted(): void {
		$character_id = Character::create( [
			'name' => 'Valid Update Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $this->player_id,
		] );

		wp_set_current_user( $this->storyteller() );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'status', 'retired' );
		$request->set_param( 'start_date', '2026-01-15' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'retired', $data->status );
		$this->assertSame( '2026-01-15', $data->start_date );
	}

	/**
	 * A player setting their own character's portrait is a self-service edit like player_name/biography above.
	 */
	public function test_a_non_attachment_image_id_is_rejected(): void {
		$character_id = Character::create( [
			'name' => 'Portrait Test Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $this->player_id,
		] );

		$not_an_attachment = self::factory()->post->create( [ 'post_type' => 'post' ] );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'image_id', $not_an_attachment );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_a_player_can_set_their_own_portrait_and_it_resolves_to_a_url(): void {
		$character_id = Character::create( [
			'name' => 'Portrait Test Character Two', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $this->player_id,
		] );

		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			0
		);

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'image_id', $attachment_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $attachment_id, (int) $data->image_id );

		// image_url is resolved fresh on every GET.
		$get_request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$get_response = rest_get_server()->dispatch( $get_request );
		$get_data     = $get_response->get_data();

		$this->assertNotEmpty( $get_data->image_url, 'image_id alone is not useful to a client - the whole point of Decision 054 is a real, fetchable URL.' );
		$this->assertStringContainsString( 'canola', $get_data->image_url );
	}
}
