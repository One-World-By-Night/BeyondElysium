<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Location_Link;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A location nested "inside of" another, its four named links, "who's here," and the Grapevine-text-vs-linked-name
 * display preference.
 */
class LocationStructureThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-location-structure';
	private int $game_id;
	private int $storyteller_id;
	private int $player_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Location Structure' ] );

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
	}

	private function send( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}{$route}" );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function make_location( array $overrides = [] ): int {
		return (int) World_Object::create( array_merge( [
			'game_id'     => $this->game_id,
			'object_type' => 'location',
			'name'        => 'A Location',
			'created_by'  => $this->storyteller_id,
		], $overrides ) );
	}

	// -------------------------------------------------------------------------
	// Inside of: parent same-game/same-type, cycles refused, children block delete.
	// -------------------------------------------------------------------------

	public function test_a_location_can_be_nested_inside_another(): void {
		wp_set_current_user( $this->storyteller_id );
		$city = $this->make_location( [ 'name' => 'Downtown' ] );

		$response = $this->send( 'POST', '/world-objects', [
			'object_type' => 'location', 'name' => 'Elysium', 'parent_id' => $city,
		] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $city, (int) $response->get_data()->parent_id );
	}

	public function test_only_a_location_may_have_a_parent(): void {
		wp_set_current_user( $this->storyteller_id );
		$city = $this->make_location( [ 'name' => 'Downtown' ] );

		$response = $this->send( 'POST', '/world-objects', [
			'object_type' => 'item', 'name' => 'A Sword', 'parent_id' => $city,
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_parent', $response->get_data()['code'] );
	}

	public function test_a_parent_must_be_in_the_same_game(): void {
		$other_game = (int) Game::create( [ 'slug' => 'thread-location-structure-other', 'name' => 'Other' ] );
		$foreign    = (int) World_Object::create( [
			'game_id' => $other_game, 'object_type' => 'location', 'name' => 'Elsewhere', 'created_by' => 1,
		] );

		wp_set_current_user( $this->storyteller_id );
		$response = $this->send( 'POST', '/world-objects', [
			'object_type' => 'location', 'name' => 'Elysium', 'parent_id' => $foreign,
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_parent', $response->get_data()['code'] );
	}

	public function test_a_location_cannot_become_its_own_descendants_parent(): void {
		wp_set_current_user( $this->storyteller_id );
		$city  = $this->make_location( [ 'name' => 'Downtown' ] );
		$block = $this->make_location( [ 'name' => 'Elysium', 'parent_id' => $city ] );

		$response = $this->send( 'PUT', "/world-objects/{$city}", [ 'parent_id' => $block ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_parent', $response->get_data()['code'] );
	}

	public function test_a_location_cannot_be_its_own_parent(): void {
		wp_set_current_user( $this->storyteller_id );
		$city = $this->make_location( [ 'name' => 'Downtown' ] );

		$response = $this->send( 'PUT', "/world-objects/{$city}", [ 'parent_id' => $city ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_location_with_children_cannot_be_deleted(): void {
		wp_set_current_user( $this->storyteller_id );
		$city = $this->make_location( [ 'name' => 'Downtown' ] );
		$this->make_location( [ 'name' => 'Elysium', 'parent_id' => $city ] );

		$response = $this->send( 'DELETE', "/world-objects/{$city}" );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'location_has_children', $response->get_data()['code'] );
	}

	public function test_get_item_reports_ancestors_and_children_for_the_breadcrumb(): void {
		wp_set_current_user( $this->storyteller_id );
		$city  = $this->make_location( [ 'name' => 'Downtown' ] );
		$block = $this->make_location( [ 'name' => 'Elysium', 'parent_id' => $city ] );

		$response = $this->send( 'GET', "/world-objects/{$block}" );
		$data     = $response->get_data();

		$this->assertSame( [ [ 'id' => $city, 'name' => 'Downtown' ] ], $data->ancestors );
		$this->assertSame( [], $data->children );
	}

	// -------------------------------------------------------------------------
	// Links: label validation, source validation.
	// -------------------------------------------------------------------------

	public function test_an_invalid_label_is_rejected(): void {
		wp_set_current_user( $this->storyteller_id );
		$location = $this->make_location();
		$owner    = (int) Character::create( [
			'name' => 'Prince Marcus', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'created_by' => $this->storyteller_id,
		] );

		$response = $this->send( 'POST', "/locations/{$location}/links", [
			'label' => 'renter', 'source_type' => 'character', 'source_id' => $owner,
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_link_source_must_be_a_real_character(): void {
		wp_set_current_user( $this->storyteller_id );
		$location = $this->make_location();

		$response = $this->send( 'POST', "/locations/{$location}/links", [
			'label' => Location_Link::OWNER, 'source_type' => 'character', 'source_id' => 999999,
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_manager_can_create_and_remove_a_link(): void {
		wp_set_current_user( $this->storyteller_id );
		$location = $this->make_location();
		$owner    = (int) Character::create( [
			'name' => 'Prince Marcus', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'created_by' => $this->storyteller_id,
		] );

		$created = $this->send( 'POST', "/locations/{$location}/links", [
			'label' => Location_Link::OWNER, 'source_type' => 'character', 'source_id' => $owner,
		] );
		$this->assertSame( 201, $created->get_status() );
		$link_id = $created->get_data()['id'];

		$list = $this->send( 'GET', "/locations/{$location}/links" );
		$this->assertCount( 1, $list->get_data() );

		$deleted = $this->send( 'DELETE', "/locations/{$location}/links/{$link_id}" );
		$this->assertSame( 204, $deleted->get_status() );
		$this->assertCount( 0, $this->send( 'GET', "/locations/{$location}/links" )->get_data() );
	}

	// -------------------------------------------------------------------------
	// Who's here: a player sees only NPCs based_at whose profile reaches them.
	// -------------------------------------------------------------------------

	private function make_npc( string $name, string $audience = 'everyone' ): int {
		$id = (int) Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'is_npc' => 1, 'created_by' => $this->storyteller_id,
		] );
		Character::update_header( $id, [ 'profile_audience' => $audience ] );
		return $id;
	}

	public function test_a_player_sees_only_npcs_based_at_whose_profile_reaches_them(): void {
		$location = $this->make_location();
		$visible  = $this->make_npc( 'The Bartender', 'everyone' );
		$hidden   = $this->make_npc( 'The Enforcer', 'storytellers' );

		Location_Link::create( $this->game_id, Location_Link::BASED_AT, 'character', $visible, $location, $this->storyteller_id );
		Location_Link::create( $this->game_id, Location_Link::BASED_AT, 'character', $hidden, $location, $this->storyteller_id );

		wp_set_current_user( $this->player_id );
		$response = $this->send( 'GET', "/locations/{$location}/links" );
		$names    = array_column( $response->get_data(), 'name' );

		$this->assertSame( [ 'The Bartender' ], $names );
	}

	public function test_a_player_never_sees_an_owner_or_haven_link(): void {
		$location = $this->make_location();
		$owner    = (int) Character::create( [
			'name' => 'Prince Marcus', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id, 'created_by' => $this->storyteller_id,
		] );
		Location_Link::create( $this->game_id, Location_Link::OWNER, 'character', $owner, $location, $this->storyteller_id );
		Location_Link::create( $this->game_id, Location_Link::HAVEN, 'character', $owner, $location, $this->storyteller_id );

		wp_set_current_user( $this->player_id );
		$response = $this->send( 'GET', "/locations/{$location}/links" );

		$this->assertSame( [], $response->get_data() );
	}

	public function test_a_manager_sees_every_link_regardless_of_label(): void {
		$location = $this->make_location();
		$owner    = (int) Character::create( [
			'name' => 'Prince Marcus', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'created_by' => $this->storyteller_id,
		] );
		Location_Link::create( $this->game_id, Location_Link::OWNER, 'character', $owner, $location, $this->storyteller_id );

		wp_set_current_user( $this->storyteller_id );
		$response = $this->send( 'GET', "/locations/{$location}/links" );

		$this->assertCount( 1, $response->get_data() );
	}

	// -------------------------------------------------------------------------
	// Audience: a character connected via any location link is always connected
	// -------------------------------------------------------------------------

	public function test_a_haven_holder_always_sees_a_storytellers_only_location(): void {
		$location = $this->make_location();
		World_Object::update( $location, [ 'audience' => 'storytellers' ] );
		$holder = (int) Character::create( [
			'name' => 'Prince Marcus', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id, 'created_by' => $this->storyteller_id,
		] );
		Location_Link::create( $this->game_id, Location_Link::HAVEN, 'character', $holder, $location, $this->storyteller_id );

		wp_set_current_user( $this->player_id );
		$response = $this->send( 'GET', "/world-objects/{$location}" );

		$this->assertSame( 200, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Display over Grapevine text: the card report and the detail page prefer a real link.
	// -------------------------------------------------------------------------

	public function test_the_detail_page_prefers_a_linked_owner_and_parent_over_typed_text(): void {
		wp_set_current_user( $this->storyteller_id );
		$city  = $this->make_location( [ 'name' => 'Downtown' ] );
		$block = $this->make_location( [
			'name' => 'Elysium', 'parent_id' => $city,
			'properties' => [ 'owner' => 'Some Typed Name', 'where' => 'Some Typed Place' ],
		] );
		$owner = (int) Character::create( [
			'name' => 'Prince Marcus', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'created_by' => $this->storyteller_id,
		] );
		Location_Link::create( $this->game_id, Location_Link::OWNER, 'character', $owner, $block, $this->storyteller_id );

		$response = $this->send( 'GET', "/world-objects/{$block}" );
		$display  = $response->get_data()->display;

		$this->assertSame( 'Prince Marcus', $display['owner'] );
		$this->assertSame( 'Downtown', $display['where'] );
	}

	public function test_the_detail_page_falls_back_to_typed_text_with_no_link(): void {
		wp_set_current_user( $this->storyteller_id );
		$block = $this->make_location( [
			'name' => 'Elysium', 'properties' => [ 'owner' => 'Some Typed Name', 'where' => 'Some Typed Place' ],
		] );

		$response = $this->send( 'GET', "/world-objects/{$block}" );
		$display  = $response->get_data()->display;

		$this->assertSame( 'Some Typed Name', $display['owner'] );
		$this->assertSame( 'Some Typed Place', $display['where'] );
	}

	public function test_the_location_cards_report_prefers_a_linked_owner_over_typed_text(): void {
		wp_set_current_user( $this->storyteller_id );
		$block = $this->make_location( [ 'name' => 'Elysium', 'properties' => [ 'owner' => 'Some Typed Name' ] ] );
		$owner = (int) Character::create( [
			'name' => 'Prince Marcus', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'created_by' => $this->storyteller_id,
		] );
		Location_Link::create( $this->game_id, Location_Link::OWNER, 'character', $owner, $block, $this->storyteller_id );

		$response = $this->send( 'GET', '/reports/location-cards' );
		$this->assertSame( 200, $response->get_status() );

		$card  = current( array_filter( $response->get_data()['cards'], static fn( $c ) => $c[0][1] === 'Elysium' ) );
		$owner_field = current( array_filter( $card, static fn( $f ) => $f[0] === 'Owner' ) );

		$this->assertSame( 'Prince Marcus', $owner_field[1] );
	}
}
