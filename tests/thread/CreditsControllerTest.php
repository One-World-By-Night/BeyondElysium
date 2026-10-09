<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\REST\Credits_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The Powered by BeyondElysium (Credits) footer: default seeding, the capability gate, and the credits text's write
 * path. The in-memoriam list has no write path; it is read-only everywhere.
 */
class CreditsControllerTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( Credits_Controller::CREDITS_OPTION );
		delete_option( Credits_Controller::MEMORIAM_OPTION );
		do_action( 'rest_api_init' );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_get_seeds_and_returns_the_default_credits_and_memoriam_list(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request  = new WP_REST_Request( 'GET', '/be/v1/credits' );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotEmpty( $data['credits_text'] );
		$names = array_column( $data['in_memoriam'], 'name' );
		$this->assertSame(
			[ 'Arielle M.', 'Ash White', 'Carl Gosline', 'Douglas Alexander', 'Gary "House" Williams', 'J. T. Nielsen', 'Jamison', 'Sarah Gabbey', 'Scott Little', 'Stephen Page', 'Tim "Ando" Anderson', 'Travis Dunn' ],
			$names
		);
	}

	public function test_get_requires_be_view_characters(): void {
		$user = self::factory()->user->create( [ 'role' => '' ] );
		wp_set_current_user( $user );

		$request  = new WP_REST_Request( 'GET', '/be/v1/credits' );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_subscriber_cannot_write(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request = new WP_REST_Request( 'PUT', '/be/v1/credits' );
		$request->set_body_params( [ 'credits_text' => 'Hijacked' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_an_administrator_can_change_the_credits_text(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'PUT', '/be/v1/credits' );
		$request->set_body_params( [ 'credits_text' => 'Updated credits line.' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Updated credits line.', $response->get_data()['credits_text'] );

		// Read back with a fresh GET, not just the write response echo.
		$get_response = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/credits' ) );
		$this->assertSame( 'Updated credits line.', $get_response->get_data()['credits_text'] );
	}

	public function test_a_put_carrying_in_memoriam_leaves_the_stored_list_byte_identical(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$before = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/credits' ) )->get_data()['in_memoriam'];

		$request = new WP_REST_Request( 'PUT', '/be/v1/credits' );
		$request->set_body_params( [
			'credits_text' => 'Updated credits line.',
			'in_memoriam'  => [
				[ 'name' => 'A New Name', 'note' => 'should never be stored' ],
			],
		] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Updated credits line.', $data['credits_text'] );
		$this->assertSame( $before, $data['in_memoriam'], 'a PUT carrying in_memoriam must not change the stored list' );

		// Read back with a fresh GET, not just the write response echo.
		$get_response = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/credits' ) );
		$this->assertSame( $before, $get_response->get_data()['in_memoriam'] );
	}
}
