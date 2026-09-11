<?php

namespace BeyondElysium\Tests\Thread;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Step 3a/3e audit findings, both around slug collisions:
 * - create_item(): an explicit (not name-derived) colliding slug used to fall through
 *   to the database's own UNIQUE constraint and surface as a generic create_failed 500.
 * - update_item(): Game::update()'s boolean return was discarded entirely - a colliding
 *   slug silently failed the UPDATE, and the code went on to look up the *other* game
 *   that already held the requested slug, returning that game's data as if the edit had
 *   succeeded.
 */
class GamesControllerTest extends WP_UnitTestCase {

	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	private function create_game( string $slug ) {
		$request = new WP_REST_Request( 'POST', '/be/v1/games' );
		$request->set_param( 'name', 'Game ' . $slug );
		$request->set_param( 'slug', $slug );
		return rest_get_server()->dispatch( $request );
	}

	public function test_an_explicit_duplicate_slug_is_rejected_on_create(): void {
		$this->assertSame( 201, $this->create_game( 'thread-test-dup-game' )->get_status() );

		$response = $this->create_game( 'thread-test-dup-game' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'duplicate_slug', $response->as_error()->get_error_code() );
	}

	public function test_a_name_derived_slug_still_auto_dedupes_on_create(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/games' );
		$request->set_param( 'name', 'Auto Dedupe Game Thread Test' );
		$first = rest_get_server()->dispatch( $request )->get_data();

		$request2 = new WP_REST_Request( 'POST', '/be/v1/games' );
		$request2->set_param( 'name', 'Auto Dedupe Game Thread Test' );
		$response2 = rest_get_server()->dispatch( $request2 );

		$this->assertSame( 201, $response2->get_status(), 'No explicit slug was requested, so auto-dedupe (not a 409) is still correct.' );
		$this->assertNotSame( $first->slug, $response2->get_data()->slug );
	}

	public function test_renaming_a_game_to_another_games_slug_is_rejected_not_silently_wrong(): void {
		$this->create_game( 'thread-test-game-a' );
		$this->create_game( 'thread-test-game-b' );

		$request = new WP_REST_Request( 'PUT', '/be/v1/games/thread-test-game-a' );
		$request->set_param( 'slug', 'thread-test-game-b' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 409, $response->get_status(), 'A colliding rename must be a real 409, not a 200 carrying the OTHER game\'s data.' );
		$this->assertSame( 'duplicate_slug', $response->as_error()->get_error_code() );

		// Game A must be untouched - still reachable at its original slug, still its own name.
		$still_there = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/games/thread-test-game-a' ) )->get_data();
		$this->assertSame( 'Game thread-test-game-a', $still_there->name );
	}

	public function test_a_normal_rename_still_succeeds(): void {
		$this->create_game( 'thread-test-rename-source' );

		$request = new WP_REST_Request( 'PUT', '/be/v1/games/thread-test-rename-source' );
		$request->set_param( 'slug', 'thread-test-rename-target' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'thread-test-rename-target', $response->get_data()->slug );
	}

	public function test_update_with_a_malformed_settings_payload_is_rejected(): void {
		$this->create_game( 'thread-test-settings-game' );

		$request = new WP_REST_Request( 'PUT', '/be/v1/games/thread-test-settings-game' );
		$request->set_param( 'settings', 'not an object' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status(), 'The PUT route previously had no args schema at all, so this reached Game::update() unvalidated.' );
	}

	/**
	 * Step 1.5c/1.5e (workflow-0.9.md) - asc_role_path is a D27-class field: present on
	 * neither this controller's own field list nor Game::update()'s allowlist until this
	 * fix, so it would have been silently dropped with no error on either layer.
	 */
	public function test_asc_role_path_can_be_set_and_read_back(): void {
		$this->create_game( 'thread-test-asc-role-path-game' );

		$request = new WP_REST_Request( 'PUT', '/be/v1/games/thread-test-asc-role-path-game' );
		$request->set_param( 'asc_role_path', 'Chronicle/KONY' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Chronicle/KONY', $response->get_data()->asc_role_path );

		$refetched = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/games/thread-test-asc-role-path-game' ) )->get_data();
		$this->assertSame( 'Chronicle/KONY', $refetched->asc_role_path );
	}
}
