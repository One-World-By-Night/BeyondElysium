<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 §3.12 item 2: an item with a set number of uses, or an expiry date - both derived
 * (`used_up`, `expired`), never stored, and a "use" route reachable by a manager or by a
 * player whose own character actually holds the item.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.12 item 2
 */
class ItemUsesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-item-uses';
	private int $game_id;
	private int $storyteller_id;
	private int $player_id;
	private int $character_id;
	private int $other_player_id;
	private int $other_character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Item Uses' ] );

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		$this->character_id = (int) Character::create( [
			'name' => 'Holder', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id, 'created_by' => $this->storyteller_id,
		] );

		$this->other_player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->other_player_id, 'player' );
		$this->other_character_id = (int) Character::create( [
			'name' => 'Someone Else', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->other_player_id, 'created_by' => $this->storyteller_id,
		] );
	}

	private function make_item( array $properties = [] ): int {
		return (int) World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'A Charm',
			'properties' => $properties, 'created_by' => $this->storyteller_id,
		] );
	}

	private function hold( int $item_id, int $character_id ): void {
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'label' => 'holds', 'created_by' => $this->storyteller_id,
		] );
	}

	private function use_item( int $wp_user_id, int $item_id, int $character_id, ?string $note = null ) {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/use" );
		$request->set_param( 'character_id', $character_id );
		if ( $note !== null ) {
			$request->set_param( 'note', $note );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function get_item( int $wp_user_id, int $item_id ) {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/world-objects/{$item_id}" );
		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Derived used_up/expired.
	// -------------------------------------------------------------------------

	public function test_used_up_is_false_when_uses_left_remain(): void {
		$item_id = $this->make_item( [ 'uses_max' => 3, 'uses_left' => 2 ] );

		$response = $this->get_item( $this->storyteller_id, $item_id );
		$this->assertFalse( $response->get_data()->used_up );
	}

	public function test_used_up_is_true_once_uses_left_reaches_zero(): void {
		$item_id = $this->make_item( [ 'uses_max' => 3, 'uses_left' => 0 ] );

		$response = $this->get_item( $this->storyteller_id, $item_id );
		$this->assertTrue( $response->get_data()->used_up );
	}

	public function test_an_item_with_no_uses_max_is_never_used_up(): void {
		$item_id = $this->make_item();

		$response = $this->get_item( $this->storyteller_id, $item_id );
		$this->assertFalse( $response->get_data()->used_up );
	}

	public function test_expired_is_true_for_a_past_date(): void {
		$item_id = $this->make_item( [ 'expires_on' => '2000-01-01' ] );

		$response = $this->get_item( $this->storyteller_id, $item_id );
		$this->assertTrue( $response->get_data()->expired );
	}

	public function test_expired_is_false_for_a_future_date(): void {
		$item_id = $this->make_item( [ 'expires_on' => '2999-01-01' ] );

		$response = $this->get_item( $this->storyteller_id, $item_id );
		$this->assertFalse( $response->get_data()->expired );
	}

	// -------------------------------------------------------------------------
	// The holder's own player may use it; a manager may use it for anyone.
	// -------------------------------------------------------------------------

	public function test_the_holders_own_player_may_use_the_item(): void {
		$item_id = $this->make_item( [ 'uses_max' => 3, 'uses_left' => 3 ] );
		$this->hold( $item_id, $this->character_id );

		$response = $this->use_item( $this->player_id, $item_id, $this->character_id, 'Cracked it open.' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()->properties['uses_left'] );
	}

	public function test_a_manager_may_use_an_item_for_any_character(): void {
		$item_id = $this->make_item( [ 'uses_max' => 3, 'uses_left' => 3 ] );
		$this->hold( $item_id, $this->character_id );

		$response = $this->use_item( $this->storyteller_id, $item_id, $this->character_id );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_different_players_character_may_not_use_someone_elses_item(): void {
		$item_id = $this->make_item( [ 'uses_max' => 3, 'uses_left' => 3 ] );
		$this->hold( $item_id, $this->character_id );

		// other_player owns other_character, but passes character_id as their OWN character,
		// which never holds this item - refused as not_holder, never touching another player's character.
		$response = $this->use_item( $this->other_player_id, $item_id, $this->other_character_id );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_player_may_not_use_an_item_through_a_character_that_is_not_their_own(): void {
		$item_id = $this->make_item( [ 'uses_max' => 3, 'uses_left' => 3 ] );
		$this->hold( $item_id, $this->character_id );

		$response = $this->use_item( $this->other_player_id, $item_id, $this->character_id );
		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Refusals: no uses, used up, expired.
	// -------------------------------------------------------------------------

	public function test_an_item_with_no_uses_max_refuses_with_400(): void {
		$item_id = $this->make_item();
		$this->hold( $item_id, $this->character_id );

		$response = $this->use_item( $this->storyteller_id, $item_id, $this->character_id );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'no_uses', $response->as_error()->get_error_code() );
	}

	public function test_a_used_up_item_refuses_with_409(): void {
		$item_id = $this->make_item( [ 'uses_max' => 3, 'uses_left' => 0 ] );
		$this->hold( $item_id, $this->character_id );

		$response = $this->use_item( $this->storyteller_id, $item_id, $this->character_id );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'used_up', $response->as_error()->get_error_code() );
	}

	public function test_an_expired_item_refuses_with_409(): void {
		$item_id = $this->make_item( [ 'uses_max' => 3, 'uses_left' => 3, 'expires_on' => '2000-01-01' ] );
		$this->hold( $item_id, $this->character_id );

		$response = $this->use_item( $this->storyteller_id, $item_id, $this->character_id );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'expired', $response->as_error()->get_error_code() );
	}

	public function test_uses_left_never_goes_below_zero(): void {
		$item_id = $this->make_item( [ 'uses_max' => 3, 'uses_left' => 1 ] );
		$this->hold( $item_id, $this->character_id );

		$first = $this->use_item( $this->storyteller_id, $item_id, $this->character_id );
		$this->assertSame( 0, $first->get_data()->properties['uses_left'] );

		$second = $this->use_item( $this->storyteller_id, $item_id, $this->character_id );
		$this->assertSame( 409, $second->get_status() );

		$item = World_Object::find( $item_id );
		$this->assertSame( 0, $item->properties['uses_left'] );
	}

	public function test_a_location_cannot_be_used(): void {
		$location_id = (int) World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'location', 'name' => 'A Place', 'created_by' => $this->storyteller_id,
		] );

		$response = $this->use_item( $this->storyteller_id, $location_id, $this->character_id );
		$this->assertSame( 409, $response->get_status() );
	}
}
