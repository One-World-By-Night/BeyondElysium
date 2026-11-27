<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/fixtures/fake-owc-oat-connection.php';

use BeyondElysium\Services\Oat_Connection;
use PHPUnit\Framework\TestCase;

/**
 * A chronicle's site reads the Toolkit connection from the network's main site, and writes nothing there.
 *
 * @group multisite
 */
class OatConnectionMultisiteThreadTest extends TestCase {

	/** @var int[] */
	private array $blogs = [];

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'BE_WP_TESTS_AVAILABLE' ) || ! BE_WP_TESTS_AVAILABLE ) {
			self::markTestSkipped( 'WP_TESTS_DIR is not set.' );
		}
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Needs a multisite network (WP_MULTISITE=1).' );
		}
	}

	protected function tearDown(): void {
		global $be_test_oat;
		$be_test_oat = [];
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		foreach ( $this->blogs as $id ) {
			wpmu_delete_blog( $id, true );
		}
		$this->blogs = [];
	}

	public function test_a_subsite_reads_the_main_sites_connection_and_not_its_own(): void {
		global $be_test_oat;
		$main = (int) get_main_site_id();
		$sub  = (int) wpmu_create_blog( 'oat-' . strtolower( wp_generate_password( 6, false ) ) . '.example.test', '/', 'Subsite', 1 );
		$this->assertGreaterThan( 0, $sub );
		$this->blogs[] = $sub;

		$be_test_oat = [
			$main => [ 'mode' => 'remote', 'base' => 'https://archivist.example.test/wp-json/owbn/v1/', 'key' => 'MAIN-KEY' ],
			$sub  => [ 'mode' => 'remote', 'base' => 'https://elsewhere.example.test/wp-json/owbn/v1/', 'key' => 'SUB-KEY' ],
		];

		switch_to_blog( $sub );
		$endpoint = Oat_Connection::endpoint();
		$current  = get_current_blog_id();
		restore_current_blog();

		$this->assertSame( [ 'base' => 'https://archivist.example.test/wp-json/owbn/v1/', 'key' => 'MAIN-KEY' ], $endpoint );
		$this->assertSame( $sub, $current, 'The call returns to the subsite it was made from.' );
	}

	public function test_a_subsite_is_not_connected_when_the_main_site_is_in_local_mode(): void {
		global $be_test_oat;
		$main = (int) get_main_site_id();
		$sub  = (int) wpmu_create_blog( 'oat-' . strtolower( wp_generate_password( 6, false ) ) . '.example.test', '/', 'Subsite', 1 );
		$this->blogs[] = $sub;

		$be_test_oat = [
			$main => [ 'mode' => 'local', 'base' => '', 'key' => '' ],
			$sub  => [ 'mode' => 'remote', 'base' => 'https://elsewhere.example.test/wp-json/owbn/v1/', 'key' => 'SUB-KEY' ],
		];

		switch_to_blog( $sub );
		$endpoint = Oat_Connection::endpoint();
		restore_current_blog();

		$this->assertNull( $endpoint );
	}

	public function test_the_main_site_reads_its_own_settings(): void {
		global $be_test_oat;
		$main        = (int) get_main_site_id();
		$be_test_oat = [ $main => [ 'mode' => 'remote', 'base' => 'https://archivist.example.test/wp-json/owbn/v1/', 'key' => 'MAIN-KEY' ] ];

		$this->assertSame( 'MAIN-KEY', Oat_Connection::endpoint()['key'] ?? null );
		$this->assertSame( $main, get_current_blog_id() );
	}
}
