<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Saved_Query;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Every query-tool user in a chronicle saw every other user's saved queries and "Most Recent Search", and could
 * overwrite or delete any of them.
 */
class SavedQueryOwnershipThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-saved-queries';
	private int $game_id;
	private int $hst;
	private int $ast;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) Game::find_by_slug( $this->slug )->id;

		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		$this->ast = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->ast, 'ast' );
	}

	private function saved_by( int $user_id, string $name ): int {
		return (int) Saved_Query::create( [
			'game_id' => $this->game_id, 'name' => $name, 'inventory' => 'char',
			'match_all' => true, 'conditions' => [], 'created_by' => $user_id,
		] );
	}

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_an_ast_sees_shared_saved_queries_but_not_the_hsts_recent_search(): void {
		$this->saved_by( $this->hst, 'Shared Downtime Query' );
		Saved_Query::save_recent( $this->game_id, $this->hst, 'char', true, [ [ 'field' => 'name', 'operator' => 'contains', 'find' => 'Secret Target' ] ] );

		wp_set_current_user( $this->ast );
		$names = array_column( $this->dispatch( 'GET', "/be/v1/{$this->slug}/queries" )->get_data(), 'name' );

		$this->assertContains( 'Shared Downtime Query', $names );
		$this->assertNotContains( 'Most Recent Search', $names );
	}

	public function test_an_ast_manages_their_own_and_the_hst_manages_everyones(): void {
		$own = $this->saved_by( $this->ast, 'AST Query' );

		wp_set_current_user( $this->ast );
		$this->assertSame( 200, $this->dispatch( 'PUT', "/be/v1/{$this->slug}/queries/{$own}", [ 'name' => 'AST Query 2' ] )->get_status() );

		wp_set_current_user( $this->hst );
		$this->assertSame( 204, $this->dispatch( 'DELETE', "/be/v1/{$this->slug}/queries/{$own}" )->get_status() );
		$this->assertNull( Saved_Query::find( $own ) );
	}
}
