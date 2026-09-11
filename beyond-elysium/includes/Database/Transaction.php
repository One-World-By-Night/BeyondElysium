<?php

namespace BeyondElysium\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks nested SAVEPOINT/START TRANSACTION calls on a single database connection.
 *
 * Lets cascading deletes (e.g. a Game deleting its Characters, Plots and
 * World_Objects) open and close units of work at multiple call-stack levels
 * without one nested call's transaction silently committing an outer call's
 * still-open transaction. Nesting depth is tracked with a plain PHP counter
 * rather than derived from MySQL session state on every call.
 */
class Transaction {

	private static int $depth = 0;

	/**
	 * Begin a unit of work. Pass a short, call-site-specific name (e.g.
	 * `'be_character_delete'`) - it is suffixed with the current nesting depth so
	 * simultaneously-open savepoints at different levels never collide, even if two call
	 * sites ever pass the same base name.
	 *
	 * @param string $savepoint
	 * @return string The exact savepoint name to pass to commit()/rollback() for this call.
	 */
	public static function begin( string $savepoint ): string {
		global $wpdb;
		$name = $savepoint . '_' . self::$depth;

		if ( self::$depth > 0 || self::is_ambient() ) {
			$wpdb->query( "SAVEPOINT {$name}" );
		} else {
			$wpdb->query( 'START TRANSACTION' );
		}

		self::$depth++;
		return $name;
	}

	/**
	 * Commits the unit of work started by the matching begin() call.
	 * Releases the savepoint when still nested inside an outer transaction;
	 * otherwise issues a full COMMIT and closes the outermost level.
	 *
	 * @param string $name The name begin() returned.
	 */
	public static function commit( string $name ): void {
		global $wpdb;
		self::$depth--;

		if ( self::$depth > 0 || self::is_ambient() ) {
			$wpdb->query( "RELEASE SAVEPOINT {$name}" );
		} else {
			$wpdb->query( 'COMMIT' );
		}
	}

	/**
	 * Rolls back the unit of work started by the matching begin() call.
	 * Rolls back to the savepoint when still nested inside an outer transaction;
	 * otherwise issues a full ROLLBACK and closes the outermost level.
	 *
	 * @param string $name The name begin() returned.
	 */
	public static function rollback( string $name ): void {
		global $wpdb;
		self::$depth--;

		if ( self::$depth > 0 || self::is_ambient() ) {
			$wpdb->query( "ROLLBACK TO SAVEPOINT {$name}" );
		} else {
			$wpdb->query( 'ROLLBACK' );
		}
	}

	/**
	 * Checks whether the connection is already inside a transaction this
	 * class did not itself open. Reads MySQL's @@autocommit session variable
	 * directly on every call rather than caching the result.
	 */
	private static function is_ambient(): bool {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT @@autocommit' ) === 0;
	}
}
