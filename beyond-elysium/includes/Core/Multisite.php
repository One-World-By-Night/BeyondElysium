<?php

namespace BeyondElysium\Core;

use BeyondElysium\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The handful of places WordPress multisite needs to be told about this plugin's own tables.
 */
class Multisite {

	/**
	 * Registers the network hooks.
	 */
	public static function register(): void {
		add_filter( 'wpmu_drop_tables', [ self::class, 'drop_tables_with_site' ], 10, 2 );
	}

	/**
	 * Adds this plugin's tables to the list WordPress drops when a subsite is deleted.
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
	 * The users whose per-user plugin settings belong to *this* site, for an uninstall to clear without reaching across
	 * the network.
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
