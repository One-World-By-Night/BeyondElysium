<?php
/**
 * Constants the plugin's guard clauses rely on, defined so static analysis does not report
 * them as undefined. Not loaded at runtime.
 *
 * Guarded: phpstan-wordpress defines some WordPress constants itself, and PHPStan loads
 * bootstrap files in each parallel worker.
 */

defined( 'ABSPATH' )        || define( 'ABSPATH', dirname( __DIR__ ) . '/beyond-elysium/' );
defined( 'BE_VERSION' )     || define( 'BE_VERSION', '0.2.1' );
defined( 'BE_PLUGIN_FILE' ) || define( 'BE_PLUGIN_FILE', dirname( __DIR__ ) . '/beyond-elysium/beyond-elysium.php' );
defined( 'BE_PLUGIN_DIR' )  || define( 'BE_PLUGIN_DIR', dirname( __DIR__ ) . '/beyond-elysium/' );
defined( 'BE_PLUGIN_URL' )  || define( 'BE_PLUGIN_URL', 'http://localhost/wp-content/plugins/beyond-elysium/' );
