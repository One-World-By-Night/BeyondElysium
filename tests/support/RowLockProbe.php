<?php

namespace BeyondElysium\Tests\Support;

/**
 * Asks, from a second database connection, whether another request could lock a row right now.
 */
class RowLockProbe {

	/**
	 * @param string $table Unprefixed Beyond Elysium table name, e.g. `characters`.
	 * @param int    $id
	 * @return bool True when the other connection got the row within a second.
	 */
	public static function could_lock( string $table, int $id ): bool {
		global $wpdb;

		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->suppress_errors( true );
		$other->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$other->query( 'START TRANSACTION' );
		$got = $other->get_var( $other->prepare( "SELECT id FROM {$wpdb->prefix}be_{$table} WHERE id = %d FOR UPDATE", $id ) );
		$other->query( 'ROLLBACK' );
		$other->close();

		return $got !== null;
	}
}
