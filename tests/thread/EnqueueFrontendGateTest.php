<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Plugin;
use WP_UnitTestCase;

/**
 * `Plugin::enqueue_frontend()` loads the media and editor assets only for logged-in users.
 */
class EnqueueFrontendGateTest extends WP_UnitTestCase {

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_an_anonymous_visitor_does_not_get_media_or_editor_scripts(): void {
		wp_set_current_user( 0 );
		Plugin::enqueue_frontend();

		$this->assertFalse( wp_script_is( 'media-editor', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'editor', 'enqueued' ) );
	}

	public function test_a_logged_in_visitor_still_gets_media_and_editor_scripts(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		Plugin::enqueue_frontend();

		$this->assertTrue( wp_script_is( 'media-editor', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'editor', 'enqueued' ) );
	}
}
