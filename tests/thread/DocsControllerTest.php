<?php

namespace BeyondElysium\Tests\Thread;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * User request, 2026-09-11: a wp-admin "Docs" tab reading the plugin's own shipped
 * docs/*.md files through this route. `SLUGS` is the route's own allowlist, not a filter
 * applied after matching - a request for anything else never reaches the handler at all.
 */
class DocsControllerTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_each_real_doc_slug_returns_its_own_file_content(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		foreach ( [ 'st-guide', 'admin-guide', 'player-guide', 'rest-api' ] as $slug ) {
			$request  = new WP_REST_Request( 'GET', "/be/v1/docs/{$slug}" );
			$response = $this->dispatch( $request );

			$this->assertSame( 200, $response->get_status(), "docs/{$slug} should resolve" );
			$data = $response->get_data();
			$this->assertSame( $slug, $data['slug'] );
			$this->assertNotEmpty( $data['content'], "docs/{$slug}.md should have real content" );
			// Loosely confirm this is genuinely markdown, not an error page or empty stub.
			$this->assertStringContainsString( '#', $data['content'] );
		}
	}

	public function test_an_unknown_slug_never_reaches_the_handler(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		// Not in the allowlist - the route itself must not match, not merely refuse.
		$request  = new WP_REST_Request( 'GET', '/be/v1/docs/../../../etc/passwd' );
		$response = $this->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_requires_be_view_characters(): void {
		// A user with no role at all - be_view_characters is granted broadly (every real
		// role has it, Characters_Controller's own established convention), but a bare
		// WP_User with no role holds no capabilities at all.
		$user = self::factory()->user->create( [ 'role' => '' ] );
		wp_set_current_user( $user );

		$request  = new WP_REST_Request( 'GET', '/be/v1/docs/st-guide' );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}
