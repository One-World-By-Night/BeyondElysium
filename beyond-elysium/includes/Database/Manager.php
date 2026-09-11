<?php

namespace BeyondElysium\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around $wpdb for Beyond Elysium's own database tables.
 *
 * Provides table-name resolution plus prepared insert/update/delete/get_row/
 * get_results/get_var helpers. Every plugin table is addressed by its short
 * name (e.g. 'games', 'characters') and resolved to the full prefixed table
 * name via table().
 */
class Manager {

	/**
	 * Builds the fully-qualified table name for a short table name.
	 * Combines the WordPress table prefix with the plugin's own 'be_'
	 * prefix. Every other method in this class calls through it.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'be_' . $name;
	}

	/**
	 * Inserts a row into a plugin table and returns its new ID.
	 * Resolves the short table name and delegates to $wpdb->insert().
	 * Returns false when the insert fails rather than throwing.
	 *
	 * @param string $table Short table name (e.g. 'games', 'characters').
	 * @param array  $data  Column => value pairs.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function insert( string $table, array $data ) {
		global $wpdb;

		$result = $wpdb->insert( self::table( $table ), $data );
		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Updates rows in a plugin table that match the given WHERE conditions.
	 * Resolves the short table name and delegates to $wpdb->update().
	 * Returns the number of matched rows, which may be zero.
	 *
	 * @param string $table Short table name.
	 * @param array  $data  Column => value pairs to update.
	 * @param array  $where Column => value pairs for WHERE clause.
	 * @return int|false Number of rows updated, or false on error.
	 */
	public static function update( string $table, array $data, array $where ) {
		global $wpdb;
		return $wpdb->update( self::table( $table ), $data, $where );
	}

	/**
	 * Deletes rows from a plugin table that match the given WHERE conditions.
	 * Resolves the short table name and delegates to $wpdb->delete().
	 * Returns the number of matched rows, which may be zero.
	 *
	 * @param string $table Short table name.
	 * @param array  $where Column => value pairs for WHERE clause.
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public static function delete( string $table, array $where ) {
		global $wpdb;
		return $wpdb->delete( self::table( $table ), $where );
	}

	/**
	 * Fetches a single row using a prepared query.
	 * Applies $wpdb->prepare() when arguments are given, then delegates
	 * to $wpdb->get_row(). Returns null when no row matches.
	 *
	 * @param string $query SQL with %s/%d placeholders.
	 * @param mixed  ...$args Values for placeholders.
	 * @return object|null Row object or null.
	 */
	public static function get_row( string $query, ...$args ): ?object {
		global $wpdb;

		if ( $args ) {
			$query = $wpdb->prepare( $query, ...$args );
		}

		return $wpdb->get_row( $query );
	}

	/**
	 * Fetches multiple rows using a prepared query.
	 * Applies $wpdb->prepare() when arguments are given, then delegates
	 * to $wpdb->get_results(). Returns an empty array when nothing matches.
	 *
	 * @param string $query SQL with %s/%d placeholders.
	 * @param mixed  ...$args Values for placeholders.
	 * @return array Array of row objects.
	 */
	public static function get_results( string $query, ...$args ): array {
		global $wpdb;

		if ( $args ) {
			$query = $wpdb->prepare( $query, ...$args );
		}

		return $wpdb->get_results( $query ) ?: [];
	}

	/**
	 * Fetches a single scalar value using a prepared query.
	 * Applies $wpdb->prepare() when arguments are given, then delegates
	 * to $wpdb->get_var(). Returns null when no value matches.
	 *
	 * @param string $query SQL with %s/%d placeholders.
	 * @param mixed  ...$args Values for placeholders.
	 * @return mixed Single value or null.
	 */
	public static function get_var( string $query, ...$args ) {
		global $wpdb;

		if ( $args ) {
			$query = $wpdb->prepare( $query, ...$args );
		}

		return $wpdb->get_var( $query );
	}
}
