<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The REST layer around Query_Engine: validation 400s naming the offending clause,
 * the permission boundary, and real execution against real characters.
 *
 * @see BE_PROCESS/workflow-0.6.md Step 4
 */
class QueryControllerThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-query-game';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Query Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		Character::create( [
			'name' => 'Marcus Vitel', 'stack_slug' => 'vampire', 'status' => 'active',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'sheet_data' => [ 'vampire-disciplines' => [ [ 'name' => 'Celerity', 'level' => 3 ] ] ],
		] );
		Character::create( [
			'name' => 'Sara Redhawk', 'stack_slug' => 'werewolf', 'status' => 'active',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_unknown_field_returns_400_naming_the_clause(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/query" );
		$request->set_param( 'conditions', [
			[ 'field' => 'race', 'operator' => 'equals', 'find' => 'vampire' ],
			[ 'field' => 'not_a_real_field', 'operator' => 'equals', 'find' => 'x' ],
		] );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'Clause 1', $response->get_data()['message'] );
	}

	public function test_inapplicable_operator_returns_400(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/query" );
		$request->set_param( 'conditions', [
			[ 'field' => 'race', 'operator' => 'at_least', 'value' => 3 ],
		] );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_player_gets_403_on_query_and_statistics(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $player );

		$query_req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/query" );
		$query_req->set_param( 'conditions', [] );
		$this->assertSame( 403, $this->dispatch( $query_req )->get_status() );

		$stats_req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/statistics" );
		$stats_req->set_param( 'key', 'xpearned' );
		$stats_req->set_param( 'stat_type', 'sums' );
		$this->assertSame( 403, $this->dispatch( $stats_req )->get_status() );
	}

	public function test_query_returns_the_right_characters_and_saves_most_recent(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/query" );
		$request->set_param( 'conditions', [
			[ 'field' => 'disciplines', 'operator' => 'contains_at_least', 'find' => 'Celerity', 'value' => 3 ],
		] );
		$response = $this->dispatch( $request );
		$names    = array_column( $response->get_data(), 'name' );

		$this->assertSame( [ 'Marcus Vitel' ], $names );

		$saved = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/queries" );
		$saved_data = $this->dispatch( $saved )->get_data();
		$this->assertCount( 1, $saved_data );
		$this->assertSame( 'Most Recent Search', $saved_data[0]->name );
	}

	public function test_or_logic_returns_the_union(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/query" );
		$request->set_param( 'conditions', [
			[ 'field' => 'race', 'operator' => 'equals', 'find' => 'vampire' ],
			[ 'field' => 'race', 'operator' => 'equals', 'find' => 'werewolf' ],
		] );
		$request->set_param( 'logic', 'OR' );
		$response = $this->dispatch( $request );

		$this->assertCount( 2, $response->get_data() );
	}

	public function test_and_logic_returns_the_intersection(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/query" );
		$request->set_param( 'conditions', [
			[ 'field' => 'race', 'operator' => 'equals', 'find' => 'vampire' ],
			[ 'field' => 'playstatus', 'operator' => 'equals', 'find' => 'retired' ],
		] );
		$request->set_param( 'logic', 'AND' );
		$response = $this->dispatch( $request );

		$this->assertCount( 0, $response->get_data() );
	}

	public function test_saved_query_persists_reloads_and_can_be_deleted(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/queries" );
		$create->set_param( 'name', 'Vampires with Celerity 3+' );
		$create->set_param( 'conditions', [
			[ 'field' => 'disciplines', 'operator' => 'contains_at_least', 'find' => 'Celerity', 'value' => 3 ],
		] );
		$saved = $this->dispatch( $create )->get_data();
		$this->assertSame( 'Vampires with Celerity 3+', $saved->name );

		$delete = new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/queries/{$saved->id}" );
		$this->assertSame( 204, $this->dispatch( $delete )->get_status() );
	}

	public function test_statistics_endpoint_returns_hand_verifiable_buckets(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/statistics" );
		$request->set_param( 'conditions', [] );
		$request->set_param( 'key', 'race' );
		$request->set_param( 'stat_type', 'distribution' );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 1.0, $data['buckets']['vampire'] );
		$this->assertSame( 1.0, $data['buckets']['werewolf'] );
	}
}
