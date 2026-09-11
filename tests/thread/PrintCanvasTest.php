<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Page_Provisioner;
use BeyondElysium\Core\Print_Canvas;
use WP_UnitTestCase;

/**
 * Decision 052: printing showed a theme-owned box CSS could never fully hide, since it
 * never came from markup this plugin controls in the first place - fixed by routing one
 * specific page through a template that never calls the theme's own header.php/footer.php
 * at all. `is_page()`-dependent behavior needs the real WP query context `go_to()` sets up,
 * not previously used elsewhere in this suite - this is the first test that needs it.
 */
class PrintCanvasTest extends WP_UnitTestCase {

	public function test_uses_the_blank_canvas_template_on_the_print_page(): void {
		// go_to( pretty URL ) depends on rewrite rules being flushed, not otherwise
		// exercised anywhere in this suite - page_id is the unambiguous form go_to()
		// itself resolves regardless of permalink structure.
		$page_id = self::factory()->post->create( [
			'post_type'  => 'page',
			'post_name'  => Page_Provisioner::PRINT_SLUG,
			'post_title' => 'Character Sheet (Print)',
		] );

		$this->go_to( home_url( '/?page_id=' . $page_id ) );

		$result = Print_Canvas::maybe_use_blank_canvas( '/some/theme/page.php' );

		$this->assertStringEndsWith( 'includes/templates/blank-canvas.php', $result );
	}

	public function test_leaves_every_other_page_on_its_original_template(): void {
		$page_id = self::factory()->post->create( [
			'post_type'  => 'page',
			'post_name'  => 'some-other-page',
			'post_title' => 'Some Other Page',
		] );

		$this->go_to( home_url( '/?page_id=' . $page_id ) );

		$original = '/some/theme/page.php';
		$result   = Print_Canvas::maybe_use_blank_canvas( $original );

		$this->assertSame( $original, $result );
	}

	public function test_leaves_a_non_page_request_alone_too(): void {
		self::factory()->post->create( [ 'post_type' => 'post' ] );

		$this->go_to( home_url( '/' ) );

		$original = '/some/theme/index.php';
		$result   = Print_Canvas::maybe_use_blank_canvas( $original );

		$this->assertSame( $original, $result );
	}
}
