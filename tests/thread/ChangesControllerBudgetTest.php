<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * workflow-0.4.md V13: `xp_cost` used to be trusted verbatim from the request, so a
 * player could submit any number - including 0 - for a change that really costs XP,
 * bypassing the editor UI's own budget guard entirely and going into `xp_unspent`
 * negative with no ST ever reviewing it. Real dispatch through the REST server against a
 * real character and a real `met-merits` catalog cost ("Iron Will" is "3-5", defaulting
 * to 3 with no chosen_cost), not a mock.
 *
 * @see BE_PROCESS/workflow-0.9.md Planned-vs-Built Audit, item 1
 */
class ChangesControllerBudgetTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-budget-game';
	private int $game_id;
	private int $character_id;
	private int $player_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Budget Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $player, 'player' );
		$this->character_id = Character::create( [
			'name' => 'Budget Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $player,
		] );
		// D27: xp_earned/xp_unspent are set via update_xp(), never trusted in create()'s
		// own data array.
		Character::update_xp( $this->character_id, 2, 2 );

		$this->player_id = $player;
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function add_iron_will_request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes" );
		$request->set_param( 'change_type', 'add_trait' );
		$request->set_param( 'category', 'met-merits' );
		$request->set_param( 'change_data', [ 'block_slug' => 'met-merits', 'trait' => [ 'name' => 'Iron Will' ] ] );
		// A malicious/buggy client submitting a fake low cost - must never be trusted.
		$request->set_param( 'xp_cost', 0 );
		return $request;
	}

	public function test_a_player_with_insufficient_xp_is_refused(): void {
		wp_set_current_user( $this->player_id );

		// Character has 2 XP unspent; Iron Will's real catalog cost is 3-5, defaulting to 3.
		$response = $this->dispatch( $this->add_iron_will_request() );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'insufficient_xp', $response->get_data()['code'] );
	}

	public function test_the_real_server_computed_cost_is_used_not_the_clients_number(): void {
		wp_set_current_user( $this->player_id );
		Character::update_xp( $this->character_id, 10, 10 ); // now affordable

		$response = $this->dispatch( $this->add_iron_will_request() );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 3, (int) $data->xp_cost, 'The real catalog cost (3) must be stored, not the client-submitted 0.' );
	}

	public function test_a_manager_may_still_go_negative_per_d19(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		// Character still has only 2 XP unspent from setUp().
		$response = $this->dispatch( $this->add_iron_will_request() );

		$this->assertSame( 201, $response->get_status(), 'D19: an ST correcting a sheet may legitimately go negative.' );
	}
}
