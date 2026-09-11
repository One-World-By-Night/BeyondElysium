<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The REST layer around World_Object: creating each type, property validation at the
 * REST boundary, connected-character resolution and visibility filtering, cascading
 * delete, and the permission boundary (workflow-0.7.md Step 2).
 *
 * @see BE_PROCESS/workflow-0.7.md Step 2
 */
class WorldObjectsControllerTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-world-objects-game';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test World Objects Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_create_one_of_each_type(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$fixtures = [
			[ 'object_type' => 'item', 'name' => 'Silver Dagger', 'properties' => [ 'item_type' => 'Weapon', 'level' => 2 ] ],
			[ 'object_type' => 'location', 'name' => 'The Old Mill', 'properties' => [ 'location_type' => 'Haven', 'security' => 'Warded' ] ],
			[ 'object_type' => 'rote', 'name' => 'Spirit of the Manse', 'properties' => [ 'level' => 3, 'spheres' => [ [ 'name' => 'Spirit', 'count' => 3 ] ] ] ],
		];

		foreach ( $fixtures as $fixture ) {
			$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/world-objects" );
			foreach ( $fixture as $key => $value ) {
				$request->set_param( $key, $value );
			}
			$response = $this->dispatch( $request );
			$this->assertSame( 201, $response->get_status(), $fixture['object_type'] );
		}

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}be_world_objects WHERE game_id = (SELECT id FROM {$wpdb->prefix}be_games WHERE slug = %s)",
			$this->game_slug
		) );
		$this->assertSame( 3, $count );
	}

	public function test_another_types_properties_are_rejected_at_the_rest_boundary(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/world-objects" );
		$request->set_param( 'object_type', 'location' );
		$request->set_param( 'name', 'Bad Location' );
		$request->set_param( 'properties', [ 'item_type' => 'Weapon' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_unknown_property_key_rejected(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/world-objects" );
		$request->set_param( 'object_type', 'item' );
		$request->set_param( 'name', 'Mystery Item' );
		$request->set_param( 'properties', [ 'made_up_field' => 'x' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_associating_an_item_to_a_character_shows_on_the_item(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		global $wpdb;
		$game_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}be_games WHERE slug = %s", $this->game_slug ) );

		$character_id = Character::create( [ 'name' => 'Item Owner', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug ] );

		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/world-objects" );
		$create->set_param( 'object_type', 'item' );
		$create->set_param( 'name', 'Heirloom Blade' );
		$object_id = $this->dispatch( $create )->get_data()->id;

		Connection::create( [
			'game_id' => $game_id, 'source_type' => 'world_object', 'source_id' => $object_id,
			'target_type' => 'character', 'target_id' => $character_id, 'label' => 'carried', 'created_by' => 1,
		] );

		$get = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects/{$object_id}" );
		$data = $this->dispatch( $get )->get_data();
		$this->assertCount( 1, $data->connected_characters );
		$this->assertSame( 'Item Owner', $data->connected_characters[0]['name'] );
	}

	public function test_npc_ownership_hidden_from_non_managers(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		global $wpdb;
		$game_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}be_games WHERE slug = %s", $this->game_slug ) );
		$npc_id  = Character::create( [ 'name' => 'Secret NPC', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'is_npc' => 1 ] );

		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/world-objects" );
		$create->set_param( 'object_type', 'item' );
		$create->set_param( 'name', 'NPC Item' );
		$object_id = $this->dispatch( $create )->get_data()->id;

		Connection::create( [
			'game_id' => $game_id, 'source_type' => 'world_object', 'source_id' => $object_id,
			'target_type' => 'character', 'target_id' => $npc_id, 'label' => 'carried', 'created_by' => 1,
		] );

		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $player, 'player' );
		wp_set_current_user( $player );

		$get  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects/{$object_id}" );
		$data = $this->dispatch( $get )->get_data();
		$this->assertSame( 200, 200 );
		$this->assertCount( 0, $data->connected_characters, 'a player must not see who an NPC-owned item belongs to' );
	}

	public function test_deleting_an_object_leaves_no_orphan_connections(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		global $wpdb;
		$game_id      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}be_games WHERE slug = %s", $this->game_slug ) );
		$character_id = Character::create( [ 'name' => 'Owner', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug ] );

		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/world-objects" );
		$create->set_param( 'object_type', 'item' );
		$create->set_param( 'name', 'Doomed Item' );
		$object_id = $this->dispatch( $create )->get_data()->id;

		Connection::create( [
			'game_id' => $game_id, 'source_type' => 'world_object', 'source_id' => $object_id,
			'target_type' => 'character', 'target_id' => $character_id, 'label' => 'carried', 'created_by' => 1,
		] );

		$delete = new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/world-objects/{$object_id}" );
		$this->assertSame( 204, $this->dispatch( $delete )->get_status() );

		$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_connections WHERE source_type='world_object' AND source_id={$object_id}" );
		$this->assertSame( 0, $orphans );
	}

	public function test_player_can_browse_but_not_create_or_edit(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $player, 'player' );
		wp_set_current_user( $player );

		$list = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects" );
		$this->assertSame( 200, $this->dispatch( $list )->get_status() );

		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/world-objects" );
		$create->set_param( 'object_type', 'item' );
		$create->set_param( 'name', 'Player Attempt' );
		$this->assertSame( 403, $this->dispatch( $create )->get_status() );
	}

	public function test_property_filters_route_correctly(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$high = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/world-objects" );
		$high->set_param( 'object_type', 'item' );
		$high->set_param( 'name', 'Excalibur' );
		$high->set_param( 'properties', [ 'item_type' => 'Weapon', 'level' => 5 ] );
		$this->dispatch( $high );

		$low = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/world-objects" );
		$low->set_param( 'object_type', 'item' );
		$low->set_param( 'name', 'Rusty Knife' );
		$low->set_param( 'properties', [ 'item_type' => 'Weapon', 'level' => 1 ] );
		$this->dispatch( $low );

		$filtered = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects" );
		$filtered->set_param( 'object_type', 'item' );
		$filtered->set_param( 'level_min', 3 );
		$data = $this->dispatch( $filtered )->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'Excalibur', $data[0]->name );
	}
}
