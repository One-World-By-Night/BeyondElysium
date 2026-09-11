<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The templates REST controller: permission matrix, and the cross-scope edit refusal
 * from Step 2e - a game HST must not be able to touch a global template, or another
 * game's override, by guessing its id.
 *
 * The workflow doc calls this out as tests/integration/TemplatesControllerTest.php, but
 * this project has no integration layer (TESTING.md: unit / thread / workflow). A REST
 * endpoint exercised end to end against a real database is exactly what belongs in
 * tests/thread/, so it lives here instead.
 *
 * @see BE_PROCESS/workflow-0.3.md Step 2g
 */
class TemplatesControllerTest extends WP_UnitTestCase {

	private string $block_slug = 'thread-test-controller-block';

	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		Schema_Block::create( [
			'slug'         => $this->block_slug,
			'name'         => 'Thread Test Controller Block',
			'section_type' => 'identity_field',
			'definition'   => [ 'fields' => [ [ 'name' => 'Test Field' ] ] ],
		] );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'thread-test-game-a', 'name' => 'Thread Test Game A',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'thread-test-game-b', 'name' => 'Thread Test Game B',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
	}

	private function layout(): array {
		return [
			'version'  => 1,
			'columns'  => 1,
			'sections' => [
				[ 'block_slug' => $this->block_slug, 'column' => 1, 'order' => 1, 'title' => 'X', 'display' => null, 'collapsed' => false ],
			],
		];
	}

	private function dispatch( string $method, string $route, array $params = [] ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Permission matrix
	// -------------------------------------------------------------------------

	public function test_viewer_can_list_global_templates(): void {
		$viewer = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $viewer );

		$response = $this->dispatch( 'GET', '/be/v1/templates' );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_viewer_cannot_create_a_template(): void {
		$viewer = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $viewer );

		$response = $this->dispatch( 'POST', '/be/v1/templates', [
			'stack_slug' => 'test-stack', 'name' => 'X', 'template_type' => 'sheet_full', 'layout' => $this->layout(),
		] );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_administrator_can_create_a_template(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$response = $this->dispatch( 'POST', '/be/v1/templates', [
			'stack_slug' => 'test-stack', 'name' => 'X', 'template_type' => 'sheet_full', 'layout' => $this->layout(),
		] );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_logged_out_user_is_forbidden(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch( 'GET', '/be/v1/templates' );
		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Cross-scope edit refusal (Step 2e)
	// -------------------------------------------------------------------------

	public function test_cannot_edit_a_global_template_through_a_game_scoped_route(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$created = $this->dispatch( 'POST', '/be/v1/templates', [
			'stack_slug' => 'test-stack', 'name' => 'Global', 'template_type' => 'sheet_full', 'layout' => $this->layout(),
		] )->get_data();

		$response = $this->dispatch( 'PUT', "/be/v1/thread-test-game-a/templates/{$created->id}", [ 'name' => 'Hijacked' ] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'not_found', $response->get_data()['code'] );
	}

	public function test_cannot_edit_another_games_override_by_guessing_its_id(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$created = $this->dispatch( 'POST', '/be/v1/thread-test-game-a/templates', [
			'stack_slug' => 'test-stack', 'name' => 'A override', 'template_type' => 'sheet_full', 'layout' => $this->layout(),
		] )->get_data();

		$response = $this->dispatch( 'PUT', "/be/v1/thread-test-game-b/templates/{$created->id}", [ 'name' => 'Hijacked' ] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'not_found', $response->get_data()['code'], 'must not leak that the id belongs to a different game' );
	}

	public function test_bad_game_slug_returns_game_not_found(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$response = $this->dispatch( 'GET', '/be/v1/no-such-game/templates/resolve', [
			'stack_slug' => 'test-stack', 'template_type' => 'sheet_full',
		] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'game_not_found', $response->get_data()['code'] );
	}

	public function test_deleting_a_system_template_is_refused(): void {
		global $wpdb;
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$wpdb->insert( $wpdb->prefix . 'be_templates', [
			'stack_slug' => 'test-stack', 'name' => 'System', 'template_type' => 'sheet_full',
			'layout' => wp_json_encode( $this->layout() ), 'is_system' => 1, 'created_by' => 1,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$id = (int) $wpdb->insert_id;

		$response = $this->dispatch( 'DELETE', "/be/v1/templates/{$id}" );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'cannot_delete', $response->get_data()['code'] );
	}
}
