<?php
/**
 * Runs once, only when an administrator explicitly clicks "Delete" on this plugin from the
 * Plugins screen after deactivating it - never on a plain deactivation or an update.
 *
 * Safe by default: does nothing at all unless an administrator has explicitly turned on the
 * "delete data on uninstall" setting beforehand (Data_Management_Controller,
 * be_delete_data_on_uninstall option). Every chronicle's games, characters, and catalog
 * customizations survive a plain uninstall untouched, matching how this plugin has always
 * behaved - the setting makes that a real, documented choice instead of an accident.
 *
 * **On multisite** (1.0.2), deleting a plugin removes its files for the whole network, but
 * this file runs only once, in one site's context. Left unqualified that orphaned every other
 * chronicle's tables. It now walks every site and cleans each one that opted in - each site's
 * own setting decides its own data, so a chronicle that never asked for deletion keeps
 * everything even if a neighbour did.
 */

// WordPress core defines this constant right before including this file; its absence means
// the file is being requested directly, not by core.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Removes this plugin's data from whichever site is current. Reads that site's own opt-in
 * first and does nothing without it.
 */
$be_uninstall_current_site = static function (): void {
	global $wpdb;

	if ( ! get_option( 'be_delete_data_on_uninstall' ) ) {
		return;
	}

	foreach ( \BeyondElysium\Database\Schema::TABLES as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'be_' . $table );
	}

	// Named options plus a wildcard sweep for anything dynamically-keyed (per-game/per-job
	// transients such as be_game_stats_{slug}, be_import_job_{id}) that a fixed list would miss.
	// MySQL's default LIKE escape character is backslash, confirmed directly - no ESCAPE clause needed.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'be\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_be\_%' OR option_name LIKE '\_transient\_timeout\_be\_%'" );

	// Per-user notification preference (v0.21.20) - not covered by the options sweep above.
	//
	// Scoped to this site's own users. `$wpdb->usermeta` is a single **global** table on a
	// network, so the unqualified sweep this replaces would have cleared the preference for
	// every user on every other chronicle the moment one chronicle uninstalled. On a
	// single-site install this is every user, exactly as it always was.
	$user_ids = \BeyondElysium\Core\Multisite::user_ids_for_this_site();
	if ( $user_ids ) {
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'be_notifications_opt_out' AND user_id IN ({$placeholders})",
				$user_ids
			)
		);
	}

	\BeyondElysium\Core\Capabilities::unregister();
};

if ( ! is_multisite() ) {
	$be_uninstall_current_site();
	return;
}

// Batched rather than one get_sites() call: a network can be large, and this runs during a
// request that is already deleting files.
$be_page = 0;
do {
	$be_sites = get_sites( [
		'number'     => 100,
		'offset'     => $be_page * 100,
		'fields'     => 'ids',
		'orderby'    => 'id',
		'network_id' => get_current_network_id(),
	] );

	foreach ( $be_sites as $be_site_id ) {
		switch_to_blog( (int) $be_site_id );
		$be_uninstall_current_site();
		restore_current_blog();
	}

	$be_page++;
} while ( count( $be_sites ) === 100 );
