<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Multisite;
use BeyondElysium\Database\Schema;
use WP_UnitTestCase;

/**
 * Multisite cleanup: a deleted subsite takes its tables, a deleted user's memberships are removed, and an uninstall
 * clears only its own site's settings.
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

	/**
	 * A new table added to the schema must be dropped with a site without anyone remembering.
	 */
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
	 * The filter can run while the *current* site is a different one.
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
	 * Uninstall must clear the per-user preference for this site's users only.
	 */
	public function test_the_user_scope_for_uninstall_is_a_real_list(): void {
		$mine = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$ids = Multisite::user_ids_for_this_site();

		$this->assertIsArray( $ids );
		$this->assertContains( $mine, $ids );
		$this->assertContainsOnly( 'int', $ids, 'These are interpolated into SQL - they must be integers.' );
	}
}
