<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Attendance;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player signs their own character in at a session through the self-service check-in route, distinct from the
 * Storyteller-only attendance route the same model already serves.
 */
class SessionCheckInThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-session-check-in';
	private int $game_id;
	private int $storyteller_id;
	private int $player_id;
	private int $character_id;
	private int $other_player_id;
	private int $other_character_id;
	private int $npc_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Session Check-In' ] );

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		$this->character_id = (int) Character::create( [
			'name' => 'Attendee', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id, 'created_by' => $this->storyteller_id,
		] );

		$this->other_player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->other_player_id, 'player' );
		$this->other_character_id = (int) Character::create( [
			'name' => 'Someone Else', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->other_player_id, 'created_by' => $this->storyteller_id,
		] );

		$this->npc_id = (int) Character::create( [
			'name' => 'An NPC', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'is_npc' => 1, 'created_by' => $this->storyteller_id,
		] );
	}

	private function make_session(): int {
		return (int) Game_Session::create( [
			'game_id' => $this->game_id, 'game_date' => gmdate( 'Y-m-d' ), 'created_by' => $this->storyteller_id,
		] );
	}

	private function check_in( int $wp_user_id, int $session_id, int $character_id ) {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/check-in" );
		$request->set_body_params( [ 'character_id' => $character_id ] );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_player_can_sign_their_own_character_in(): void {
		$session_id = $this->make_session();

		$response = $this->check_in( $this->player_id, $session_id, $this->character_id );

		$this->assertSame( 201, $response->get_status() );
		$this->assertContains( $this->character_id, Attendance::character_ids_for_session( $session_id ) );
	}

	public function test_a_player_cannot_sign_someone_elses_character_in(): void {
		$session_id = $this->make_session();

		$response = $this->check_in( $this->player_id, $session_id, $this->other_character_id );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_signing_in_twice_is_refused(): void {
		$session_id = $this->make_session();
		$this->check_in( $this->player_id, $session_id, $this->character_id );

		$response = $this->check_in( $this->player_id, $session_id, $this->character_id );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_an_npc_cannot_be_signed_in_through_this_route(): void {
		$session_id = $this->make_session();
		wp_set_current_user( $this->storyteller_id );
		Character::update_header( $this->npc_id, [ 'wp_user_id' => $this->storyteller_id ] );

		$response = $this->check_in( $this->storyteller_id, $session_id, $this->npc_id );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_storyteller_can_still_sign_their_own_character_in_this_way_too(): void {
		$session_id = $this->make_session();
		$storyteller_character_id = (int) Character::create( [
			'name' => 'Storyteller PC', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->storyteller_id, 'created_by' => $this->storyteller_id,
		] );

		$response = $this->check_in( $this->storyteller_id, $session_id, $storyteller_character_id );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_this_route_never_lets_a_player_sign_in_a_visitor(): void {
		$session_id = $this->make_session();
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/check-in" );
		$request->set_body_params( [ 'visitor_name' => 'A Visitor' ] );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}
}
