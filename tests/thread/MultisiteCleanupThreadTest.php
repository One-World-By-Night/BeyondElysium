<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Multisite;
use BeyondElysium\Database\Schema;
use WP_UnitTestCase;

/**
 * The three multisite defects 1.0.2 closes, each measured on a real network first.
 *
 * Everything else about running on multisite already worked, and for a real reason: table
 * names come from `$wpdb->prefix`, settings are per-site options, roles are per-site. Two
 * subsites activated side by side each built their own 17 tables and 69 catalog blocks, saw
 * only their own characters, and switching one off left the other alone.
 *
 * Cleanup was where it broke, because cleanup is where a plugin has to declare what is its:
 *
 *   1. Deleting a subsite left every table behind - 40 orphaned `be_*` tables survived
 *      deleting two test subsites on the real network.
 *   2. Uninstall swept `be_notifications_opt_out` from `$wpdb->usermeta`, a single **global**
 *      table on a network, so one chronicle deleting its data cleared that preference for
 *      every user on every other chronicle.
 *
 * These run on a single-site test harness, so they test the seams rather than a live network:
 * that the filter names the right tables for an arbitrary blog id, and that the user scope is
 * a real list rather than "everyone".
 *
 * @see BE_PROCESS/releases/1.0.2-design-workflow.md
 */
class MultisiteCleanupThreadTest extends WP_UnitTestCase {

	public function test_every_plugin_table_is_handed_to_wordpress_when_a_site_is_deleted(): void {
		global $wpdb;

		$tables = Multisite::drop_tables_with_site( [], 1 );
		$prefix = $wpdb->get_blog_prefix( 1 );

		foreach ( Schema::TABLES as $table ) {
			$this->assertContains(
				$prefix . 'be_' . $table,
				$tables,
				"A table WordPress is not told about is a table left behind when a chronicle is deleted."
			);
		}

		$this->assertCount(
			count( Schema::TABLES ),
			$tables,
			'Every table, and nothing that is not ours.'
		);
	}

	/** A new table added to the schema must be dropped with a site without anyone remembering. */
	public function test_the_table_list_is_derived_not_hand_maintained(): void {
		global $wpdb;

		$tables = Multisite::drop_tables_with_site( [], 1 );
		$prefix = $wpdb->get_blog_prefix( 1 );

		$expected = array_map(
			static fn( $t ) => $prefix . 'be_' . $t,
			Schema::TABLES
		);

		$this->assertSame(
			$expected,
			$tables,
			'Derived straight from Schema::TABLES, so a new table needs no second edit here.'
		);
	}

	public function test_it_keeps_whatever_wordpress_was_already_dropping(): void {
		$tables = Multisite::drop_tables_with_site( [ 'wp_2_posts', 'wp_2_options' ], 2 );

		$this->assertContains( 'wp_2_posts', $tables );
		$this->assertContains( 'wp_2_options', $tables );
	}

	/**
	 * The filter can run while the *current* site is a different one - a super admin deleting a
	 * chronicle from Network Admin - so it must name the tables of the site being deleted, not
	 * of whichever site happens to be loaded. Naming the wrong site's tables is worse than
	 * leaving orphans behind.
	 */
	public function test_it_names_the_tables_of_the_site_being_deleted_not_the_current_one(): void {
		global $wpdb;

		$other = Multisite::drop_tables_with_site( [], 7 );

		$this->assertNotEmpty( $other );
		foreach ( $other as $table ) {
			$this->assertStringStartsWith( $wpdb->get_blog_prefix( 7 ), $table );
		}
	}

	/**
	 * Uninstall must clear the per-user preference for this site's users only. Asserted as a
	 * real id list: a bare "delete every row with this meta_key" is the bug being fixed, and it
	 * would still pass a test that only checked the current user was included.
	 */
	public function test_the_user_scope_for_uninstall_is_a_real_list(): void {
		$mine = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$ids = Multisite::user_ids_for_this_site();

		$this->assertIsArray( $ids );
		$this->assertContains( $mine, $ids );
		$this->assertContainsOnly( 'int', $ids, 'These are interpolated into SQL - they must be integers.' );
	}
}
