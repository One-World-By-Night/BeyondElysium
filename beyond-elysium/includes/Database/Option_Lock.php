<?php

namespace BeyondElysium\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A named lock held across requests: one timestamped row in the options
 * table, inserted only if absent - the way WordPress's own updater locks.
 * MySQL decides which of two requests inserts it, so exactly one holds it.
 * A lock older than its time-to-live is stale and can be taken over, so a
 * request that died holding one never blocks the work for good.
 *
 * Used for the data upgrade (1.0.0-review F-064) and for committing an
 * import job (F-070).
 */
class Option_Lock {

	/**
	 * Takes the lock if no one holds it, or if the one holding it has held it
	 * longer than `$ttl` seconds.
	 *
	 * @param string $name Option name for the lock row.
	 * @param int    $ttl  Seconds after which a held lock is stale.
	 * @return bool Whether this request now holds the lock.
	 */
	public static function claim( string $name, int $ttl ): bool {
		global $wpdb;
		$now = time();

		$claimed = (int) $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
			$name,
			(string) $now
		) );
		if ( $claimed === 0 ) {
			$claimed = (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) < %d",
				(string) $now,
				$name,
				$now - $ttl
			) );
		}

		wp_cache_delete( $name, 'options' );
		return $claimed > 0;
	}

	/**
	 * Releases the lock, letting the next request take it straight away.
	 *
	 * @param string $name Option name for the lock row.
	 */
	public static function release( string $name ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, [ 'option_name' => $name ] );
		wp_cache_delete( $name, 'options' );
	}
}
