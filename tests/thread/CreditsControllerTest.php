<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\REST\Credits_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * User request, 2026-09-11: a front-end "Powered by BeyondElysium (Credits)" footer whose
 * modal shows credits text plus an editable in-memoriam list. Backed by two plain options,
 * not a table - covers the default seeding, the be_manage_games write gate, and that a
 * save fully replaces the stored list rather than merging into it.
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
			[ 'Arielle M.', 'Stephen Page', 'Scott Little', 'Jamison', 'Travis Dunn', 'Carl Gosline', 'Gary "House" Williams' ],
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

	public function test_an_administrator_can_replace_the_memoriam_list_and_credits_text(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'PUT', '/be/v1/credits' );
		$request->set_body_params( [
			'credits_text' => 'Updated credits line.',
			'in_memoriam'  => [
				[ 'name' => 'Arielle M.', 'note' => 'XP Day' ],
				[ 'name' => 'A New Name', 'note' => '' ],
			],
		] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Updated credits line.', $data['credits_text'] );
		$this->assertCount( 2, $data['in_memoriam'], 'a save fully replaces the stored list, not merges into it' );
		$this->assertSame( 'Arielle M.', $data['in_memoriam'][0]['name'] );
		$this->assertSame( 'XP Day', $data['in_memoriam'][0]['note'] );

		// Confirmed for real against a fresh GET, not just the write response echo.
		$get_response = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/credits' ) );
		$this->assertCount( 2, $get_response->get_data()['in_memoriam'] );
	}

	public function test_an_entry_with_no_name_is_dropped_silently(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'PUT', '/be/v1/credits' );
		$request->set_body_params( [
			'in_memoriam' => [
				[ 'name' => 'Real Name', 'note' => '' ],
				[ 'name' => '', 'note' => 'no name, should be dropped' ],
			],
		] );
		$response = $this->dispatch( $request );

		$data = $response->get_data();
		$this->assertCount( 1, $data['in_memoriam'] );
		$this->assertSame( 'Real Name', $data['in_memoriam'][0]['name'] );
	}
}
