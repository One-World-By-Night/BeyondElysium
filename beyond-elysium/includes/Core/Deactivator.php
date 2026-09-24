<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin deactivation entry point.
 */
class Deactivator {

	/**
	 * Removes every custom capability this plugin registered from all of its granted roles.
	 */
	public static function deactivate(): void {
		Capabilities::unregister();
		Maintenance::unschedule();
	}
}
