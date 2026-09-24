<?php

namespace BeyondElysium\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks nested SAVEPOINT/START TRANSACTION calls on a single database connection.
 */
class Transaction {

	/**
	 * Units of work still open, outermost first.
	 *
	 * @var array<int,array{name:string,savepoint:bool}>
	 */
	private static array $open = [];

	/**
	 * Begin a unit of work.
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
	 * Removes the named unit from the open list, along with any inner unit above it that never closed.
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
	 * Checks whether the connection is already inside a transaction this class did not itself open.
	 */
	private static function is_ambient(): bool {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT @@autocommit' ) === 0;
	}
}
