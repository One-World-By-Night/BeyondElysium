<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit tests run with no WordPress at all. Thread and workflow tests need the WordPress
 * test suite; when WP_TESTS_DIR is not set they are skipped rather than failed, so a fresh
 * clone can still run `vendor/bin/phpunit --testsuite unit`.
 *
 * See BE_PROCESS/TESTING.md.
 */

define( 'BE_TESTS_DIR', __DIR__ );

// The repository root. Reference material a test reads directly - GV301Source/,
// data-samples/, tests/fixtures/ - lives here, outside the shippable plugin.
define( 'BE_PLUGIN_ROOT', dirname( __DIR__ ) );

// The plugin itself, which since the Step 10h restructure is a subfolder of the repo
// rather than the repo root. Everything that ships lives under this path.
define( 'BE_PLUGIN_PATH', BE_PLUGIN_ROOT . '/beyond-elysium' );

require_once BE_PLUGIN_PATH . '/vendor/autoload.php';

/**
 * Load the WordPress test suite when it is available.
 *
 * WP_TESTS_DIR should point at the wordpress-develop tests/phpunit directory.
 *
 * This check must happen BEFORE any of this file's own ABSPATH/BE_* defines: the real
 * WordPress bootstrap (via wp-tests-config.php) defines ABSPATH itself, and PHP's
 * define() silently no-ops on an already-defined constant - defining a placeholder
 * ABSPATH here first would win by going first, leaving every thread test running
 * against the plugin directory instead of the real WordPress install.
 */
$be_wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( $be_wp_tests_dir && file_exists( $be_wp_tests_dir . '/includes/functions.php' ) ) {

	define( 'BE_WP_TESTS_AVAILABLE', true );

	require_once $be_wp_tests_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function () {
			require BE_PLUGIN_PATH . '/beyond-elysium.php';

			// The WP test suite loads the plugin by requiring it directly (above), which
			// never fires register_activation_hook()'s callback the way a real activation
			// would. Plugin::init() (hooked from plugins_loaded, which DOES fire here)
			// covers table creation and seeding via Schema::maybe_upgrade() - deferred to
			// the `init` action (Decision 048), which this same bootstrap also fires
			// before tests run - but Capabilities::register() only ever runs from
			// Activator::activate() - so every custom be_* capability check would
			// otherwise fail for every role in every thread test, indistinguishable from a
			// real permission bug.
			\BeyondElysium\Core\Capabilities::register();
		}
	);

	require $be_wp_tests_dir . '/includes/bootstrap.php';

} else {
	define( 'BE_WP_TESTS_AVAILABLE', false );

	// Plugin classes all guard on ABSPATH. Define it for pure unit tests so they can be
	// loaded without WordPress present at all.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', BE_PLUGIN_PATH . '/' );
	}
	if ( ! defined( 'BE_VERSION' ) ) {
		define( 'BE_VERSION', '0.2.1' );
	}
	if ( ! defined( 'BE_PLUGIN_FILE' ) ) {
		define( 'BE_PLUGIN_FILE', BE_PLUGIN_PATH . '/beyond-elysium.php' );
	}
	if ( ! defined( 'BE_PLUGIN_DIR' ) ) {
		define( 'BE_PLUGIN_DIR', BE_PLUGIN_PATH . '/' );
	}
	if ( ! defined( 'BE_PLUGIN_URL' ) ) {
		define( 'BE_PLUGIN_URL', 'http://localhost/wp-content/plugins/beyond-elysium/' );
	}
}
