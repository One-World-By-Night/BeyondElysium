<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Faction;
use BeyondElysium\Models\Faction_Member;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Position;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 §3.7 item 3: "Who's Who" - an NPC's public profile. A Storyteller sees every NPC
 * regardless of `profile_audience`; a player sees only the projection {id, name,
 * public_description, image_url, titles, factions} for an NPC whose audience reaches them,
 * never the NPC's real sheet, status, or assignment.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.7
 */
class NpcProfileThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-npc-profile';
	private int $game_id;
	private int $storyteller_id;
	private int $player_id;
	private int $player_character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread NPC Profile',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		$this->player_character_id = (int) Character::create( [
			'name' => 'A Player Character', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_id, 'status' => 'active', 'created_by' => 1,
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function make_npc( array $overrides = [] ): int {
		return (int) Character::create( array_merge( [
			'name'       => 'An NPC',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug,
			'is_npc'     => 1,
			'created_by' => $this->storyteller_id,
		], $overrides ) );
	}

	// -------------------------------------------------------------------------
	// Updating the profile: manager-only, NPC-only.
	// -------------------------------------------------------------------------

	public function test_a_manager_can_set_an_npcs_public_profile(): void {
		$id = $this->make_npc();

		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$id}/profile" );
		$request->set_param( 'public_name', 'The Bartender' );
		$request->set_param( 'public_description', 'Runs the bar, sees everything.' );
		$request->set_param( 'profile_audience', 'everyone' );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'The Bartender', $response->get_data()['name'] );
		$this->assertSame( 'Runs the bar, sees everything.', $response->get_data()['public_description'] );
	}

	public function test_setting_a_profile_on_a_pc_is_rejected(): void {
		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$this->player_character_id}/profile" );
		$request->set_param( 'public_name', 'Not an NPC' );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_an_npc', $response->get_data()['code'] );
	}

	public function test_a_player_cannot_set_an_npcs_profile(): void {
		$id = $this->make_npc();

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$id}/profile" );
		$request->set_param( 'public_name', 'Sneaky' );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_an_invalid_profile_audience_is_rejected(): void {
		$id = $this->make_npc();

		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$id}/profile" );
		$request->set_param( 'profile_audience', 'nonsense' );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// GET /npcs and /npcs/{id}: audience-scoped for a player, unrestricted for a manager.
	// -------------------------------------------------------------------------

	private function set_profile( int $id, array $fields ): void {
		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$id}/profile" );
		foreach ( $fields as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$this->dispatch( $request );
	}

	public function test_a_player_only_sees_npcs_whose_audience_reaches_them(): void {
		$everyone_npc = $this->make_npc( [ 'name' => 'Visible NPC' ] );
		$this->set_profile( $everyone_npc, [ 'public_name' => 'Visible NPC', 'profile_audience' => 'everyone' ] );

		$storyteller_only_npc = $this->make_npc( [ 'name' => 'Hidden NPC' ] );
		$this->set_profile( $storyteller_only_npc, [ 'public_name' => 'Hidden NPC', 'profile_audience' => 'storytellers' ] );

		// No profile set up at all - never appears, even though it exists.
		$this->make_npc( [ 'name' => 'No Profile NPC' ] );

		wp_set_current_user( $this->player_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/npcs" );
		$response = $this->dispatch( $request );
		$names    = array_column( $response->get_data(), 'name' );

		$this->assertSame( [ 'Visible NPC' ], $names );
	}

	public function test_a_manager_sees_every_npc_regardless_of_audience(): void {
		$this->make_npc( [ 'name' => 'One NPC' ] );
		$hidden = $this->make_npc( [ 'name' => 'Another NPC' ] );
		$this->set_profile( $hidden, [ 'profile_audience' => 'storytellers' ] );

		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/npcs" );
		$response = $this->dispatch( $request );

		$this->assertCount( 2, $response->get_data() );
	}

	public function test_the_projection_never_includes_sheet_or_status_fields(): void {
		$id = $this->make_npc();
		$this->set_profile( $id, [ 'public_name' => 'Shaped', 'profile_audience' => 'everyone' ] );

		wp_set_current_user( $this->player_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/npcs/{$id}" );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame(
			[ 'id', 'name', 'public_description', 'image_url', 'titles', 'factions' ],
			array_keys( $data )
		);
	}

	public function test_a_connected_character_sees_a_restricted_npcs_profile(): void {
		$id = $this->make_npc();
		$this->set_profile( $id, [ 'profile_audience' => 'restricted' ] );
		Connection::create( [
			'game_id' => $this->game_id,
			'source_type' => 'character', 'source_id' => $id,
			'target_type' => 'character', 'target_id' => $this->player_character_id,
			'label' => 'ally',
		] );

		wp_set_current_user( $this->player_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/npcs/{$id}" );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_get_item_404s_for_a_pc_id(): void {
		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/npcs/{$this->player_character_id}" );
		$response = $this->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_get_item_404s_instead_of_403ing_a_denied_player(): void {
		$id = $this->make_npc();
		$this->set_profile( $id, [ 'profile_audience' => 'storytellers' ] );

		wp_set_current_user( $this->player_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/npcs/{$id}" );
		$response = $this->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// titles/factions (F1/F2): each keeps the same visibility rule it has on its
	// own dedicated route, never a looser one just because it is reached from here.
	// -------------------------------------------------------------------------

	public function test_titles_and_factions_reach_a_manager_in_full(): void {
		$id = $this->make_npc();
		$this->set_profile( $id, [ 'profile_audience' => 'everyone' ] );
		Position::create( [ 'game_id' => $this->game_id, 'title' => 'Sheriff', 'character_id' => $id, 'holder_public' => false, 'created_by' => $this->storyteller_id ] );
		$faction_id = (int) Faction::create( [ 'game_id' => $this->game_id, 'name' => 'The Camarilla', 'faction_type' => 'sect', 'audience' => 'storytellers', 'created_by' => $this->storyteller_id ] );
		Faction_Member::add( $faction_id, $id, $this->storyteller_id );

		wp_set_current_user( $this->storyteller_id );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/npcs/{$id}" ) )->get_data();

		$this->assertSame( [ 'Sheriff' ], $data['titles'] );
		$this->assertSame( [ 'The Camarilla' ], $data['factions'] );
	}

	public function test_a_non_public_title_and_a_storytellers_only_faction_are_hidden_from_a_player(): void {
		$id = $this->make_npc();
		$this->set_profile( $id, [ 'profile_audience' => 'everyone' ] );
		Position::create( [ 'game_id' => $this->game_id, 'title' => 'Sheriff', 'character_id' => $id, 'holder_public' => false, 'created_by' => $this->storyteller_id ] );
		$faction_id = (int) Faction::create( [ 'game_id' => $this->game_id, 'name' => 'The Camarilla', 'faction_type' => 'sect', 'audience' => 'storytellers', 'created_by' => $this->storyteller_id ] );
		Faction_Member::add( $faction_id, $id, $this->storyteller_id );

		wp_set_current_user( $this->player_id );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/npcs/{$id}" ) )->get_data();

		$this->assertSame( [], $data['titles'] );
		$this->assertSame( [], $data['factions'] );
	}

	public function test_a_public_title_and_an_everyone_faction_reach_a_player(): void {
		$id = $this->make_npc();
		$this->set_profile( $id, [ 'profile_audience' => 'everyone' ] );
		Position::create( [ 'game_id' => $this->game_id, 'title' => 'Prince', 'character_id' => $id, 'holder_public' => true, 'created_by' => $this->storyteller_id ] );
		$faction_id = (int) Faction::create( [ 'game_id' => $this->game_id, 'name' => 'The Camarilla', 'faction_type' => 'sect', 'audience' => 'everyone', 'created_by' => $this->storyteller_id ] );
		Faction_Member::add( $faction_id, $id, $this->storyteller_id );

		wp_set_current_user( $this->player_id );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/npcs/{$id}" ) )->get_data();

		$this->assertSame( [ 'Prince' ], $data['titles'] );
		$this->assertSame( [ 'The Camarilla' ], $data['factions'] );
	}
}
