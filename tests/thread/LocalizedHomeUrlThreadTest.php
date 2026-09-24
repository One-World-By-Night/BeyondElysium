<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Plugin;
use WP_UnitTestCase;

/**
 * Both localize payloads hand the client this site's own base URL.
 */
class LocalizedHomeUrlThreadTest extends WP_UnitTestCase {

	/** @var mixed the real `$GLOBALS['wp_scripts']` this test borrows and must give back. */
	private $real_wp_scripts;

	public function setUp(): void {
		parent::setUp();
		$this->real_wp_scripts = $GLOBALS['wp_scripts'] ?? null;
		$GLOBALS['wp_scripts']  = null;
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		$GLOBALS['wp_scripts'] = $this->real_wp_scripts;
		parent::tearDown();
	}

	/**
	 * The `var beyondElysium = {...}` this handle would print, decoded.
	 */
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
		// The exact trailing-slashed value.
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
