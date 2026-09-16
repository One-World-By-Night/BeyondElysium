<?php

namespace BeyondElysium\Core;

use BeyondElysium\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The handful of places WordPress multisite needs to be told about this plugin's own tables.
 *
 * Everything else about running on a network already worked, and worked for a real reason
 * rather than by luck: every table name is built from `$wpdb->prefix`, every setting is a
 * per-site option, and roles are per-site - so activating on one chronicle builds that
 * chronicle's own schema and touches nothing else. That was measured on a real network
 * (1.0.2): two subsites activated side by side each got their own 17 tables and 69 catalog
 * blocks, saw only their own characters, and deactivating one left the other untouched.
 *
 * What did *not* work was cleanup, which is where a plugin has to say what belongs to it:
 *
 * 1. Deleting a subsite left all 17 tables behind. WordPress drops the tables it knows about,
 *    and it only knows about a plugin's tables if the plugin adds them to `wpmu_drop_tables`.
 *    Measured: 40 orphaned `be_*` tables survived deleting two test subsites.
 * 2. Uninstalling swept `be_notifications_opt_out` out of `$wpdb->usermeta`, which is a single
 *    **global** table on a network - so deleting plugin data on one chronicle would have
 *    cleared that preference for every user on every other chronicle.
 *
 * Both are cleanup-path only; neither could fire during ordinary use. Both are real.
 *
 * @see BE_PROCESS/releases/1.0.2-design-workflow.md
 */
class Multisite {

	/**
	 * Registers the network hooks. Safe to call on a single-site install: the filter simply
	 * never fires there.
	 */
	public static function register(): void {
		add_filter( 'wpmu_drop_tables', [ self::class, 'drop_tables_with_site' ], 10, 2 );
	}

	/**
	 * Adds this plugin's tables to the list WordPress drops when a subsite is deleted.
	 *
	 * `$blog_id`'s prefix is resolved through `$wpdb->get_blog_prefix()` rather than
	 * `$wpdb->prefix`: this filter can run while the current site is a different one (a super
	 * admin deleting a chronicle from Network Admin), and using the current prefix there would
	 * name the wrong site's tables - which is worse than leaving orphans.
	 *
	 * `$tables` is typed loosely on purpose: it arrives through a filter any other plugin may
	 * have touched first, so it is checked rather than trusted.
	 *
	 * @param mixed $tables  Tables WordPress is about to drop.
	 * @param int   $blog_id The site being deleted.
	 * @return array<int,string>
	 */
	public static function drop_tables_with_site( $tables, $blog_id ): array {
		global $wpdb;

		$tables = is_array( $tables ) ? $tables : [];
		$prefix = $wpdb->get_blog_prefix( (int) $blog_id );

		foreach ( Schema::TABLES as $table ) {
			$tables[] = $prefix . 'be_' . $table;
		}

		return $tables;
	}

	/**
	 * The users whose per-user plugin settings belong to *this* site, for an uninstall to
	 * clear without reaching across the network.
	 *
	 * On a single-site install this is every user, which is the same answer the old
	 * unqualified sweep gave - so nothing changes there. On a network it is the members of
	 * the site being uninstalled from, and no one else.
	 *
	 * @return int[] Empty when there is nothing to clear.
	 */
	public static function user_ids_for_this_site(): array {
		if ( ! is_multisite() ) {
			return array_map( 'intval', (array) get_users( [ 'fields' => 'ID' ] ) );
		}

		return array_map( 'intval', (array) get_users( [
			'blog_id' => get_current_blog_id(),
			'fields'  => 'ID',
		] ) );
	}
}
