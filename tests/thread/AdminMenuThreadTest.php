<?php

namespace BeyondElysium\Tests\Thread;

use WP_UnitTestCase;
use BeyondElysium\Core\Admin_Menu;

/**
 * admin-menu-consolidation-design.md: 16 flat wp-admin submenus collapsed to
 * 8 group hub pages plus a landing Dashboard (9 visible rows total). No REST
 * endpoint changed, so this is the one new test this phase needs -
 * confirming the reduced, renamed page structure actually registers, and
 * that the stale-slug fix-links this replaced (Setup_Status_Controller.php)
 * now point at real registered pages.
 *
 * The Dashboard row stays visible rather than hidden via
 * remove_submenu_page() - a real bug found live during this phase's own
 * browser verification: WordPress core builds the top-level label's own
 * href from the first REMAINING $submenu entry for the parent slug, not
 * from whatever slug add_menu_page() was originally given, so hiding this
 * row silently repointed the top-level "Beyond Elysium" click at
 * "Characters" (the new first entry) instead of the dashboard.
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

	public function test_exactly_nine_visible_submenu_pages_are_registered(): void {
		// The Dashboard row (slug == parent slug) is deliberately visible - see
		// Admin_Menu::add_pages()'s comment on that add_submenu_page() call - so this
		// is 9, the 8 groups plus the landing dashboard, not a bare 8.
		$this->assertCount( 9, $this->registered_slugs() );
	}

	public function test_the_dashboard_plus_eight_hub_slugs_replace_the_old_sixteen_flat_pages(): void {
		$this->assertSame(
			[
				'beyond-elysium',
				'beyond-elysium-characters',
				'beyond-elysium-plots',
				'beyond-elysium-world-objects',
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
		// WordPress core builds the top-level label's own href from $submenu[parent][0][2]
		// - the FIRST row, in registration order - not from add_menu_page()'s own slug.
		// This is exactly the bug this test guards: it was previously possible for a
		// later add_submenu_page() call (or, as actually happened, removing this row
		// entirely) to silently become "row 0" instead, repointing the top-level click.
		global $submenu;
		$this->assertSame( 'beyond-elysium', $submenu['beyond-elysium'][0][2] );
	}
}
