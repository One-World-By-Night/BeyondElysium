<?php

namespace BeyondElysium\Tests\Thread;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Step 1.5e, workflow-0.9.md - `be_asc_enabled` (`Authorization::asc_enabled()`) could
 * only ever be flipped via `update_option()` directly before this route existed.
 */
class AuthorizationSettingsControllerTest extends WP_UnitTestCase {

	private int $admin_id;
	private int $player_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->admin_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		delete_option( 'be_asc_enabled' );
	}

	public function test_a_manager_can_read_the_current_settings(): void {
		wp_set_current_user( $this->admin_id );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/authorization-settings' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['asc_enabled'], 'default is off - a fresh install runs on capabilities alone' );
	}

	public function test_a_manager_can_enable_and_disable_it(): void {
		wp_set_current_user( $this->admin_id );

		$on = new WP_REST_Request( 'PUT', '/be/v1/authorization-settings' );
		$on->set_param( 'asc_enabled', true );
		$response = rest_get_server()->dispatch( $on );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['asc_enabled'] );
		$this->assertTrue( \BeyondElysium\Core\Authorization::asc_enabled() );

		$off = new WP_REST_Request( 'PUT', '/be/v1/authorization-settings' );
		$off->set_param( 'asc_enabled', false );
		$response = rest_get_server()->dispatch( $off );
		$this->assertFalse( $response->get_data()['asc_enabled'] );
		$this->assertFalse( \BeyondElysium\Core\Authorization::asc_enabled() );
	}

	public function test_client_detected_reflects_whether_the_functions_actually_exist(): void {
		wp_set_current_user( $this->admin_id );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/authorization-settings' ) );

		// Neither accessSchema nor owbn-client is installed on the test harness (confirmed
		// plugin list, same as every real environment this project has run in to date) -
		// asserting the real, measured value here, not a mocked one.
		$this->assertFalse( $response->get_data()['client_detected'] );
	}

	public function test_a_non_manager_is_denied_on_both_routes(): void {
		wp_set_current_user( $this->player_id );

		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/authorization-settings' ) )->get_status() );

		$request = new WP_REST_Request( 'PUT', '/be/v1/authorization-settings' );
		$request->set_param( 'asc_enabled', true );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertFalse( \BeyondElysium\Core\Authorization::asc_enabled(), 'a denied request must never have taken effect' );
	}
}
