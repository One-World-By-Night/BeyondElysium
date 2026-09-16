<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-002: every HST is a WordPress editor, and v0.99.16 granted editors
 * be_manage_schemas and be_manage_templates so an HST could customize their own chronicle.
 * The global write routes were never narrowed, so any HST could rewrite the catalog, the
 * creature stacks, and the templates every chronicle shares - and could fork a chronicle
 * they have no membership in by adding ?game_slug= to a global route. Global catalog writes
 * are now a site administrator's; a chronicle's own customizations go through its own
 * URL-scoped routes, where membership is checked.
 */
class GlobalCatalogWritesThreadTest extends WP_UnitTestCase {

	private string $home = 'thread-catalog-home';
	private string $other = 'thread-catalog-other';
	private string $block = 'thread-catalog-block';
	private int $hst;
	private int $admin;
	private int $global_template;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		foreach ( [ $this->home, $this->other ] as $slug ) {
			$wpdb->insert( $wpdb->prefix . 'be_games', [
				'slug' => $slug, 'name' => $slug, 'settings' => '{}',
				'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			] );
		}

		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) Game::find_by_slug( $this->home )->id, $this->hst, 'hst' );
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

		Schema_Block::create( [
			'slug' => $this->block, 'name' => 'Thread Catalog Block', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [] ], 'is_system' => 0,
		] );
		$this->global_template = Template::create( [
			'name' => 'Thread Global Template', 'template_type' => 'sheet_full',
			'layout' => [ 'version' => 1, 'columns' => 3, 'sections' => [] ],
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

	public function test_a_storyteller_cannot_rewrite_a_global_schema_block(): void {
		wp_set_current_user( $this->hst );

		$response = $this->dispatch( 'PUT', "/be/v1/schema-blocks/{$this->block}", [ 'name' => 'Rewritten For Everyone' ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'Thread Catalog Block', Schema_Block::find_by_slug( $this->block )->name );
	}

	public function test_a_storyteller_cannot_fork_another_chronicle_through_the_global_route(): void {
		wp_set_current_user( $this->hst );
		// The query string must go through set_query_params() - WP_REST_Request does not parse
		// one out of the route string, which would make this request match no route at all.
		$request = new WP_REST_Request( 'PUT', "/be/v1/schema-blocks/{$this->block}" );
		$request->set_query_params( [ 'game_slug' => $this->other ] );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'name' => 'Forked Elsewhere' ] ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), [ 400, 403 ] );
		$this->assertNull( $this->fork_of( $this->other ) );
	}

	public function test_a_game_slug_on_a_global_write_route_is_refused_even_for_an_administrator(): void {
		wp_set_current_user( $this->admin );
		$request = new WP_REST_Request( 'PUT', "/be/v1/schema-blocks/{$this->block}" );
		$request->set_query_params( [ 'game_slug' => $this->home ] );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'name' => 'Meant For One Chronicle' ] ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'use_chronicle_route', $response->as_error()->get_error_code() );
		$this->assertSame( 'Thread Catalog Block', Schema_Block::find_by_slug( $this->block )->name );
	}

	public function test_a_storyteller_customizes_their_own_chronicle_through_its_route(): void {
		wp_set_current_user( $this->hst );

		$response = $this->dispatch( 'PUT', "/be/v1/{$this->home}/schema-blocks/{$this->block}", [ 'name' => 'Home Only' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Home Only', $this->fork_of( $this->home )->name );
		$this->assertSame( 'Thread Catalog Block', Schema_Block::find_by_slug( $this->block )->name );
	}

	public function test_creating_a_block_through_a_chronicle_route_creates_it_for_that_chronicle_only(): void {
		wp_set_current_user( $this->hst );

		$response = $this->dispatch( 'POST', "/be/v1/{$this->home}/schema-blocks", [
			'slug' => 'thread-home-house-rules', 'name' => 'Home House Rules', 'section_type' => 'trait_list',
		] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertNull( Schema_Block::find_by_slug( 'thread-home-house-rules' ) );
		$this->assertSame( $this->home, Schema_Block::find_for_game( 'thread-home-house-rules', $this->home )->game_slug );
	}

	public function test_a_chronicle_route_cannot_create_a_block_that_shadows_the_global_catalog(): void {
		wp_set_current_user( $this->hst );

		$response = $this->dispatch( 'POST', "/be/v1/{$this->home}/schema-blocks", [
			'slug' => $this->block, 'name' => 'Shadow', 'section_type' => 'trait_list',
		] );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_deleting_a_fork_that_does_not_exist_is_a_404_not_a_silent_204(): void {
		wp_set_current_user( $this->hst );

		$response = $this->dispatch( 'DELETE', "/be/v1/{$this->home}/schema-blocks/{$this->block}" );

		$this->assertSame( 404, $response->get_status() );
		$this->assertNotNull( Schema_Block::find_by_slug( $this->block ) );
	}

	public function test_a_storyteller_cannot_change_the_shared_creature_stacks(): void {
		wp_set_current_user( $this->hst );
		$before = \BeyondElysium\Models\Creature_Stack::find_by_slug( 'vampire' )->name;

		// A PUT, not a POST: WordPress validates a POST route's required args before its
		// permission callback, so an incomplete create would 400 without testing the gate.
		$response = $this->dispatch( 'PUT', '/be/v1/creature-stacks/vampire', [ 'name' => 'Renamed For Everyone' ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( $before, \BeyondElysium\Models\Creature_Stack::find_by_slug( 'vampire' )->name );
	}

	public function test_a_storyteller_cannot_edit_a_global_template_but_can_override_it_for_their_chronicle(): void {
		wp_set_current_user( $this->hst );

		$global = $this->dispatch( 'PUT', "/be/v1/templates/{$this->global_template}", [ 'name' => 'Rewritten For Everyone' ] );
		$own    = $this->dispatch( 'POST', "/be/v1/{$this->home}/templates", [
			'name' => 'Home Sheet', 'template_type' => 'sheet_full',
			'layout' => [ 'version' => 1, 'columns' => 3, 'sections' => [] ],
		] );

		$this->assertSame( 403, $global->get_status() );
		$this->assertSame( 'Thread Global Template', Template::find( $this->global_template )->name );
		$this->assertSame( 201, $own->get_status() );
	}

	public function test_an_administrator_still_edits_the_global_catalog(): void {
		wp_set_current_user( $this->admin );

		$response = $this->dispatch( 'PUT', "/be/v1/schema-blocks/{$this->block}", [ 'name' => 'Admin Renamed' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Admin Renamed', Schema_Block::find_by_slug( $this->block )->name );
	}

	private function fork_of( string $game_slug ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = %s",
			$this->block,
			$game_slug
		) );
	}
}
