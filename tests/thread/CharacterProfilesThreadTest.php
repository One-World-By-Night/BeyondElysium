<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * GET /{game}/profiles: NPC and player-character profiles together, each carrying a kind discriminator and a
 * played-by name only when the character has opted in.
 */
class CharacterProfilesThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-character-profiles';
	private int $game_id;
	private int $storyteller_id;
	private int $player_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Character Profiles',
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
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function make_pc( array $overrides = [] ): int {
		return (int) Character::create( array_merge( [
			'name'       => 'A Player Character',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug,
			'wp_user_id' => $this->player_id,
			'status'     => 'active',
			'created_by' => 1,
		], $overrides ) );
	}

	private function make_npc( array $overrides = [] ): int {
		return (int) Character::create( array_merge( [
			'name'       => 'An NPC',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug,
			'is_npc'     => 1,
			'status'     => 'active',
			'created_by' => $this->storyteller_id,
		], $overrides ) );
	}

	private function set_profile( int $id, array $fields, ?int $as = null ): void {
		wp_set_current_user( $as ?? $this->storyteller_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$id}/profile" );
		foreach ( $fields as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$this->dispatch( $request );
	}

	public function test_a_new_character_is_absent_from_profiles_until_shown(): void {
		$this->make_pc();

		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/profiles" ) );

		$this->assertSame( [], $response->get_data() );
	}

	public function test_a_shown_pc_carries_kind_pc_and_no_played_by_until_ticked(): void {
		$id = $this->make_pc();
		$this->set_profile( $id, [ 'profile_audience' => 'everyone' ], $this->player_id );

		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/profiles" ) );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'pc', $data[0]['kind'] );
		$this->assertNull( $data[0]['played_by'] );
	}

	public function test_played_by_appears_only_once_ticked(): void {
		$id = $this->make_pc();
		$this->set_profile( $id, [ 'profile_audience' => 'everyone', 'profile_show_player' => true ], $this->player_id );

		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/profiles" ) );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertNotNull( $data[0]['played_by'] );
	}

	public function test_npcs_and_pcs_both_appear_with_their_own_kind(): void {
		$npc_id = $this->make_npc();
		$this->set_profile( $npc_id, [ 'profile_audience' => 'everyone' ] );

		$pc_id = $this->make_pc();
		$this->set_profile( $pc_id, [ 'profile_audience' => 'everyone' ], $this->player_id );

		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/profiles" ) );
		$kinds    = array_column( $response->get_data(), 'kind' );

		sort( $kinds );
		$this->assertSame( [ 'npc', 'pc' ], $kinds );
	}

	public function test_an_inactive_character_drops_out_of_profiles(): void {
		$id = $this->make_pc( [ 'status' => 'inactive' ] );
		$this->set_profile( $id, [ 'profile_audience' => 'everyone' ], $this->player_id );

		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/profiles" ) );

		$this->assertSame( [], $response->get_data() );
	}

	public function test_a_manager_sees_every_profile_regardless_of_audience(): void {
		$this->make_npc();
		$this->make_pc();

		wp_set_current_user( $this->storyteller_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/profiles" ) );

		$this->assertCount( 2, $response->get_data() );
	}

	public function test_a_connected_characters_bypass_does_not_apply_to_pc_restricted_audience(): void {
		$id = $this->make_pc();
		$this->set_profile( $id, [ 'profile_audience' => 'restricted' ], $this->player_id );

		$other_player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $other_player_id, 'player' );
		$other_character_id = $this->make_pc( [ 'wp_user_id' => $other_player_id ] );
		\BeyondElysium\Models\Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'character', 'source_id' => $id,
			'target_type' => 'character', 'target_id' => $other_character_id,
			'label'       => 'ally',
		] );

		wp_set_current_user( $other_player_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/profiles" ) );

		$this->assertSame( [], $response->get_data() );
	}

	public function test_an_uploaded_portrait_appears_as_a_portrait_attachment_id(): void {
		$id = $this->make_pc();
		$this->set_profile( $id, [ 'profile_audience' => 'everyone' ], $this->player_id );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_attachments', [
			'game_id'       => $this->game_id,
			'entity_type'   => 'character',
			'entity_id'     => $id,
			'original_name' => 'portrait.png',
			'stored_name'   => 'abc123',
			'mime'          => 'image/png',
			'bytes'         => 100,
			'created_by'    => $this->player_id,
			'created_at'    => current_time( 'mysql' ),
		] );

		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/profiles" ) );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertIsInt( $data[0]['portrait_attachment_id'] );
	}

	public function test_the_existing_npcs_route_shape_is_unchanged(): void {
		$id = $this->make_npc();
		$this->set_profile( $id, [ 'public_name' => 'Shaped', 'profile_audience' => 'everyone' ] );

		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/npcs/{$id}" ) );

		$this->assertSame(
			[ 'id', 'name', 'public_description', 'image_url', 'titles', 'factions' ],
			array_keys( $response->get_data() )
		);
	}
}
