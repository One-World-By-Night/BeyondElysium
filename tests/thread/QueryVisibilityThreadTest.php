<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The Query Tool redacts character rows for anyone who is not a Storyteller of the chronicle.
 */
class QueryVisibilityThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-query-visibility';
	private int $narrator;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) Game::find_by_slug( $this->slug )->id;

		// Site-wide both are editors, which hold be_run_queries; only the chronicle role differs.
		$this->narrator = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $this->narrator, 'narrator' );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $this->hst, 'hst' );

		Character::create( [
			'name' => 'Player Character', 'stack_slug' => 'vampire', 'is_npc' => 0,
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'notes' => 'Public note. [ST]hidden-marker[/ST]',
			'rp_notes' => 'Storyteller roleplaying notes',
			'sheet_data' => [ 'npc-roleplaying-notes' => [ 'Voice' => 'Storyteller-only voice notes' ] ],
		] );
		Character::create( [
			'name' => 'Secret NPC', 'stack_slug' => 'vampire', 'is_npc' => 1,
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
		] );
	}

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}/{$route}" );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_the_query_tool_refuses_a_narrator(): void {
		wp_set_current_user( $this->narrator );

		$this->assertSame( 403, $this->dispatch( 'POST', 'query', [ 'conditions' => [] ] )->get_status() );
		$this->assertSame( 403, $this->dispatch( 'POST', 'statistics', [ 'conditions' => [], 'key' => 'name', 'stat_type' => 'distribution' ] )->get_status() );
		$this->assertSame( 403, $this->dispatch( 'GET', 'queries' )->get_status() );
	}

	/**
	 * The second line: what a non-Storyteller's query would reach, run straight through the engine with the options the
	 * controller builds for one.
	 */
	public function test_a_query_run_for_anyone_but_a_storyteller_never_reaches_npcs_or_hidden_data(): void {
		// Outside a request, Authorization::can() answers site-wide - a subscriber is nobody's Storyteller.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$method = new \ReflectionMethod( \BeyondElysium\REST\Query_Controller::class, 'visibility_options' );
		$method->setAccessible( true );
		$options = $method->invoke( new \BeyondElysium\REST\Query_Controller(), 'char', Game::find_by_slug( $this->slug ) );

		$rows = \BeyondElysium\Services\Query_Engine::execute( $this->slug, [], 'AND', [], 'char', $options )['results'];
		$this->assertSame( [ 'Player Character' ], array_column( $rows, 'name' ) );
		$this->assertArrayNotHasKey( 'npc-roleplaying-notes', (array) $rows[0]->sheet_data );
		$this->assertStringNotContainsString( 'hidden-marker', (string) $rows[0]->notes );

		$probe = \BeyondElysium\Services\Query_Engine::execute( $this->slug, [ [ 'field' => 'notes', 'operator' => 'contains', 'find' => 'hidden-marker' ] ], 'AND', [], 'char', $options );
		$this->assertSame( [], $probe['results'] );
	}

	public function test_the_chronicles_storyteller_sees_everything_unredacted(): void {
		wp_set_current_user( $this->hst );

		$rows    = $this->dispatch( 'POST', 'query', [ 'conditions' => [ [ 'field' => 'notes', 'operator' => 'contains', 'find' => 'hidden-marker' ] ] ] )->get_data();
		$all     = $this->dispatch( 'POST', 'query', [ 'conditions' => [] ] )->get_data();
		$by_name = array_column( $all, null, 'name' );

		$this->assertSame( [ 'Player Character' ], array_column( $rows, 'name' ) );
		$this->assertArrayHasKey( 'Secret NPC', $by_name );
		$this->assertStringContainsString( 'hidden-marker', (string) $by_name['Player Character']->notes );
	}
}
