<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player hand-enters a new character: the starting picks land on the sheet directly, never as XP changes.
 */
class CharacterHandEntryCreationTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-hand-entry-game';
	private string $stack_slug = 'thread-test-hand-entry-stack';
	private int $player_id;

	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Hand Entry Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		Creature_Stack::create( [
			'slug'             => $this->stack_slug,
			'name'             => 'Thread Test Hand Entry Stack',
			'stack_definition' => [
				'sections' => [
					[ 'block_slug' => 'vampire-abilities', 'label' => 'Abilities', 'display_order' => 1, 'required' => true ],
					[ 'block_slug' => 'vampire-merits', 'label' => 'Merits', 'display_order' => 2, 'required' => false ],
				],
			],
		] );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		// A player of this chronicle.
		\BeyondElysium\Models\Game_Member::set_role( (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}be_games WHERE slug = %s", $this->game_slug ) ), $this->player_id, 'player' );
	}

	private function starting_sheet(): array {
		return [
			'vampire-abilities' => [ [ 'name' => 'Brawl', 'count' => 3 ] ],
			'vampire-merits'    => [ [ 'name' => 'Iron Will', 'count' => 1 ] ],
		];
	}

	public function test_a_player_creates_a_fully_populated_character_in_one_request(): void {
		wp_set_current_user( $this->player_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters" );
		$request->set_param( 'name', 'Hand Entered Hero' );
		$request->set_param( 'stack_slug', $this->stack_slug );
		$request->set_param( 'sheet_data', $this->starting_sheet() );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( 0, (int) $data->xp_unspent, 'A starting sheet must never be priced as XP spend.' );
		$this->assertSame( 'active', $data->status, 'No approval setting on this game - unchanged default behavior.' );

		$character = Character::find( $data->id );
		$this->assertSame( [ [ 'name' => 'Brawl', 'count' => 3 ] ], $character->sheet_data['vampire-abilities'] );
		$this->assertSame( [ [ 'name' => 'Iron Will', 'count' => 1 ] ], $character->sheet_data['vampire-merits'] );

		global $wpdb;
		$changes = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}be_character_changes WHERE character_id = %d",
				$data->id
			)
		);
		$this->assertSame( '0', $changes, 'A starting sheet is not a purchase - it must never create Change records.' );
	}

	public function test_sheet_data_referencing_an_unknown_block_is_rejected(): void {
		wp_set_current_user( $this->player_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters" );
		$request->set_param( 'name', 'Bad Block Character' );
		$request->set_param( 'stack_slug', $this->stack_slug );
		$request->set_param( 'sheet_data', [ 'not-a-real-block' => [] ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_non_array_sheet_data_is_rejected(): void {
		wp_set_current_user( $this->player_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters" );
		$request->set_param( 'name', 'String Sheet Character' );
		$request->set_param( 'stack_slug', $this->stack_slug );
		$request->set_param( 'sheet_data', 'not an object' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_approval_required_game_forces_pending_for_a_player(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'require_new_character_approval' => true ] ] );
		wp_set_current_user( $this->player_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters" );
		$request->set_param( 'name', 'Awaiting Approval' );
		$request->set_param( 'stack_slug', $this->stack_slug );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()->status );
	}

	public function test_a_player_cannot_bypass_approval_by_sending_status_active(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'require_new_character_approval' => true ] ] );
		wp_set_current_user( $this->player_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters" );
		$request->set_param( 'name', 'Sneaky Bypass Attempt' );
		$request->set_param( 'stack_slug', $this->stack_slug );
		$request->set_param( 'status', 'active' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()->status, 'A non-manager\'s explicit status must never override the approval requirement.' );
	}

	public function test_a_manager_is_unaffected_by_the_approval_setting(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'require_new_character_approval' => true ] ] );
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters" );
		$request->set_param( 'name', 'Manager Made NPC' );
		$request->set_param( 'stack_slug', $this->stack_slug );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'active', $response->get_data()->status, 'A manager is never subject to the approval gate.' );
	}
}
