<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Audience;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 U5: items and locations enforce their own audience, defaulting to `everyone` (a
 * catalog is normally public - owner ruled the storytellers-only default applies to plots
 * only), on every player-reachable reader: `World_Objects_Controller::get_items()`/`get_item()`
 * and the `item-cards`/`location-cards` reports. A character connected to an object always
 * sees it, whatever its audience - "Print My Items" and a character's own sheet must keep
 * working even when the object is otherwise storytellers-only.
 *
 * These tests fail against pre-U5 code: `World_Objects_Controller` applied no audience
 * enforcement at all (the original survey's own finding).
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §2.5, U5
 */
class WorldObjectAudienceThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-world-object-audience';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread World Object Audience',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	private function make_player(): int {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		return $player;
	}

	private function make_manager(): int {
		$hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $hst, 'hst' );
		return $hst;
	}

	private function make_character( int $wp_user_id, array $overrides = [] ): int {
		return (int) Character::create( array_merge( [
			'name'       => 'Fixture Character',
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'wp_user_id' => $wp_user_id,
			'created_by' => $wp_user_id,
		], $overrides ) );
	}

	private function make_object( string $type, array $overrides = [] ): int {
		return (int) World_Object::create( array_merge( [
			'game_id'     => $this->game_id,
			'object_type' => $type,
			'name'        => ucfirst( $type ) . ' Fixture',
			'created_by'  => 1,
		], $overrides ) );
	}

	private function connect( int $character_id, int $object_id ): void {
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'character',
			'source_id'   => $character_id,
			'target_type' => 'world_object',
			'target_id'   => $object_id,
			'label'       => 'holds',
			'created_by'  => 1,
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// get_items() / get_item()
	// -------------------------------------------------------------------------

	public function test_a_new_item_defaults_to_everyone_not_storytellers(): void {
		$id     = $this->make_object( 'item' );
		$object = World_Object::find( $id );
		$this->assertSame( Audience::EVERYONE, $object->audience );
	}

	/**
	 * A real gap found writing U7's frontend: World_Objects_Controller enforced audience on
	 * every reader (U5) but never actually read `audience`/`audience_rules` from a create or
	 * update request at all - the model layer supported both since U1, but a Storyteller
	 * setting either through the real API silently did nothing.
	 */
	public function test_a_manager_can_set_audience_on_create(): void {
		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/world-objects" );
		$request->set_param( 'name', 'A New Item' );
		$request->set_param( 'object_type', 'item' );
		$request->set_param( 'audience', Audience::STORYTELLERS );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame( Audience::STORYTELLERS, $data->audience );
	}

	public function test_a_manager_can_set_audience_and_rules_on_update(): void {
		$id = $this->make_object( 'location' );
		wp_set_current_user( $this->make_manager() );

		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/world-objects/{$id}" );
		$request->set_param( 'audience', Audience::RESTRICTED );
		$request->set_param( 'audience_rules', [
			'logic'      => 'AND',
			'conditions' => [ [ 'field' => 'clan', 'operator' => 'equals', 'find' => 'Tremere' ] ],
		] );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame( Audience::RESTRICTED, $data->audience );
		$this->assertSame( 'Tremere', $data->audience_rules['conditions'][0]['find'] );
	}

	public function test_update_rejects_an_invalid_audience_value(): void {
		$id = $this->make_object( 'item' );
		wp_set_current_user( $this->make_manager() );

		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/world-objects/{$id}" );
		$request->set_param( 'audience', 'nonsense' );

		$this->assertSame( 400, $this->dispatch( $request )->get_status() );
	}

	public function test_the_list_never_includes_a_storytellers_only_item_for_a_player(): void {
		$this->make_object( 'item', [ 'name' => 'Open Item', 'audience' => Audience::EVERYONE ] );
		$this->make_object( 'item', [ 'name' => 'Hidden Item', 'audience' => Audience::STORYTELLERS ] );

		wp_set_current_user( $this->make_player() );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects" ) );
		$names    = array_map( static fn( $o ) => $o->name, $response->get_data() );

		$this->assertContains( 'Open Item', $names );
		$this->assertNotContains( 'Hidden Item', $names );
	}

	/**
	 * The D38-class pagination proof, mirroring PlotAudienceThreadTest's own: fetching a
	 * SQL-paginated page and then dropping invisible rows would silently truncate below
	 * per_page while more real, visible items existed past an early cutoff.
	 */
	public function test_the_list_total_and_paging_reflect_only_what_is_actually_visible(): void {
		foreach ( [ 'Open A', 'Open B', 'Open C' ] as $name ) {
			$this->make_object( 'item', [ 'name' => $name, 'audience' => Audience::EVERYONE ] );
		}
		foreach ( [ 'Hidden A', 'Hidden B' ] as $name ) {
			$this->make_object( 'item', [ 'name' => $name, 'audience' => Audience::STORYTELLERS ] );
		}

		wp_set_current_user( $this->make_player() );
		$page1 = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects" );
		$page1->set_param( 'per_page', 2 );
		$page1->set_param( 'page', 1 );
		$response1 = $this->dispatch( $page1 );

		$this->assertSame( '3', $response1->get_headers()['X-WP-Total'] );
		$this->assertCount( 2, $response1->get_data() );

		$page2 = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects" );
		$page2->set_param( 'per_page', 2 );
		$page2->set_param( 'page', 2 );
		$this->assertCount( 1, $this->dispatch( $page2 )->get_data() );
	}

	public function test_a_manager_sees_every_item_regardless_of_audience(): void {
		$this->make_object( 'item', [ 'name' => 'Manager Sees This', 'audience' => Audience::STORYTELLERS ] );

		wp_set_current_user( $this->make_manager() );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects" ) );
		$names    = array_map( static fn( $o ) => $o->name, $response->get_data() );

		$this->assertContains( 'Manager Sees This', $names );
	}

	public function test_get_item_404s_for_a_hidden_item_and_200s_for_a_manager(): void {
		$hidden_id = $this->make_object( 'item', [ 'audience' => Audience::STORYTELLERS ] );

		wp_set_current_user( $this->make_player() );
		$this->assertSame(
			404,
			$this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects/{$hidden_id}" ) )->get_status()
		);

		wp_set_current_user( $this->make_manager() );
		$this->assertSame(
			200,
			$this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects/{$hidden_id}" ) )->get_status()
		);
	}

	public function test_a_restricted_location_is_visible_only_through_a_matching_rule(): void {
		$vampire_identity = $this->seed_vampire_identity_block();
		$tremere_id        = $this->make_character( $this->make_player(), [ 'sheet_data' => [ $vampire_identity => [ 'Clan' => 'Tremere' ] ] ] );
		$brujah_id         = $this->make_character( $this->make_player(), [ 'sheet_data' => [ $vampire_identity => [ 'Clan' => 'Brujah' ] ] ] );

		$chantry_id = $this->make_object( 'location', [
			'name'           => 'The Tremere Chantry',
			'audience'       => Audience::RESTRICTED,
			'audience_rules' => [
				'logic'      => 'AND',
				'conditions' => [ [ 'field' => 'clan', 'operator' => 'equals', 'find' => 'Tremere' ] ],
			],
		] );

		wp_set_current_user( Character::find( $tremere_id )->wp_user_id );
		$this->assertSame( 200, $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects/{$chantry_id}" ) )->get_status() );

		wp_set_current_user( Character::find( $brujah_id )->wp_user_id );
		$this->assertSame( 404, $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects/{$chantry_id}" ) )->get_status() );
	}

	// -------------------------------------------------------------------------
	// The connected-character exception (§2.5's own explicit rule)
	// -------------------------------------------------------------------------

	public function test_a_character_connected_to_a_storytellers_only_item_can_still_see_it(): void {
		$owner_player = $this->make_player();
		$owner_id     = $this->make_character( $owner_player );
		$item_id      = $this->make_object( 'item', [ 'audience' => Audience::STORYTELLERS ] );
		$this->connect( $owner_id, $item_id );

		wp_set_current_user( $owner_player );
		$this->assertSame(
			200,
			$this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects/{$item_id}" ) )->get_status(),
			'a connected character always sees the object, whatever its audience (1.1.0 §2.5)'
		);
	}

	public function test_an_unconnected_player_still_cannot_see_a_storytellers_only_item(): void {
		$item_id = $this->make_object( 'item', [ 'audience' => Audience::STORYTELLERS ] );

		wp_set_current_user( $this->make_player() );
		$this->assertSame(
			404,
			$this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects/{$item_id}" ) )->get_status()
		);
	}

	public function test_rotes_and_boons_are_never_audience_filtered(): void {
		$this->make_object( 'rote', [ 'name' => 'A Rote', 'audience' => Audience::STORYTELLERS ] );

		wp_set_current_user( $this->make_player() );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects" ) );
		$names    = array_map( static fn( $o ) => $o->name, $response->get_data() );

		$this->assertContains( 'A Rote', $names, 'Audience has no opinion on rotes - they are a rules catalog, not a secret' );
	}

	/**
	 * Seeds a minimal vampire-identity schema block with a Clan field, matching
	 * AudienceThreadTest's own fixture, so `clan` resolves as a real queryable field.
	 */
	private function seed_vampire_identity_block(): string {
		global $wpdb;
		$table = $wpdb->prefix . 'be_schema_blocks';
		$slug  = 'vampire-identity';

		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) {
			$wpdb->insert( $table, [
				'slug'         => $slug,
				'name'         => 'Identity',
				'section_type' => 'identity_field',
				'definition'   => wp_json_encode( [ 'fields' => [ [ 'name' => 'Clan', 'field_type' => 'text' ] ] ] ),
				'is_system'    => 1,
				'created_by'   => 1,
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			] );
		}

		return $slug;
	}
}
