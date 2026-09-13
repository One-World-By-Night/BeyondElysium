<?php
/**
 * Plugin Name: Beyond Elysium
 * Description: Character management system for Mind's Eye Theatre LARP chronicles.
 * Version: 0.99.10
 * Author: OWBN
 * License: GPL-2.0-or-later
 * Text Domain: beyond-elysium
 * Domain Path: /languages
 * Requires PHP: 8.2
 * Requires at least: 6.0
 * Requires Plugins: elementor
 */

defined( 'ABSPATH' ) || exit;

// Constants.
define( 'BE_VERSION', '0.99.10' );
define( 'BE_PLUGIN_FILE', __FILE__ );
define( 'BE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Composer autoloader.
require_once BE_PLUGIN_DIR . 'vendor/autoload.php';

// Register with centralized ASC module in owbn-client.
add_action( 'init', function () {
    if ( function_exists( 'owc_asc_register_client' ) ) {
        owc_asc_register_client( 'beyond_elysium', 'Beyond Elysium' );
    }
} );

// Activation / deactivation.
register_activation_hook( __FILE__, [ BeyondElysium\Core\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ BeyondElysium\Core\Deactivator::class, 'deactivate' ] );

// Initialize plugin on plugins_loaded.
add_action( 'plugins_loaded', [ BeyondElysium\Core\Plugin::class, 'init' ] );

// Register REST routes.
add_action( 'rest_api_init', [ BeyondElysium\Core\Plugin::class, 'register_rest_routes' ] );
