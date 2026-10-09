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

	private function as_portuguese_viewer(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		add_filter( 'locale', static fn(): string => 'pt_BR' );
	}

	public function test_an_english_viewer_gets_the_english_original(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$help  = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/docs/help/my-chronicle' ) )->get_data();
		$guide = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/docs/player-guide' ) )->get_data();

		$this->assertSame( [ 'en', false ], [ $help['language'], $help['fallback'] ] );
		$this->assertStringStartsWith( '# My Chronicle', $help['content'] );
		$this->assertSame( [ 'en', false ], [ $guide['language'], $guide['fallback'] ] );
		$this->assertStringStartsWith( '# Player Guide', $guide['content'] );
	}

	public function test_a_portuguese_viewer_gets_the_translated_help_page_and_guide(): void {
		$this->as_portuguese_viewer();

		$help  = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/docs/help/my-chronicle' ) )->get_data();
		$guide = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/docs/player-guide' ) )->get_data();

		$this->assertSame( [ 'pt_BR', false ], [ $help['language'], $help['fallback'] ] );
		$this->assertStringStartsWith( '# Minha Crônica', $help['content'] );
		$this->assertSame( [ 'pt_BR', false ], [ $guide['language'], $guide['fallback'] ] );
		$this->assertStringStartsWith( '# Guia do Jogador', $guide['content'] );
	}

	public function test_a_portuguese_viewer_gets_the_english_page_when_it_has_no_translation(): void {
		$this->as_portuguese_viewer();
		$page = BE_PLUGIN_DIR . 'docs/help/zz-untranslated-test-page.md';
		file_put_contents( $page, "# Untranslated\n" );

		try {
			$response = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/docs/help/zz-untranslated-test-page' ) );
		} finally {
			unlink( $page );
		}

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'en', true ], [ $data['language'], $data['fallback'] ] );
		$this->assertSame( "# Untranslated\n", $data['content'] );
	}

	public function test_every_guide_reports_whether_the_portuguese_viewer_got_a_translation(): void {
		$this->as_portuguese_viewer();

		foreach ( [ 'st-guide', 'admin-guide', 'player-guide', 'rest-api' ] as $slug ) {
			$data       = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/docs/{$slug}" ) )->get_data();
			$translated = is_readable( BE_PLUGIN_DIR . "docs/pt_BR/{$slug}.md" );

			$this->assertSame( $translated ? 'pt_BR' : 'en', $data['language'], $slug );
			$this->assertSame( ! $translated, $data['fallback'], $slug );
		}
	}

	public function test_a_page_that_does_not_exist_is_not_found_in_either_language(): void {
		$this->as_portuguese_viewer();

		foreach ( [ 'no-such-screen', 'st-guide', '..%2Fst-guide', 'Character-Sheet' ] as $key ) {
			$this->assertSame( 404, $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/docs/help/' . $key ) )->get_status(), $key );
		}
		$this->assertSame( 404, $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/docs/../../../etc/passwd' ) )->get_status() );
	}
}
