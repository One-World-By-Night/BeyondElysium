<?php

namespace BeyondElysium\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks nested SAVEPOINT/START TRANSACTION calls on a single database connection.
 *
 * Lets cascading deletes (e.g. a Game deleting its Characters, Plots and
 * World_Objects) open and close units of work at multiple call-stack levels
 * without one nested call's transaction silently committing an outer call's
 * still-open transaction. Open units are tracked in a plain PHP list rather
 * than derived from MySQL session state on every call.
 */
class Transaction {

	/**
	 * Units of work still open, outermost first. `savepoint` records whether the unit was
	 * opened as a SAVEPOINT (nested, or inside a transaction this class did not open) or as
	 * a real START TRANSACTION.
	 *
	 * @var array<int,array{name:string,savepoint:bool}>
	 */
	private static array $open = [];

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
		$name   = $savepoint . '_' . count( self::$open );
		$nested = self::$open !== [] || self::is_ambient();

		$wpdb->query( $nested ? "SAVEPOINT {$name}" : 'START TRANSACTION' );

		self::$open[] = [ 'name' => $name, 'savepoint' => $nested ];
		return $name;
	}

	/**
	 * Commits the unit of work started by the matching begin() call.
	 * Releases the savepoint when it was opened nested inside an outer transaction;
	 * otherwise issues a full COMMIT and closes the outermost level.
	 *
	 * @param string $name The name begin() returned.
	 */
	public static function commit( string $name ): void {
		global $wpdb;
		$unit = self::close( $name );
		if ( $unit === null ) {
			return;
		}

		$wpdb->query( $unit['savepoint'] ? "RELEASE SAVEPOINT {$name}" : 'COMMIT' );
	}

	/**
	 * Rolls back the unit of work started by the matching begin() call.
	 * Rolls back to the savepoint when it was opened nested inside an outer transaction;
	 * otherwise issues a full ROLLBACK and closes the outermost level.
	 *
	 * @param string $name The name begin() returned.
	 */
	public static function rollback( string $name ): void {
		global $wpdb;
		$unit = self::close( $name );
		if ( $unit === null ) {
			return;
		}

		$wpdb->query( $unit['savepoint'] ? "ROLLBACK TO SAVEPOINT {$name}" : 'ROLLBACK' );
	}

	/**
	 * Removes the named unit from the open list, along with any inner unit above it that
	 * never closed - an exception thrown between an inner begin() and its commit() or
	 * rollback() leaves one behind (1.0.0-review F-004). MySQL discards those inner
	 * savepoints itself when the named unit is released, rolled back, committed, or ended.
	 *
	 * @param string $name
	 * @return array{name:string,savepoint:bool}|null Null when no open unit has that name.
	 */
	private static function close( string $name ): ?array {
		for ( $i = count( self::$open ) - 1; $i >= 0; $i-- ) {
			if ( self::$open[ $i ]['name'] === $name ) {
				$unit       = self::$open[ $i ];
				self::$open = array_slice( self::$open, 0, $i );
				return $unit;
			}
		}
		return null;
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
