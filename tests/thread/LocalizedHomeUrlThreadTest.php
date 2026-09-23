<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Plugin;
use WP_UnitTestCase;

/**
 * 1.2.11 D95: both localize payloads hand the client this site's own base URL.
 *
 * `chronicles.owbn.net` is a multisite network, and on a subsite (`/bbf/`) the client's
 * `window.location.origin` is the *network* root - so every link the plugin built to a
 * provisioned page dropped the subsite path and landed on the wrong site entirely. The fix
 * shipped to that install as `1.2.9.1`, a build that never existed in this repository;
 * these assertions are what stop 1.2.11 overwriting it.
 *
 * Both payloads are checked because there are exactly two: `Plugin::enqueue_frontend()`
 * for the front end and `Admin_Menu`'s for wp-admin. They are separate literals, so one
 * can carry the field while the other silently does not - which is the shape of the
 * original omission.
 */
class LocalizedHomeUrlThreadTest extends WP_UnitTestCase {

	/** @var mixed the real `$GLOBALS['wp_scripts']` this test borrows and must give back. */
	private $real_wp_scripts;

	public function setUp(): void {
		parent::setUp();
		// `wp_localize_script()` APPENDS to whatever the handle already carries, and
		// `EnqueueFrontendGateTest` enqueues the same front-end handle twice. Left alone, this
		// test would read two concatenated `var beyondElysium = {...};` statements and fail to
		// decode them - a test-order artifact, not a product fault. A fresh WP_Scripts per test
		// keeps the payload this test reads the one it just caused.
		//
		// D95 fixed a real defect this introduced: nulling the global and never restoring it
		// left every later test in the same process running against an unprimed WP_Scripts -
		// none of WordPress's own default script registrations survive - which broke two
		// entirely unrelated thread tests (GameSessionsThreadTest, ImportAtomicityRealAutocommitTest)
		// whenever this class ran before them. Borrow the global, give it back in tearDown().
		$this->real_wp_scripts = $GLOBALS['wp_scripts'] ?? null;
		$GLOBALS['wp_scripts']  = null;
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		$GLOBALS['wp_scripts'] = $this->real_wp_scripts;
		parent::tearDown();
	}

	/** The `var beyondElysium = {...}` this handle would print, decoded. */
	private function localized_payload( string $handle ): array {
		$data = wp_scripts()->get_data( $handle, 'data' );
		$this->assertIsString( $data, "No localized data attached to {$handle}." );

		$start = strpos( (string) $data, '{' );
		$end   = strrpos( (string) $data, '}' );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );

		$decoded = json_decode( substr( (string) $data, $start, $end - $start + 1 ), true );
		$this->assertIsArray( $decoded, "Could not decode {$handle}'s payload." );
		return $decoded;
	}

	public function test_the_front_end_payload_carries_home_url(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		Plugin::enqueue_frontend();

		$payload = $this->localized_payload( 'beyond-elysium' );

		$this->assertArrayHasKey( 'homeUrl', $payload );
		// The exact trailing-slashed value, which is also what the client's own
		// trailing-slash strip is written against.
		$this->assertSame( trailingslashit( home_url() ), $payload['homeUrl'] );
		$this->assertStringEndsWith( '/', $payload['homeUrl'] );
	}

	public function test_the_admin_payload_carries_home_url(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		\BeyondElysium\Core\Admin_Menu::enqueue_assets( 'toplevel_page_beyond-elysium' );

		$payload = $this->localized_payload( 'beyond-elysium-admin' );

		$this->assertArrayHasKey( 'homeUrl', $payload );
		$this->assertSame( trailingslashit( home_url() ), $payload['homeUrl'] );
	}
}
