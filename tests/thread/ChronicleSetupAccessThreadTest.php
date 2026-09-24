<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Admin_Menu;
use WP_UnitTestCase;

/**
 * Chronicle Setup is for staff.
 */
class ChronicleSetupAccessThreadTest extends WP_UnitTestCase {

	/** @var mixed the real `$GLOBALS['wp_scripts']` this test borrows and must give back. */
	private $real_wp_scripts;
	private int $subscriber_id;
	private int $editor_id;
	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		// A fresh WP_Scripts per test, given back in tearDown().
		$this->real_wp_scripts = $GLOBALS['wp_scripts'] ?? null;
		$GLOBALS['wp_scripts']  = null;

		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->editor_id     = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );

		wp_set_current_user( $this->admin_id );
		global $menu, $submenu;
		$menu    = [];
		$submenu = [];
		Admin_Menu::add_pages();
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		$GLOBALS['wp_scripts'] = $this->real_wp_scripts;
		parent::tearDown();
	}

	/** @return array<string,string> page slug => the capability WordPress checks before opening it */
	private function capability_by_slug(): array {
		global $submenu;
		$found = [];
		foreach ( $submenu['beyond-elysium'] ?? [] as $row ) {
			$found[ $row[2] ] = $row[1];
		}
		return $found;
	}

	/**
	 * The `var beyondElysium = {...}` this handle would print, decoded.
	 */
	private function localized_payload( string $handle ): array {
		$data = wp_scripts()->get_data( $handle, 'data' );
		$this->assertIsString( $data, "No localized data attached to {$handle}." );
		$decoded = json_decode( substr( (string) $data, (int) strpos( (string) $data, '{' ), (int) strrpos( (string) $data, '}' ) - (int) strpos( (string) $data, '{' ) + 1 ), true );
		$this->assertIsArray( $decoded );
		return $decoded;
	}

	public function test_the_chronicle_setup_page_needs_the_chronicle_setup_capability(): void {
		$capability = $this->capability_by_slug()['beyond-elysium-chronicle-setup-hub'] ?? null;

		$this->assertSame( 'be_manage_chronicle_setup', $capability );
		$this->assertFalse( user_can( $this->subscriber_id, (string) $capability ), 'a player must not open it' );
		$this->assertTrue( user_can( $this->editor_id, (string) $capability ), 'an HST is a WordPress editor' );
		$this->assertTrue( user_can( $this->admin_id, (string) $capability ) );
	}

	public function test_the_only_pages_open_to_a_player_are_the_dashboard_and_docs(): void {
		$open = [];
		foreach ( $this->capability_by_slug() as $slug => $capability ) {
			if ( user_can( $this->subscriber_id, $capability ) ) {
				$open[] = $slug;
			}
		}
		sort( $open );

		$this->assertSame( [ 'beyond-elysium', 'beyond-elysium-docs' ], $open );
	}

	public function test_the_admin_bundle_is_told_whether_the_viewer_may_use_chronicle_setup(): void {
		foreach ( [ [ $this->editor_id, true ], [ $this->subscriber_id, false ], [ $this->admin_id, true ] ] as [ $user_id, $expected ] ) {
			$GLOBALS['wp_scripts'] = null;
			wp_set_current_user( $user_id );
			Admin_Menu::enqueue_assets( 'toplevel_page_beyond-elysium' );

			$capabilities = $this->localized_payload( 'beyond-elysium-admin' )['capabilities'] ?? [];

			$this->assertArrayHasKey( 'be_manage_chronicle_setup', $capabilities );
			$this->assertSame( $expected, $capabilities['be_manage_chronicle_setup'] );
		}
	}
}
