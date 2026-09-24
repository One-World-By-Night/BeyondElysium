<?php
/**
 * Runs once, only when an administrator explicitly clicks "Delete" on this plugin from the Plugins screen after
 * deactivating it.
 */

// Refuses to run unless WordPress core is uninstalling the plugin.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Removes this plugin's data from whichever site is current.
 */
$be_uninstall_current_site = static function (): void {
	global $wpdb;

	if ( ! get_option( 'be_delete_data_on_uninstall' ) ) {
		return;
	}

	foreach ( \BeyondElysium\Database\Schema::TABLES as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'be_' . $table );
	}

	// Deletes the named options and any dynamically-keyed transients.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'be\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_be\_%' OR option_name LIKE '\_transient\_timeout\_be\_%'" );

	// Per-user notification preference.
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

	// Deletes the private upload directory.
	\BeyondElysium\Services\Attachment_Storage::remove_all();
};

if ( ! is_multisite() ) {
	$be_uninstall_current_site();
	return;
}

// Visits the sites in batches.
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
