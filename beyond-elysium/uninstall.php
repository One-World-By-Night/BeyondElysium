<?php
/**
 * Runs once, only when a site administrator explicitly clicks "Delete" on
 * this plugin from the Plugins screen after deactivating it - never on a
 * plain deactivation or an update.
 *
 * Safe by default: does nothing at all unless an administrator has
 * explicitly turned on the "delete data on uninstall" setting beforehand
 * (Data_Management_Controller, be_delete_data_on_uninstall option). Every
 * chronicle's games, characters, and catalog customizations survive a
 * plain uninstall untouched, matching how this plugin has always behaved -
 * the setting makes that a real, documented choice instead of an accident.
 */

// WordPress core defines this constant right before including this file;
// its absence means the file is being requested directly, not by core.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'be_delete_data_on_uninstall' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;

foreach ( \BeyondElysium\Database\Schema::TABLES as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'be_' . $table );
}

// Named options plus a wildcard sweep for anything dynamically-keyed (per-game/per-job
// transients such as be_game_stats_{slug}, be_import_job_{id}) that a fixed list would miss.
// MySQL's default LIKE escape character is backslash, confirmed directly - no ESCAPE clause needed.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'be\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_be\_%' OR option_name LIKE '\_transient\_timeout\_be\_%'" );

// Per-user notification preference (v0.21.20) - not covered by the options sweep above.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'be_notifications_opt_out'" );

\BeyondElysium\Core\Capabilities::unregister();
