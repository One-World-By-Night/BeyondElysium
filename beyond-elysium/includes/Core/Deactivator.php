<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin deactivation entry point. Strips Beyond Elysium's custom
 * capabilities back off every role they were granted to, leaving
 * database tables and stored data untouched. Runs once when the plugin
 * is deactivated in wp-admin.
 */
class Deactivator {

	/**
	 * Removes every custom capability this plugin registered from all of
	 * its granted roles. Does not drop tables or delete any stored data;
	 * reactivating the plugin re-registers the same capabilities.
	 */
	public static function deactivate(): void {
		Capabilities::unregister();
	}
}
