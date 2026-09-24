<?php

namespace BeyondElysium\Core;

use BeyondElysium\Database\Schema;
use BeyondElysium\Services\Pdf_Signer;

defined( 'ABSPATH' ) || exit;

/**
 * Shows an admin notice when one or more of this plugin's database tables are missing.
 */
class Health_Notice {

	const TRANSIENT       = 'be_missing_tables_check';
	const DRIFT_TRANSIENT = 'be_chronicle_slug_drift_check';

	/**
	 * Hooks render() onto admin_notices.
	 */
	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	/**
	 * Renders an admin-notice error box listing any missing database tables, visible only to users who can activate
	 * plugins.
	 */
	public static function render(): void {
		// Visible only to users who can activate plugins.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$missing = get_transient( self::TRANSIENT );
		if ( $missing === false ) {
			$missing = Schema::missing_tables();
			set_transient( self::TRANSIENT, $missing, 5 * MINUTE_IN_SECONDS );
		}

		if ( ! empty( $missing ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p>%3$s <code>%4$s</code></p></div>',
				esc_html__( 'Beyond Elysium:', 'beyond-elysium' ),
				esc_html__( 'one or more database tables are missing. Data for the affected features will appear empty rather than erroring, which can look like normal use until you know to check here.', 'beyond-elysium' ),
				esc_html__( 'Deactivating and reactivating the plugin re-runs table creation and is safe to do at any time. Missing:', 'beyond-elysium' ),
				esc_html( implode( ', ', $missing ) )
			);
		}

		self::render_slug_drift();
		self::render_signing_notice();
		self::render_upgrade_error();
	}

	/**
	 * Says when the last data upgrade did not finish: the version it was upgrading to and what stopped it.
	 */
	private static function render_upgrade_error(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$error = get_option( Schema::UPGRADE_ERROR_OPTION );
		if ( ! is_array( $error ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p><code>%3$s</code></p></div>',
			esc_html__( 'Beyond Elysium:', 'beyond-elysium' ),
			esc_html( sprintf(
				/* translators: 1: version being upgraded to, 2: minutes between attempts */
				__( 'the data upgrade to %1$s did not finish, and the plugin is running on partly upgraded data. It is tried again every %2$d minutes; this notice clears once it finishes. What stopped it:', 'beyond-elysium' ),
				(string) ( $error['version'] ?? '' ),
				(int) ( Schema::UPGRADE_LOCK_TTL / MINUTE_IN_SECONDS )
			) ),
			esc_html( (string) ( $error['message'] ?? '' ) )
		);
	}

	/**
	 * Warns when sheet signing is not configured on this install.
	 */
	private static function render_signing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( Pdf_Signer::availability()['ok'] ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p><p><code>%4$s</code></p></div>',
			esc_html__( 'Beyond Elysium:', 'beyond-elysium' ),
			esc_html__( 'signed character sheets are not configured on this install. Sheets and reports print stamped UNSIGNED until BE_PDF_SIGNING_CERT and BE_PDF_SIGNING_KEY are defined in wp-config.php, pointing at a certificate and key generated above the webroot.', 'beyond-elysium' ),
			esc_html__( 'Generate one with:', 'beyond-elysium' ),
			esc_html( 'BE_KEYPASS=\'your-passphrase\' openssl req -x509 -newkey rsa:4096 -days 3650 -cipher aes-256-cbc -passout env:BE_KEYPASS -subj "/CN=Beyond Elysium Signing" -keyout be-signing.key -out be-signing.crt' )
		);
	}

	/**
	 * Warns when a games row's owbn_chronicle_post_id points at a post whose chronicle_slug no longer matches the row's
	 * slug.
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
	 * Deletes the cached missing-tables transient.
	 */
	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Deletes the cached chronicle-slug-drift transient.
	 */
	public static function clear_drift_cache(): void {
		delete_transient( self::DRIFT_TRANSIENT );
	}
}
