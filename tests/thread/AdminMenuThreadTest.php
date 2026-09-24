<?php

namespace BeyondElysium\Tests\Thread;

use WP_UnitTestCase;
use BeyondElysium\Core\Admin_Menu;

/**
 * The wp-admin menu: eight group hub pages plus a landing Dashboard, nine visible rows in total.
 */
class AdminMenuThreadTest extends WP_UnitTestCase {

	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		global $menu, $submenu;
		$menu    = [];
		$submenu = [];
		Admin_Menu::add_pages();
	}

	/** @return array<int,string> */
	private function registered_slugs(): array {
		global $submenu;
		return array_map( static fn( $row ) => $row[2], $submenu['beyond-elysium'] ?? [] );
	}

	public function test_exactly_ten_visible_submenu_pages_are_registered(): void {
		$this->assertCount( 10, $this->registered_slugs() );
	}

	public function test_the_dashboard_plus_nine_hub_slugs_replace_the_old_sixteen_flat_pages(): void {
		$this->assertSame(
			[
				'beyond-elysium',
				'beyond-elysium-characters',
				'beyond-elysium-plots',
				'beyond-elysium-world-objects',
				'beyond-elysium-game-nights',
				'beyond-elysium-query-hub',
				'beyond-elysium-import',
				'beyond-elysium-chronicle-setup-hub',
				'beyond-elysium-system-config',
				'beyond-elysium-docs',
			],
			array_values( array_unique( $this->registered_slugs() ) )
		);
	}

	public function test_the_retired_flat_slugs_are_gone(): void {
		$retired = [
			'beyond-elysium-npc-roster',
			'beyond-elysium-schema-blocks',
			'beyond-elysium-creature-stacks',
			'beyond-elysium-templates',
			'beyond-elysium-approval-rules',
			'beyond-elysium-chronicle-access',
			'beyond-elysium-apr-settings',
			'beyond-elysium-reports',
			'beyond-elysium-query',
			'beyond-elysium-chronicle-setup',
		];
		$this->assertEmpty( array_intersect( $retired, $this->registered_slugs() ) );
	}

	public function test_the_dashboard_is_the_first_row_so_the_top_level_click_lands_there(): void {
		// WordPress core builds the top-level label's own href from $submenu[parent][0][2].
		global $submenu;
		$this->assertSame( 'beyond-elysium', $submenu['beyond-elysium'][0][2] );
	}
}
