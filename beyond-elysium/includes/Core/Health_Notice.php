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

	const TRANSIENT       = 'be_missing_tables_check';
	const DRIFT_TRANSIENT = 'be_chronicle_slug_drift_check';

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

		self::render_slug_drift();
	}

	/**
	 * Warns when a games row's owbn_chronicle_post_id points at a post
	 * whose own chronicle_slug no longer matches the row's slug. This is
	 * the one condition Chronicle_Sync's own deferred rename branch would
	 * need to notice and does not (BE_PROCESS/chronicle-rename-design.md
	 * §5.3/CR-6) - surfacing it here means that gap is safe to leave
	 * deferred, since an administrator finds out rather than the drift
	 * silently persisting. Visible only to users who can activate plugins,
	 * cached the same 5-minute-transient way as the missing-tables check.
	 */
	private static function render_slug_drift(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$drifted = get_transient( self::DRIFT_TRANSIENT );
		if ( $drifted === false ) {
			global $wpdb;
			$games_table = Schema::table( 'games' );
			$drifted     = $wpdb->get_results(
				"SELECT g.slug AS games_slug, pm.meta_value AS post_slug
				 FROM {$games_table} g
				 INNER JOIN {$wpdb->postmeta} pm
				   ON pm.post_id = g.owbn_chronicle_post_id AND pm.meta_key = 'chronicle_slug'
				 WHERE g.owbn_chronicle_post_id IS NOT NULL
				   AND pm.meta_value <> g.slug",
				ARRAY_A
			) ?: [];
			set_transient( self::DRIFT_TRANSIENT, $drifted, 5 * MINUTE_IN_SECONDS );
		}

		if ( empty( $drifted ) ) {
			return;
		}

		$pairs = array_map(
			static fn( $row ) => "{$row['games_slug']} \u{2192} {$row['post_slug']}",
			$drifted
		);

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p>%3$s <code>%4$s</code></p></div>',
			esc_html__( 'Beyond Elysium:', 'beyond-elysium' ),
			esc_html__( 'a chronicle\'s slug no longer matches its linked chronicle post. Renaming the chronicle here (Games) will cascade correctly; renaming it upstream will not, until that path is built.', 'beyond-elysium' ),
			esc_html__( 'Affected (current slug \u{2192} upstream slug):', 'beyond-elysium' ),
			esc_html( implode( ', ', $pairs ) )
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

	/**
	 * Deletes the cached chronicle-slug-drift transient, so the next admin
	 * page load re-runs the real check instead of reusing a stale result.
	 */
	public static function clear_drift_cache(): void {
		delete_transient( self::DRIFT_TRANSIENT );
	}
}
