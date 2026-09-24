<?php
/**
 * PHPUnit bootstrap.
 */

define( 'BE_TESTS_DIR', __DIR__ );

define( 'BE_PLUGIN_ROOT', dirname( __DIR__ ) );

// The private repository root, one level above the code root.
define( 'BE_REPO_ROOT', dirname( BE_PLUGIN_ROOT ) );

/**
 * Resolves a reference path a test reads directly.
 */
function be_reference_path( string $relative ): string {
	$private = strpos( $relative, 'GV301Source/' ) === 0 || strpos( $relative, 'samples/' ) === 0;
	return ( $private ? BE_REPO_ROOT : BE_PLUGIN_ROOT ) . '/' . $relative;
}

define( 'BE_PLUGIN_PATH', BE_PLUGIN_ROOT . '/beyond-elysium' );

require_once BE_PLUGIN_PATH . '/vendor/autoload.php';

/**
 * Load the WordPress test suite when it is available.
 */
$be_wp_tests_dir = getenv( 'WP_TESTS_DIR' );

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

			// The WP test suite loads the plugin by requiring it directly (above).
			\BeyondElysium\Core\Capabilities::register();
		}
	);

	require $be_wp_tests_dir . '/includes/bootstrap.php';

} else {
	define( 'BE_WP_TESTS_AVAILABLE', false );

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

	// English's own plural rule: one is singular, everything else - zero included - is plural.
	if ( ! function_exists( '_n' ) ) {
		function _n( string $single, string $plural, int $number, string $domain = 'default' ): string { // phpcs:ignore
			return $number === 1 ? $single : $plural;
		}
	}

	// Sheet_Document::use_portuguese() reads the site's own locale.
	if ( ! function_exists( 'get_locale' ) ) {
		function get_locale(): string { // phpcs:ignore
			return 'en_US';
		}
	}

	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( string $text ): string { // phpcs:ignore
			return $text;
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, int $options = 0, int $depth = 512 ) { // phpcs:ignore
			return json_encode( $data, $options, $depth );
		}
	}

	// strip_html_for_game()'s other half.
	if ( ! function_exists( 'force_balance_tags' ) ) {
		function force_balance_tags( string $text ): string { // phpcs:ignore
			return $text;
		}
	}

	// A process with no WordPress has no option table, so an option reads as unset.
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $name, $default = false ) { // phpcs:ignore
			return $default;
		}
	}
}
