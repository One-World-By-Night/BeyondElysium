<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The non-game-scoped `/import/game/...` routes (workflow-0.8.md Step 9d-9f), against the
 * one real `.gv3` sample this repo has - `data-samples/personal-chron.gv3` (chronicle
 * "Personal", 1 vampire character "Ian Kincaid II", 48 items, 1 location, 18 queries, 0
 * rotes/actions/plots/rumors). Dispatched through the real REST server, exercising the
 * actual permission gate, transient job store, and transaction - not a direct
 * `Game_File_Parser`/`Import_Controller` call.
 *
 * @see BE_PROCESS/GV-SOURCEMAP.md "GVBG binary game-file shape - verified 2026-09-10"
 * @see BE_PROCESS/workflow-0.8.md Step 9d-9f
 */
class GameImportControllerTest extends WP_UnitTestCase {

	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function path( string $relative ): string {
		return BE_PLUGIN_ROOT . '/' . $relative;
	}

	private function upload_request(): WP_REST_Request {
		$file    = $this->path( 'data-samples/personal-chron.gv3' );
		$request = new WP_REST_Request( 'POST', '/be/v1/import/game/parse' );
		$request->set_file_params( [
			'file' => [
				'tmp_name' => $file,
				'name'     => 'personal-chron.gv3',
				'error'    => 0,
				'size'     => filesize( $file ),
				'type'     => 'application/octet-stream',
			],
		] );
		return $request;
	}

	public function test_parsing_the_real_gv3_file_returns_the_chronicle_summary(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( $this->upload_request() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertNotEmpty( $data['job_id'] );
		$this->assertSame( 'GVBG', $data['format'] );
		$this->assertSame( 'Personal', $data['chronicle_title'] );
		$this->assertSame( 1, $data['counts']['characters'] );
		$this->assertSame( 48, $data['counts']['items'] );
		$this->assertSame( 1, $data['counts']['locations'] );
		$this->assertSame( 18, $data['counts']['queries'] );
		// Nothing real exists yet to collide with - no target has been chosen.
		$this->assertSame( [], $data['duplicates'] );
		$this->assertSame( [], $data['world_object_duplicates'] );
		$this->assertSame( 18, $data['skipped']['queries'] );
		$this->assertTrue( $data['skipped']['apr_engine'] );
	}

	public function test_a_binary_exchange_file_is_refused_not_silently_parsed(): void {
		wp_set_current_user( $this->admin_id );

		$file    = $this->path( 'GV301Source/Code/New Game Items.gex' );
		$request = new WP_REST_Request( 'POST', '/be/v1/import/game/parse' );
		$request->set_file_params( [
			'file' => [
				'tmp_name' => $file, 'name' => 'New Game Items.gex', 'error' => 0,
				'size' => filesize( $file ), 'type' => 'application/octet-stream',
			],
		] );

		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_format', $response->get_data()['code'] );
	}

	public function test_a_player_role_user_is_refused(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $player );

		$response = $this->dispatch( $this->upload_request() );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_get_job_with_a_target_shows_real_duplicates(): void {
		wp_set_current_user( $this->admin_id );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'gv3-target-game', 'name' => 'GV3 Target Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) $wpdb->insert_id;
		Character::create( [
			'name' => 'Ian Kincaid II', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => 'gv3-target-game',
		] );
		World_Object::create( [
			'game_id' => $game_id, 'object_type' => 'item', 'name' => 'Assault Rifle',
			'properties' => [ 'item_type' => 'Weapon', 'item_subtype' => '', 'level' => 0, 'bonus' => 0,
				'damage_type' => '', 'damage_amount' => 0, 'concealability' => '', 'powers' => '',
				'appearance' => '', 'tempers' => [], 'negatives' => [], 'abilities' => [], 'availability' => [] ],
		] );

		$job_id = $this->dispatch( $this->upload_request() )->get_data()['job_id'];

		$get      = new WP_REST_Request( 'GET', "/be/v1/import/game/{$job_id}" );
		$get->set_param( 'target', 'gv3-target-game' );
		$response = $this->dispatch( $get );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertCount( 1, $data['duplicates'] );
		$this->assertSame( 'Ian Kincaid II', $data['duplicates'][0]['character'] );
		$this->assertCount( 1, $data['world_object_duplicates'] );
		$this->assertSame( 'Assault Rifle', $data['world_object_duplicates'][0]['name'] );
	}

	public function test_committing_without_a_target_is_refused(): void {
		wp_set_current_user( $this->admin_id );
		$job_id = $this->dispatch( $this->upload_request() )->get_data()['job_id'];

		$commit   = new WP_REST_Request( 'POST', "/be/v1/import/game/{$job_id}/commit" );
		$response = $this->dispatch( $commit );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_target', $response->get_data()['code'] );
	}

	public function test_committing_a_merge_into_a_nonexistent_chronicle_is_refused(): void {
		wp_set_current_user( $this->admin_id );
		$job_id = $this->dispatch( $this->upload_request() )->get_data()['job_id'];

		$commit = new WP_REST_Request( 'POST', "/be/v1/import/game/{$job_id}/commit" );
		$commit->set_param( 'target', [ 'action' => 'merge', 'game_slug' => 'does-not-exist' ] );
		$response = $this->dispatch( $commit );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'game_not_found', $response->get_data()['code'] );
	}

	public function test_committing_with_create_new_makes_a_real_chronicle_and_imports_everything(): void {
		wp_set_current_user( $this->admin_id );
		$job_id = $this->dispatch( $this->upload_request() )->get_data()['job_id'];

		$before_games = Game::count();

		$commit = new WP_REST_Request( 'POST', "/be/v1/import/game/{$job_id}/commit" );
		$commit->set_param( 'target', [ 'action' => 'create_new', 'name' => 'Brand New Chronicle' ] );
		$response = $this->dispatch( $commit );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertTrue( $data['game']['created'] );
		$this->assertSame( 'Brand New Chronicle', $data['game']['name'] );
		$this->assertSame( $before_games + 1, Game::count() );

		$this->assertCount( 1, $data['characters'] );
		$this->assertSame( 'created', $data['characters'][0]['action'] );
		$this->assertCount( 48, $data['items'] );
		$this->assertCount( 1, $data['locations'] );
		$this->assertCount( 0, $data['rotes'] );

		$game      = Game::find( (int) $data['game']['id'] );
		$character = Character::find_by_name_in_game( 'Ian Kincaid II', $game->slug );
		$this->assertNotNull( $character );
		$this->assertSame( 'vampire', $character->stack_slug );
		// "Imported health levels are silently discarded" (0.99.2-workflow.md) - the file's
		// own extended_health flag (true for this fixture, per GameFileParserTest) must
		// survive onto the new chronicle instead of being parsed and dropped.
		$this->assertTrue( $game->settings->extended_health );

		// A blocked or failed create_new attempt must never leave a phantom chronicle -
		// re-committing the SAME already-succeeded job must not create a second one.
		$this->dispatch( $commit );
		$this->assertSame( $before_games + 1, Game::count(), 'V13: re-committing must not import (or create a chronicle) twice' );
	}

	public function test_committing_a_merge_resolves_a_duplicate_by_overwriting_in_place(): void {
		wp_set_current_user( $this->admin_id );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'gv3-merge-target', 'name' => 'GV3 Merge Target',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$existing_id = Character::create( [
			'name' => 'Ian Kincaid II', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => 'gv3-merge-target',
		] );
		$existing_uuid = Character::find( $existing_id )->uuid;

		$job_id = $this->dispatch( $this->upload_request() )->get_data()['job_id'];

		$commit = new WP_REST_Request( 'POST', "/be/v1/import/game/{$job_id}/commit" );
		$commit->set_param( 'target', [ 'action' => 'merge', 'game_slug' => 'gv3-merge-target' ] );
		$commit->set_param( 'resolutions', [ 'duplicates' => [ 'Ian Kincaid II' => 'overwrite' ] ] );
		$response = $this->dispatch( $commit );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertFalse( $data['game']['created'] );
		$this->assertSame( 'overwritten', $data['characters'][0]['action'] );
		$this->assertSame( $existing_id, $data['characters'][0]['id'] );

		$updated = Character::find( $existing_id );
		$this->assertSame( $existing_uuid, $updated->uuid, 'merge overwrite must preserve identity, same rule as Chunk 1' );

		// 48 real items, none pre-existing in this game - all created, not blocked.
		$this->assertCount( 48, $data['items'] );
	}
}
