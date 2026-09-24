<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Item_Event;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * An item's own history (`be_item_events`) and transferring it to a new character.
 */
class ItemHistoryThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-item-history';
	private int $game_id;
	private int $storyteller_id;
	private int $player_id;
	private int $character_id;
	private int $other_character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Item History' ] );

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		$this->character_id = (int) Character::create( [
			'name' => 'Holder', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id, 'created_by' => $this->storyteller_id,
		] );
		$this->other_character_id = (int) Character::create( [
			'name' => 'Recipient', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'created_by' => $this->storyteller_id,
		] );
	}

	private function make_item(): int {
		return (int) World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'A Relic', 'created_by' => $this->storyteller_id,
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// given/taken via an ordinary Connections_Controller add/remove.
	// -------------------------------------------------------------------------

	public function test_connecting_a_character_to_an_item_writes_given(): void {
		$item_id = $this->make_item();

		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/connections" );
		$request->set_body_params( [
			'source_type' => 'character', 'source_id' => $this->character_id,
			'target_type' => 'world_object', 'target_id' => $item_id,
		] );
		$this->dispatch( $request );

		$events = Item_Event::for_object( $item_id );
		$this->assertCount( 1, $events );
		$this->assertSame( 'given', $events[0]->event );
		$this->assertSame( $this->character_id, (int) $events[0]->character_id );
	}

	public function test_removing_the_connection_writes_taken(): void {
		$item_id = $this->make_item();
		$connection_id = (int) Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $this->character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'created_by' => $this->storyteller_id,
		] );

		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'DELETE', "/be/v1/{$this->slug}/connections/{$connection_id}" );
		$this->dispatch( $request );

		$events = Item_Event::for_object( $item_id );
		$this->assertCount( 1, $events );
		$this->assertSame( 'taken', $events[0]->event );
		$this->assertSame( $this->character_id, (int) $events[0]->character_id );
	}

	public function test_connecting_a_character_to_a_non_item_writes_nothing(): void {
		$location_id = (int) World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'location', 'name' => 'A Place', 'created_by' => $this->storyteller_id,
		] );

		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/connections" );
		$request->set_body_params( [
			'source_type' => 'character', 'source_id' => $this->character_id,
			'target_type' => 'world_object', 'target_id' => $location_id,
		] );
		$this->dispatch( $request );

		$this->assertSame( [], Item_Event::for_object( $location_id ) );
	}

	// -------------------------------------------------------------------------
	// Transfer.
	// -------------------------------------------------------------------------

	private function transfer( int $item_id, array $body ) {
		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/transfer" );
		$request->set_body_params( $body );
		return $this->dispatch( $request );
	}

	public function test_transferring_to_a_new_character_moves_the_connection_and_records_the_event(): void {
		$item_id = $this->make_item();
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $this->character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'created_by' => $this->storyteller_id,
		] );

		$response = $this->transfer( $item_id, [ 'to_character_id' => $this->other_character_id, 'how' => 'traded', 'note' => 'Fair trade.' ] );
		$this->assertSame( 200, $response->get_status() );

		$connections = Connection::for_entity( 'world_object', $item_id );
		$this->assertCount( 1, $connections );
		$this->assertSame( $this->other_character_id, (int) $connections[0]->source_id );

		$events = Item_Event::for_object( $item_id );
		$this->assertCount( 1, $events );
		$this->assertSame( 'traded', $events[0]->event );
		$this->assertSame( $this->character_id, (int) $events[0]->from_character_id );
		$this->assertSame( $this->other_character_id, (int) $events[0]->character_id );
	}

	public function test_a_lost_item_clears_every_holder_connection_with_no_new_one(): void {
		$item_id = $this->make_item();
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $this->character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'created_by' => $this->storyteller_id,
		] );

		$response = $this->transfer( $item_id, [ 'how' => 'lost' ] );
		$this->assertSame( 200, $response->get_status() );

		$this->assertSame( [], Connection::for_entity( 'world_object', $item_id ) );
		$events = Item_Event::for_object( $item_id );
		$this->assertSame( 'lost', $events[0]->event );
		$this->assertNull( $events[0]->character_id );
		$this->assertSame( $this->character_id, (int) $events[0]->from_character_id );
	}

	public function test_lost_ignores_an_accidentally_included_recipient(): void {
		$item_id  = $this->make_item();
		$response = $this->transfer( $item_id, [ 'how' => 'lost', 'to_character_id' => $this->other_character_id ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], Connection::for_entity( 'world_object', $item_id ) );
	}

	public function test_a_non_lost_transfer_with_no_recipient_is_refused(): void {
		$item_id  = $this->make_item();
		$response = $this->transfer( $item_id, [ 'how' => 'given' ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_an_invalid_how_is_refused(): void {
		$item_id  = $this->make_item();
		$response = $this->transfer( $item_id, [ 'how' => 'teleported', 'to_character_id' => $this->other_character_id ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_player_may_not_transfer_an_item(): void {
		$item_id = $this->make_item();

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/transfer" );
		$request->set_body_params( [ 'to_character_id' => $this->other_character_id, 'how' => 'given' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// proposed, via the real propose_world_object approval flow.
	// -------------------------------------------------------------------------

	public function test_approving_a_proposed_item_writes_proposed(): void {
		wp_set_current_user( $this->player_id );
		$propose_request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes" );
		$propose_request->set_body_params( [
			'change_type' => 'propose_world_object',
			'category'    => 'world_object',
			'change_data' => [ 'object_type' => 'item', 'name' => 'A Proposed Item' ],
		] );
		$change_id = (int) ( (array) $this->dispatch( $propose_request )->get_data() )['id'];

		wp_set_current_user( $this->storyteller_id );
		$approve_request = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}" );
		$approve_request->set_body_params( [ 'status' => 'approved' ] );
		$this->dispatch( $approve_request );

		$objects = World_Object::for_game( $this->game_id, [ 'per_page' => 50 ] );
		$made    = null;
		foreach ( $objects as $object ) {
			if ( $object->name === 'A Proposed Item' ) {
				$made = $object;
			}
		}
		$this->assertNotNull( $made );

		$events = Item_Event::for_object( (int) $made->id );
		$this->assertCount( 1, $events );
		$this->assertSame( 'proposed', $events[0]->event );
		$this->assertSame( $this->character_id, (int) $events[0]->character_id );
	}

	// -------------------------------------------------------------------------
	// adjusted, on a Storyteller's own uses/expiry edit.
	// -------------------------------------------------------------------------

	private function update_item( int $item_id, array $properties ) {
		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/world-objects/{$item_id}" );
		$request->set_body_params( [ 'properties' => $properties ] );
		return $this->dispatch( $request );
	}

	public function test_editing_uses_max_writes_adjusted(): void {
		$item_id = $this->make_item();

		$this->update_item( $item_id, [ 'uses_max' => 5, 'uses_left' => 5 ] );

		$events = Item_Event::for_object( $item_id );
		$this->assertCount( 1, $events );
		$this->assertSame( 'adjusted', $events[0]->event );
	}

	public function test_editing_an_unrelated_property_writes_nothing(): void {
		$item_id = $this->make_item();

		$this->update_item( $item_id, [ 'item_type' => 'Trinket' ] );

		$this->assertSame( [], Item_Event::for_object( $item_id ) );
	}

	public function test_saving_the_same_uses_value_again_writes_nothing(): void {
		$item_id = $this->make_item();
		$this->update_item( $item_id, [ 'uses_max' => 5, 'uses_left' => 5 ] );

		$this->update_item( $item_id, [ 'uses_max' => 5, 'uses_left' => 5 ] );

		$this->assertCount( 1, Item_Event::for_object( $item_id ) );
	}

	// -------------------------------------------------------------------------
	// GET .../events is staff-only.
	// -------------------------------------------------------------------------

	public function test_a_manager_can_read_an_items_history(): void {
		$item_id = $this->make_item();
		Item_Event::record( [ 'game_id' => $this->game_id, 'world_object_id' => $item_id, 'event' => 'given', 'character_id' => $this->character_id, 'recorded_by' => $this->storyteller_id ] );

		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/world-objects/{$item_id}/events" );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
	}

	public function test_a_player_cannot_read_an_items_history(): void {
		$item_id = $this->make_item();

		wp_set_current_user( $this->player_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/world-objects/{$item_id}/events" );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}
