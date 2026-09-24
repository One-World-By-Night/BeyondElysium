<?php

namespace BeyondElysium\Tests\Thread;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The wp-admin Docs tab route reads only the plugin's own shipped `docs/*.md` files, through an allowlist.
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
		// A user with no role at all.
		$user = self::factory()->user->create( [ 'role' => '' ] );
		wp_set_current_user( $user );

		$request  = new WP_REST_Request( 'GET', '/be/v1/docs/st-guide' );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_help_page_is_served_by_its_key(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/docs/help/character-sheet' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'character-sheet', $response->get_data()['key'] );
		$this->assertStringStartsWith( '# Character Sheet', $response->get_data()['content'] );
	}

	public function test_a_help_key_with_no_page_is_not_found(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		foreach ( [ 'no-such-screen', 'st-guide', '..%2Fst-guide', 'Character-Sheet' ] as $key ) {
			$response = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/docs/help/' . $key ) );
			$this->assertSame( 404, $response->get_status(), $key );
		}
	}

	public function test_a_help_page_needs_what_the_guides_need(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => '' ] ) );

		$this->assertSame( 403, $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/docs/help/character-sheet' ) )->get_status() );
	}
}
