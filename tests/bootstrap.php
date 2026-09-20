<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit tests run with no WordPress at all. Thread and workflow tests need the WordPress
 * test suite; when WP_TESTS_DIR is not set they are skipped rather than failed, so a fresh
 * clone can still run `vendor/bin/phpunit --testsuite unit`.
 *
 * See BE_PROCESS/now/TESTING.md.
 */

define( 'BE_TESTS_DIR', __DIR__ );

// The code root - `code/`, everything that goes to the public repository and nothing else.
// tests/fixtures/ lives here, as does src/ and the plugin itself.
define( 'BE_PLUGIN_ROOT', dirname( __DIR__ ) );

// The private repository root, one level above the code root. Reference material a test
// reads directly but never ships - GV301Source/ and samples/ - lives here. In the public
// repository there is nothing above the code root, so these paths simply do not exist and
// the tests that read them skip, exactly as they already do when samples/ is absent.
define( 'BE_REPO_ROOT', dirname( BE_PLUGIN_ROOT ) );

/**
 * Resolves a reference path a test reads directly. GV301Source/ and samples/ are private
 * and sit above the code root; everything else is inside it.
 */
function be_reference_path( string $relative ): string {
	$private = strpos( $relative, 'GV301Source/' ) === 0 || strpos( $relative, 'samples/' ) === 0;
	return ( $private ? BE_REPO_ROOT : BE_PLUGIN_ROOT ) . '/' . $relative;
}

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

// Several checkouts can run the suite at once when each points WordPress's own bootstrap at a
// wp-tests-config.php with its own $table_prefix - it reads this constant, never the
// environment, so it is carried across here before that bootstrap loads.
if ( getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) && ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) );
}

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

	// Pure display code wraps its words for translation; without WordPress they read as written.
	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string { // phpcs:ignore
			return $text;
		}
	}

	// Sheet_Document::use_portuguese() reads the site's own locale; with no WordPress and no
	// site configured, that's WordPress's own default, `en_US` - never `pt_BR`.
	if ( ! function_exists( 'get_locale' ) ) {
		function get_locale(): string { // phpcs:ignore
			return 'en_US';
		}
	}

	// St_Filter::strip_html_for_game() re-sanitizes a cut string through this to close a
	// dangling tag a byte-offset cut can leave open; every real caller passes plain text with
	// no markup, so a pass-through is exact here, not an approximation of WordPress's own
	// allowlist behavior.
	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( string $text ): string { // phpcs:ignore
			return $text;
		}
	}

	// strip_html_for_game()'s other half (1.0.1 D1) - closes a tag the byte-offset cut left
	// dangling. Same reasoning as wp_kses_post() just above: real callers pass plain text
	// with no markup, so a pass-through is exact, not an approximation.
	if ( ! function_exists( 'force_balance_tags' ) ) {
		function force_balance_tags( string $text ): string { // phpcs:ignore
			return $text;
		}
	}
}
