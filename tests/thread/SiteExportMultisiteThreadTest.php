<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Attachment_Storage;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The site-export mu-plugin, loaded directly from its own file: a real subsite's Beyond Elysium tables and private
 * attachment folder are zipped before WordPress deletes the subsite, and a folder that refuses the write stops the
 * deletion from happening at all.
 *
 * @group multisite
 */
class SiteExportMultisiteThreadTest extends TestCase {

	private string $prior_autocommit = '1';
	private string $export_dir = '';

	/** @var array<string,mixed> */
	private array $made = [];

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'BE_WP_TESTS_AVAILABLE' ) || ! BE_WP_TESTS_AVAILABLE ) {
			self::markTestSkipped( 'WP_TESTS_DIR is not set.' );
		}
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Needs a multisite network (WP_MULTISITE=1).' );
		}
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		require_once BE_PLUGIN_PATH . '/mu-plugin/be-site-export.php';
	}

	protected function setUp(): void {
		global $wpdb;
		$this->prior_autocommit = (string) $wpdb->get_var( 'SELECT @@autocommit' );
		$wpdb->query( 'SET autocommit = 1;' );
		$this->export_dir = dirname( BE_TESTS_DIR ) . '/.tmp-site-export-' . wp_generate_password( 8, false );
		putenv( 'BE_SITE_EXPORT_DIR_OVERRIDE=' . $this->export_dir );
	}

	protected function tearDown(): void {
		global $wpdb;
		if ( ! empty( $this->made['blog'] ) && get_site( (int) $this->made['blog'] ) ) {
			wpmu_delete_blog( (int) $this->made['blog'], true );
		}
		putenv( 'BE_SITE_EXPORT_DIR_OVERRIDE' );
		if ( $this->export_dir && is_dir( $this->export_dir ) ) {
			self::remove_directory( $this->export_dir );
		}
		$value = $this->prior_autocommit === '0' ? '0' : '1';
		$wpdb->query( "SET autocommit = {$value};" );
	}

	private static function remove_directory( string $dir ): void {
		$items = scandir( $dir );
		foreach ( (array) $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? self::remove_directory( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	private function create_subsite(): int {
		$suffix = strtolower( wp_generate_password( 6, false ) );
		$admin  = get_current_user_id() ?: 1;
		$blog   = (int) wpmu_create_blog( (string) get_network()->domain, "/export-test-{$suffix}/", 'Export Test', $admin );
		$this->assertGreaterThan( 0, $blog );
		$this->made['blog'] = $blog;
		return $blog;
	}

	public function test_deleting_a_subsite_backs_up_its_tables_and_attachment_then_drops_them(): void {
		$blog = $this->create_subsite();

		switch_to_blog( $blog );
		Schema::create_tables();
		$game_id = (int) Game::create( [ 'name' => 'Export Test Chronicle', 'slug' => 'export-test-chronicle' ] );
		$private = Attachment_Storage::base_dir();
		wp_mkdir_p( $private . '/export-test-stored' );
		file_put_contents( $private . '/export-test-stored/portrait.txt', 'a real attached file' );
		global $wpdb;
		$prefix = $wpdb->prefix;
		restore_current_blog();

		wpmu_delete_blog( $blog, true );

		$this->assertNull( get_site( $blog ), 'the site is really gone' );
		$zips = glob( $this->export_dir . '/*.zip' );
		$this->assertNotEmpty( $zips, 'a zip was written before the subsite was dropped' );
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zips[0] ) === true );

		$manifest = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
		$this->assertSame( $blog, $manifest['site_id'] );
		$this->assertSame( 1, $manifest['tables']['games'] );
		$this->assertSame( 1, $manifest['attachment_count'] );

		$tables = json_decode( (string) $zip->getFromName( 'tables.json' ), true );
		$this->assertSame( 'Export Test Chronicle', $tables['tables']['games'][0]['name'] );

		$this->assertNotFalse( $zip->locateName( 'attachments/export-test-stored/portrait.txt' ) );
		$this->assertSame( 'a real attached file', $zip->getFromName( 'attachments/export-test-stored/portrait.txt' ) );
		$zip->close();

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . 'be_games' ) ) );
		$this->assertNull( $exists, 'the table is gone after the real deletion' );

		unset( $this->made['blog'] );
	}

	public function test_a_neighbouring_subsites_tables_are_untouched(): void {
		$blog = $this->create_subsite();

		switch_to_blog( $blog );
		Schema::create_tables();
		Game::create( [ 'name' => 'Dropped Chronicle', 'slug' => 'dropped-chronicle' ] );
		restore_current_blog();

		$main_game_id = (int) Game::create( [ 'name' => 'Main Site Chronicle', 'slug' => 'export-test-main-untouched' ] );

		wpmu_delete_blog( $blog, true );

		$this->assertNotNull( Game::find( $main_game_id ) );
		Game::delete_with_content( 'export-test-main-untouched' );

		unset( $this->made['blog'] );
	}

	public function test_a_site_with_no_be_tables_writes_nothing_and_deletes_normally(): void {
		$blog = $this->create_subsite();

		wpmu_delete_blog( $blog, true );

		$this->assertNull( get_site( $blog ), 'the site is really gone' );
		$zips = glob( $this->export_dir . '/*.zip' );
		$this->assertEmpty( $zips, 'nothing is written for a site with no be_ tables' );

		unset( $this->made['blog'] );
	}

	public function test_an_unwritable_export_folder_refuses_the_deletion_and_drops_nothing(): void {
		$blog = $this->create_subsite();

		switch_to_blog( $blog );
		Schema::create_tables();
		Game::create( [ 'name' => 'Refused Chronicle', 'slug' => 'export-test-refused' ] );
		global $wpdb;
		$prefix = $wpdb->prefix;
		restore_current_blog();

		wp_mkdir_p( $this->export_dir );
		chmod( $this->export_dir, 0500 );

		wpmu_delete_blog( $blog, true );

		chmod( $this->export_dir, 0700 );

		$this->assertNotNull( get_site( $blog ), 'the deletion is refused, the site row survives' );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . 'be_games' ) ) );
		$this->assertNotNull( $exists, 'nothing was dropped' );
	}

	public function test_an_export_folder_inside_abspath_is_refused(): void {
		$blog = $this->create_subsite();

		switch_to_blog( $blog );
		Schema::create_tables();
		Game::create( [ 'name' => 'Inside Abspath Chronicle', 'slug' => 'export-test-inside-abspath' ] );
		restore_current_blog();

		putenv( 'BE_SITE_EXPORT_DIR_OVERRIDE=' . rtrim( ABSPATH, '/' ) . '/be-site-exports-inside' );

		wpmu_delete_blog( $blog, true );

		putenv( 'BE_SITE_EXPORT_DIR_OVERRIDE=' . $this->export_dir );

		$this->assertNotNull( get_site( $blog ), 'the deletion is refused rather than writing inside the web root, and the site row survives' );
	}
}
