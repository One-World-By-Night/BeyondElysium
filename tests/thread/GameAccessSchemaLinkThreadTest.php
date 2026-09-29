<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A chronicle's accessSchema link and notification switch, on create and on update.
 */
class GameAccessSchemaLinkThreadTest extends WP_UnitTestCase {

	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function create_by_route( array $body ) {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', '/be/v1/games' );
		$request->set_body_params( $body );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_chronicle_created_with_an_accessschema_link_keeps_it(): void {
		$id = (int) Game::create( [ 'name' => 'Linked at Birth', 'slug' => 'thread-asc-created', 'asc_role_path' => 'chronicle/thread-asc-created', 'owbn_chronicle_post_id' => 424242 ] );

		$game = Game::find( $id );
		$this->assertSame( 'chronicle/thread-asc-created', $game->asc_role_path );
		$this->assertSame( '424242', (string) $game->owbn_chronicle_post_id );
	}

	public function test_a_chronicle_created_with_notifications_off_keeps_them_off(): void {
		$id = (int) Game::create( [ 'name' => 'Quiet at Birth', 'slug' => 'thread-asc-quiet', 'notifications_enabled' => 0 ] );

		$this->assertSame( 0, (int) Game::find( $id )->notifications_enabled );
	}

	public function test_a_chronicle_created_with_neither_has_no_link_and_notifies(): void {
		$id = (int) Game::create( [ 'name' => 'Plain', 'slug' => 'thread-asc-plain' ] );

		$game = Game::find( $id );
		$this->assertNull( $game->asc_role_path );
		$this->assertSame( 1, (int) $game->notifications_enabled );
	}

	public function test_the_create_route_keeps_an_accessschema_link(): void {
		$response = $this->create_by_route( [ 'name' => 'Linked by Route', 'slug' => 'thread-asc-route', 'asc_role_path' => 'chronicle/thread-asc-route', 'notifications_enabled' => false ] );

		$this->assertSame( 201, $response->get_status() );
		$game = Game::find_by_slug( 'thread-asc-route' );
		$this->assertSame( 'chronicle/thread-asc-route', $game->asc_role_path );
		$this->assertSame( 0, (int) $game->notifications_enabled );
	}

	public function test_updating_a_chronicle_that_does_not_exist_is_refused(): void {
		$this->assertFalse( Game::update( 'thread-asc-nowhere', [ 'asc_role_path' => 'chronicle/nowhere' ] ) );
	}

	public function test_a_chronicles_id_is_not_its_slug(): void {
		$id = (int) Game::create( [ 'name' => 'By Id', 'slug' => 'thread-asc-by-id' ] );

		$this->assertFalse( Game::update( (string) $id, [ 'asc_role_path' => 'chronicle/thread-asc-by-id' ] ) );
		$this->assertNull( Game::find( $id )->asc_role_path );
	}

	public function test_updating_a_chronicle_by_its_slug_links_it(): void {
		$id = (int) Game::create( [ 'name' => 'By Slug', 'slug' => 'thread-asc-by-slug' ] );

		$this->assertTrue( Game::update( 'thread-asc-by-slug', [ 'asc_role_path' => 'chronicle/thread-asc-by-slug' ] ) );
		$this->assertSame( 'chronicle/thread-asc-by-slug', Game::find( $id )->asc_role_path );
	}
}
