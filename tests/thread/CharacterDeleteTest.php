<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Sheet_Style;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Services\Change_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `DELETE /be/v1/{game_slug}/characters/{id}` and `Character::delete()`: who may delete a character and what is
 * removed.
 */
class CharacterDeleteTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-character-delete-game';
	private int $game_id;
	private int $admin_id;
	private int $player_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Character Delete Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->admin_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Real fixture: a character carrying at least one row in every table the cascade is supposed to clean up.
	 */
	private function fully_populated_character(): int {
		$character_id = Character::create( [
			'name' => 'Cascade Delete Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );

		$other_character_id = Character::create( [
			'name' => 'Other Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );

		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $character_id,
			'target_type' => 'character', 'target_id' => $other_character_id, 'label' => 'ally',
			'created_by' => $this->admin_id,
		] );

		Change_Engine::submit(
			$character_id,
			[
				'change_type' => 'modify_resource', 'category' => 'vampire-resources',
				'change_data' => [ 'field' => 'blood_temp', 'delta' => 1 ],
				'notes' => 'Cascade test change',
			],
			$this->admin_id
		);

		Snapshot::create( $character_id, null );

		Sheet_Style::save( $character_id, [ 'font_family' => 'Georgia, serif' ] );

		return $character_id;
	}

	public function test_deleting_a_character_removes_it(): void {
		wp_set_current_user( $this->admin_id );
		$character_id = Character::create( [
			'name' => 'Simple Delete Test', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );

		$this->assertNotNull( Character::find( $character_id ) );
		$this->assertTrue( Character::delete( $character_id ) );
		$this->assertNull( Character::find( $character_id ) );
	}

	/**
	 * The real fix: before this, a delete left orphaned rows in every one of these four tables.
	 */
	public function test_deleting_a_character_cascades_to_connections_changes_snapshots_and_sheet_style(): void {
		wp_set_current_user( $this->admin_id );
		$character_id = $this->fully_populated_character();

		$this->assertNotEmpty( Connection::for_entity( 'character', $character_id ), 'fixture sanity check' );
		$this->assertNotEmpty( Change::for_character( $character_id ), 'fixture sanity check' );
		$this->assertNotEmpty( Snapshot::for_character( $character_id ), 'fixture sanity check' );
		$this->assertNotNull( Sheet_Style::for_character( $character_id ), 'fixture sanity check' );

		Character::delete( $character_id );

		$this->assertEmpty( Connection::for_entity( 'character', $character_id ), 'connections must be gone' );
		$this->assertEmpty( Change::for_character( $character_id ), 'change history must be gone' );
		$this->assertEmpty( Snapshot::for_character( $character_id ), 'snapshots must be gone' );
		$this->assertNull( Sheet_Style::for_character( $character_id ), 'sheet style must be gone' );
	}

	/**
	 * The cascade must only ever touch the ONE character being deleted.
	 */
	public function test_deleting_a_character_does_not_touch_a_connection_where_it_is_only_the_target(): void {
		wp_set_current_user( $this->admin_id );

		$keeper_id = Character::create( [
			'name' => 'Keeper', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );
		$doomed_id = Character::create( [
			'name' => 'Doomed', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );

		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $keeper_id,
			'target_type' => 'character', 'target_id' => $doomed_id, 'label' => 'rival',
			'created_by' => $this->admin_id,
		] );

		Character::delete( $doomed_id );

		$between = array_filter( Connection::for_entity( 'character', $keeper_id ), static fn( $c ) => $c->label === 'rival' );
		$this->assertEmpty( $between );
		$this->assertNotNull( Character::plot_id( $keeper_id ), "the keeper's link to its own plot is its own, and stays" );
		$this->assertNotNull( Character::find( $keeper_id ), 'the OTHER character must survive - only its connection is affected' );
	}

	public function test_the_delete_route_actually_deletes_through_a_real_request(): void {
		wp_set_current_user( $this->admin_id );
		$character_id = Character::create( [
			'name' => 'Via REST', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );

		$request  = new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$response = $this->dispatch( $request );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( Character::find( $character_id ) );
	}

	public function test_a_player_cannot_delete_a_character_via_the_route(): void {
		$character_id = Character::create( [
			'name' => 'Protected From Players', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $this->player_id,
		] );

		wp_set_current_user( $this->player_id );
		$request  = new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status(), 'be_manage_characters is required, even for a player deleting their OWN character' );
		$this->assertNotNull( Character::find( $character_id ) );
	}
}
