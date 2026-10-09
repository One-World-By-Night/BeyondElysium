<?php

namespace BeyondElysium\Core;

use BeyondElysium\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin activation entry point.
 */
class Activator {

	/**
	 * Creates the tables and capabilities, then runs the same whole upgrade an update runs (which seeds the catalog,
	 * stacks and templates on a fresh install and records the version once it has finished), then provisions any missing
	 * default page.
	 */
	public static function activate(): void {
		Schema::create_tables();
		Capabilities::register();

		Schema::maybe_upgrade();

		// Provisions any default page that is missing.
		do_action( 'be_after_upgrade' );
	}
}
