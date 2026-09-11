<?php

namespace BeyondElysium\Core;

use BeyondElysium\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Shows an admin notice when one or more of this plugin's database
 * tables are missing. A missing table otherwise fails silently -
 * affected REST endpoints simply return empty results - so this is the
 * one place that surfaces the condition to site administrators.
 */
class Health_Notice {

	const TRANSIENT = 'be_missing_tables_check';

	/**
	 * Hooks render() onto admin_notices, so the missing-tables check is
	 * evaluated on every wp-admin page load and any resulting notice is
	 * displayed there.
	 */
	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	/**
	 * Renders an admin-notice error box listing any missing database
	 * tables, visible only to users who can activate plugins. Checks a
	 * short-lived transient cache before querying the database directly,
	 * and outputs nothing when no tables are missing.
	 */
	public static function render(): void {
		// Visible only to users who can activate plugins.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Caches the SHOW TABLES check in a 5-minute transient rather than querying every page load.
		$missing = get_transient( self::TRANSIENT );
		if ( $missing === false ) {
			$missing = Schema::missing_tables();
			set_transient( self::TRANSIENT, $missing, 5 * MINUTE_IN_SECONDS );
		}

		if ( empty( $missing ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p>%3$s <code>%4$s</code></p></div>',
			esc_html__( 'Beyond Elysium:', 'beyond-elysium' ),
			esc_html__( 'one or more database tables are missing. Data for the affected features will appear empty rather than erroring, which can look like normal use until you know to check here.', 'beyond-elysium' ),
			esc_html__( 'Deactivating and reactivating the plugin re-runs table creation and is safe to do at any time. Missing:', 'beyond-elysium' ),
			esc_html( implode( ', ', $missing ) )
		);
	}

	/**
	 * Deletes the cached missing-tables transient, so the next admin page
	 * load re-runs the real database check instead of reusing a stale
	 * result.
	 */
	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT );
	}
}
