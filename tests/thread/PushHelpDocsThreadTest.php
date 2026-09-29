<?php

namespace BeyondElysium\Tests\Thread;

use WP_UnitTestCase;

/**
 * `bin/push-help-docs.php` saves each help page's text as written, backslashes included.
 */
class PushHelpDocsThreadTest extends WP_UnitTestCase {

	private const JSON = '/tmp/help-docs.json';

	private ?string $prior_json = null;

	public function setUp(): void {
		parent::setUp();
		register_post_type( 'docs', [ 'public' => true ] );
		register_taxonomy( 'doc_category', 'docs' );
		$this->prior_json = file_exists( self::JSON ) ? (string) file_get_contents( self::JSON ) : null;
	}

	public function tearDown(): void {
		if ( $this->prior_json === null ) {
			@unlink( self::JSON ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} else {
			file_put_contents( self::JSON, $this->prior_json );
		}
		unregister_taxonomy( 'doc_category' );
		unregister_post_type( 'docs' );
		parent::tearDown();
	}

	/**
	 * Runs the script against the given pages and returns what it printed.
	 *
	 * @param array<int,array<string,string>> $docs
	 */
	private function push( array $docs ): string {
		file_put_contents( self::JSON, (string) wp_json_encode( $docs ) );
		ob_start();
		include BE_PLUGIN_ROOT . '/bin/push-help-docs.php';
		return (string) ob_get_clean();
	}

	public function test_a_new_page_keeps_its_backslashes(): void {
		$content = 'Save it under C:\\Users\\you, and type \\"quoted\\" text.';

		$this->push( [ [ 'slug' => 'thread-help-backslash', 'title' => 'Back\\slash', 'content' => $content ] ] );

		$post = get_page_by_path( 'thread-help-backslash', OBJECT, 'docs' );
		$this->assertSame( $content, $post->post_content );
		$this->assertSame( 'Back\\slash', $post->post_title );
	}

	public function test_an_updated_page_keeps_its_backslashes(): void {
		$this->push( [ [ 'slug' => 'thread-help-update', 'title' => 'Update', 'content' => 'first' ] ] );
		$content = 'A regex like \\d+ stays as typed.';

		$printed = $this->push( [ [ 'slug' => 'thread-help-update', 'title' => 'Update', 'content' => $content ] ] );

		$this->assertStringContainsString( 'Updated: 1', $printed );
		$this->assertSame( $content, get_page_by_path( 'thread-help-update', OBJECT, 'docs' )->post_content );
	}
}
