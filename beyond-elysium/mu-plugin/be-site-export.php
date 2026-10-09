<?php
/**
 * Plugin Name: Beyond Elysium Site Export
 * Description: Writes every Beyond Elysium table and attached file for a subsite to a protected zip just before Network Admin or WP-CLI deletes it.
 * Version: 1.0.0
 * Author: OWBN
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'BE_SITE_EXPORT_MU_VERSION', '1.0.0' );

if ( ! is_multisite() ) {
	return;
}

/**
 * Backs up a subsite's Beyond Elysium tables and private attachment files to a zip outside the web root, hooked to
 * the moment just before WordPress itself would delete that subsite.
 */
final class BE_Site_Export {

	const TABLE_MARKER   = 'be_';
	const OUTCOME_OPTION = 'be_site_export_last_outcome';
	const OUTCOME_TTL    = 300;

	/**
	 * Tables already backed up this request, by blog id, so `wpmu_drop_tables` can add them to the cleanup list.
	 *
	 * @var array<int,string[]>
	 */
	private static $exported_tables = [];

	public static function register() {
		add_action( 'wp_validate_site_deletion', [ __CLASS__, 'on_validate_site_deletion' ], 10, 2 );
		add_filter( 'wpmu_drop_tables', [ __CLASS__, 'on_wpmu_drop_tables' ], 10, 2 );
		add_action( 'network_admin_notices', [ __CLASS__, 'render_notice' ] );
		add_action( 'admin_notices', [ __CLASS__, 'render_notice' ] );
	}

	/**
	 * Exports a subsite's data the moment WordPress starts validating whether it may be deleted, before any table
	 * is dropped or any upload is removed. Adding an error here stops the deletion.
	 *
	 * @param WP_Error $errors
	 * @param WP_Site  $old_site
	 */
	public static function on_validate_site_deletion( $errors, $old_site ) {
		if ( $errors->has_errors() ) {
			return;
		}

		global $wpdb;
		$blog_id = (int) $old_site->blog_id;
		$prefix  = $wpdb->get_blog_prefix( $blog_id );
		$tables  = self::tables_for_prefix( $prefix );

		if ( empty( $tables ) ) {
			return;
		}

		$result = self::export( $blog_id, $old_site, $prefix, $tables );

		if ( is_wp_error( $result ) ) {
			$errors->add( 'be_site_export_failed', $result->get_error_message() );
			self::record_outcome( $old_site, false, $result->get_error_message() );
			if ( class_exists( 'WP_CLI' ) ) {
				WP_CLI::warning( 'Beyond Elysium: ' . $result->get_error_message() . ' The site was not deleted.' );
			}
			return;
		}

		self::$exported_tables[ $blog_id ] = $tables;
		self::record_outcome( $old_site, true, $result );
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::log( 'Beyond Elysium: backed up ' . $result . ' before deleting this site.' );
		}
	}

	/**
	 * Adds a successfully backed-up blog's own tables to WordPress's own cleanup list, so nothing is left orphaned.
	 *
	 * @param string[] $tables
	 * @param int      $blog_id
	 * @return string[]
	 */
	public static function on_wpmu_drop_tables( $tables, $blog_id ) {
		if ( isset( self::$exported_tables[ $blog_id ] ) ) {
			$tables = array_values( array_unique( array_merge( $tables, self::$exported_tables[ $blog_id ] ) ) );
		}
		return $tables;
	}

	/**
	 * Builds the zip for one blog: a JSON manifest, every row of every matched table, and every file under its
	 * private attachment folder. Returns the zip's own filename, or a WP_Error naming why nothing was written.
	 *
	 * @param int      $blog_id
	 * @param WP_Site  $old_site
	 * @param string   $prefix
	 * @param string[] $tables
	 * @return string|WP_Error
	 */
	public static function export( $blog_id, $old_site, $prefix, array $tables ) {
		$dir = self::export_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$prepared = self::prepare_dir( $dir );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'be_site_export_no_zip', 'PHP\'s zip extension is not available, so no backup could be written.' );
		}

		$private = self::private_attachment_dir( $blog_id );

		$manifest = [
			'exported_at'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'site_id'           => $blog_id,
			'site_url'          => $old_site->domain . $old_site->path,
			'mu_plugin_version' => BE_SITE_EXPORT_MU_VERSION,
			'tables'            => [],
		];
		$tables_json = [
			'exported_at' => $manifest['exported_at'],
			'tables'      => [],
		];

		global $wpdb;
		foreach ( $tables as $table ) {
			$short                           = self::short_table_name( $table, $prefix );
			$rows                             = $wpdb->get_results( 'SELECT * FROM `' . $table . '`', ARRAY_A );
			$tables_json['tables'][ $short ] = $rows;
			$manifest['tables'][ $short ]    = count( (array) $rows );
		}

		$attachment_files = $private && is_dir( $private ) ? self::collect_files( $private ) : [];
		$manifest['attachment_count'] = count( $attachment_files );

		$filename = sprintf(
			'%d-%s-%s.zip',
			$blog_id,
			sanitize_title( $old_site->domain . $old_site->path ),
			gmdate( 'Ymd-His' )
		);
		$zip_path = rtrim( $dir, '/' ) . '/' . $filename;

		$zip = new ZipArchive();
		if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			return new WP_Error( 'be_site_export_zip_failed', 'The backup zip could not be created at ' . $zip_path . '.' );
		}

		$zip->addFromString( 'manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
		$zip->addFromString( 'tables.json', (string) wp_json_encode( $tables_json ) );
		foreach ( $attachment_files as $absolute => $relative ) {
			$zip->addFile( $absolute, 'attachments/' . $relative );
		}
		$zip->close();

		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'be_site_export_zip_failed', 'The backup zip could not be created at ' . $zip_path . '.' );
		}
		chmod( $zip_path, 0600 );

		return $filename;
	}

	/**
	 * The folder a backup is written to: `BE_SITE_EXPORT_DIR` when defined, else a folder beside the WordPress
	 * install. Refused when it would sit inside the web root, since a deny-all `.htaccess` can be bypassed by a
	 * server that does not honor it.
	 *
	 * @return string|WP_Error
	 */
	private static function export_dir() {
		$override = getenv( 'BE_SITE_EXPORT_DIR_OVERRIDE' );
		if ( $override ) {
			$dir = rtrim( $override, '/' );
		} elseif ( defined( 'BE_SITE_EXPORT_DIR' ) && BE_SITE_EXPORT_DIR ) {
			$dir = rtrim( (string) BE_SITE_EXPORT_DIR, '/' );
		} else {
			$dir = rtrim( dirname( ABSPATH ), '/' ) . '/be-site-exports';
		}

		$dir_normalized     = trailingslashit( wp_normalize_path( $dir ) );
		$abspath_normalized = trailingslashit( wp_normalize_path( ABSPATH ) );
		if ( strpos( $dir_normalized, $abspath_normalized ) === 0 ) {
			return new WP_Error( 'be_site_export_dir_inside_webroot', 'The export folder would sit inside the web root, so no backup could be written.' );
		}

		return $dir;
	}

	/**
	 * Creates the export folder if needed, locked to its owner, with the same deny-all `.htaccess` and blank
	 * `index.php` pattern this plugin's own private attachment folder uses.
	 *
	 * @param string $dir
	 * @return true|WP_Error
	 */
	private static function prepare_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'be_site_export_dir_failed', 'The export folder could not be created at ' . $dir . '.' );
			}
			chmod( $dir, 0700 );
		}
		if ( ! is_writable( $dir ) ) {
			return new WP_Error( 'be_site_export_dir_unwritable', 'The export folder is not writable: ' . $dir . '.' );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return true;
	}

	/**
	 * Every table name on this blog matching this plugin's own naming, via a live `SHOW TABLES` rather than a
	 * hardcoded list, so an inactive or outdated main plugin still gets a complete backup.
	 *
	 * @param string $prefix
	 * @return string[]
	 */
	private static function tables_for_prefix( $prefix ) {
		global $wpdb;
		$like = $wpdb->esc_like( $prefix . self::TABLE_MARKER ) . '%';
		return $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	}

	/**
	 * A table's name with its blog prefix and this plugin's own marker removed.
	 *
	 * @param string $table
	 * @param string $prefix
	 * @return string
	 */
	private static function short_table_name( $table, $prefix ) {
		$marker = $prefix . self::TABLE_MARKER;
		return strpos( $table, $marker ) === 0 ? substr( $table, strlen( $marker ) ) : $table;
	}

	/**
	 * The private attachment folder of one blog, without creating it.
	 *
	 * @param int $blog_id
	 * @return string|null
	 */
	private static function private_attachment_dir( $blog_id ) {
		$switched = get_current_blog_id() !== $blog_id;
		if ( $switched ) {
			switch_to_blog( $blog_id );
		}
		$uploads = wp_get_upload_dir();
		if ( $switched ) {
			restore_current_blog();
		}
		return isset( $uploads['basedir'] ) ? rtrim( $uploads['basedir'], '/' ) . '/beyond-elysium-private' : null;
	}

	/**
	 * Every file under a folder, each mapped to its path relative to that folder.
	 *
	 * @param string $root
	 * @return array<string,string>
	 */
	private static function collect_files( $root ) {
		$files    = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && ! in_array( $file->getFilename(), [ '.htaccess', 'index.php' ], true ) ) {
				$absolute           = $file->getPathname();
				$relative            = ltrim( substr( $absolute, strlen( $root ) ), '/' );
				$files[ $absolute ] = $relative;
			}
		}
		return $files;
	}

	/**
	 * Remembers this deletion's export outcome for the next admin page load, since the core screens that trigger a
	 * deletion report "deleted" on their own regardless of what this hook did.
	 *
	 * @param WP_Site $old_site
	 * @param bool    $success
	 * @param string  $detail
	 */
	private static function record_outcome( $old_site, $success, $detail ) {
		update_site_option( self::OUTCOME_OPTION, [
			'site'    => $old_site->domain . $old_site->path,
			'success' => $success,
			'detail'  => $detail,
			'at'      => time(),
		] );
	}

	/**
	 * Shows the most recent export's real outcome once, to a network administrator, correcting a core screen's own
	 * unconditional "deleted" message when the export - and so the deletion - actually failed.
	 */
	public static function render_notice() {
		if ( ! is_multisite() || ! current_user_can( 'manage_network' ) ) {
			return;
		}
		$outcome = get_site_option( self::OUTCOME_OPTION );
		if ( ! is_array( $outcome ) || ( time() - (int) ( $outcome['at'] ?? 0 ) ) > self::OUTCOME_TTL ) {
			return;
		}
		delete_site_option( self::OUTCOME_OPTION );

		if ( ! empty( $outcome['success'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>Beyond Elysium Site Export:</strong> %s</p></div>',
				esc_html( 'backed up ' . $outcome['site'] . ' before it was deleted: ' . $outcome['detail'] )
			);
			return;
		}

		printf(
			'<div class="notice notice-error is-dismissible"><p><strong>Beyond Elysium Site Export:</strong> %s</p></div>',
			esc_html( 'could not back up ' . $outcome['site'] . ', so it was NOT deleted: ' . $outcome['detail'] )
		);
	}
}

BE_Site_Export::register();
