<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\REST\Data_Management_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Covers the uninstall-data setting (defaults to keeping data, an
 * administrator can turn on delete) and the full export route this
 * plugin's "keep, download, or delete" choice is built from.
 */
class DataManagementControllerTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( Data_Management_Controller::DELETE_OPTION );
		do_action( 'rest_api_init' );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_defaults_to_keeping_data(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/data-management' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['delete_on_uninstall'] );
	}

	public function test_a_subscriber_cannot_read_or_write(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 403, $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/data-management' ) )->get_status() );

		$request = new WP_REST_Request( 'PUT', '/be/v1/data-management' );
		$request->set_body_params( [ 'delete_on_uninstall' => true ] );
		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	public function test_an_administrator_can_turn_delete_on_and_off(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$on = new WP_REST_Request( 'PUT', '/be/v1/data-management' );
		$on->set_body_params( [ 'delete_on_uninstall' => true ] );
		$this->assertTrue( $this->dispatch( $on )->get_data()['delete_on_uninstall'] );

		$off = new WP_REST_Request( 'PUT', '/be/v1/data-management' );
		$off->set_body_params( [ 'delete_on_uninstall' => false ] );
		$this->assertFalse( $this->dispatch( $off )->get_data()['delete_on_uninstall'] );
	}

	public function test_export_includes_real_data_from_every_table(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		Game::create( [ 'slug' => 'export-test-game', 'name' => 'Export Test', 'created_by' => 1 ] );

		$response = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/data-management/export' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'games', $data['tables'] );
		$this->assertArrayHasKey( 'characters', $data['tables'] );
		$slugs = array_column( $data['tables']['games'], 'slug' );
		$this->assertContains( 'export-test-game', $slugs );
	}
}
