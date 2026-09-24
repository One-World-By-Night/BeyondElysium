<?php

namespace BeyondElysium\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A named lock held across requests: one timestamped row in the options table, inserted only if absent.
 */
class Option_Lock {

	/**
	 * Takes the lock if no one holds it, or if the one holding it has held it longer than `$ttl` seconds.
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
	 * When the lock was last claimed, or 0 when nobody holds it.
	 *
	 * @param string $name Option name for the lock row.
	 * @return int Unix time of the claim.
	 */
	public static function held_since( string $name ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT CAST( option_value AS UNSIGNED ) FROM {$wpdb->options} WHERE option_name = %s",
			$name
		) );
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
