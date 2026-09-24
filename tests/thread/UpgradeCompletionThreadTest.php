<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Health_Notice;
use BeyondElysium\Database\Schema;
use WP_UnitTestCase;

/**
 * The upgrade records its version only when it completes: a failed step leaves the version unrecorded and says why, a
 * failure is retried once its lock goes stale rather than on every request, an upgrade already running elsewhere is not
 * run alongside it, and a finished upgrade records the version and lets the next one run.
 */
class UpgradeCompletionThreadTest extends WP_UnitTestCase {

	private int $runs = 0;

	public function setUp(): void {
		parent::setUp();
		update_option( Schema::VERSION_OPTION, '0.99.0' );
		delete_option( 'be_upgrade_error' );
		$this->set_lock( null );
		add_action( 'be_after_upgrade', [ $this, 'count_run' ] );
	}

	public function tearDown(): void {
		remove_action( 'be_after_upgrade', [ $this, 'count_run' ] );
		remove_action( 'be_after_upgrade', [ $this, 'fail_a_step' ] );
		parent::tearDown();
	}

	public function count_run(): void {
		$this->runs++;
	}

	public function fail_a_step(): void {
		throw new \RuntimeException( 'Page provisioning failed' );
	}

	/**
	 * Writes the lock row directly, the way another request holding it would have.
	 */
	private function set_lock( ?int $since ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, [ 'option_name' => 'be_upgrade_lock' ] );
		if ( $since !== null ) {
			$wpdb->insert( $wpdb->options, [ 'option_name' => 'be_upgrade_lock', 'option_value' => (string) $since, 'autoload' => 'off' ] );
		}
		wp_cache_delete( 'be_upgrade_lock', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private function installed_version(): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Schema::VERSION_OPTION ) );
	}

	public function test_a_step_that_fails_leaves_the_version_unrecorded_and_says_why(): void {
		add_action( 'be_after_upgrade', [ $this, 'fail_a_step' ] );

		Schema::maybe_upgrade();

		$this->assertSame( '0.99.0', $this->installed_version() );
		$this->assertStringContainsString( 'Page provisioning failed', (string) ( get_option( 'be_upgrade_error' )['message'] ?? '' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		Health_Notice::render();
		$this->assertStringContainsString( 'Page provisioning failed', (string) ob_get_clean() );
	}

	public function test_a_failure_is_retried_once_its_lock_goes_stale_not_on_every_request(): void {
		add_action( 'be_after_upgrade', [ $this, 'fail_a_step' ] );
		Schema::maybe_upgrade();
		remove_action( 'be_after_upgrade', [ $this, 'fail_a_step' ] );
		$this->runs = 0;

		Schema::maybe_upgrade();
		$this->assertSame( 0, $this->runs, 'the next request does not run it again straight away' );

		$this->set_lock( time() - HOUR_IN_SECONDS );
		Schema::maybe_upgrade();

		$this->assertSame( 1, $this->runs );
		$this->assertSame( Schema::DB_VERSION, $this->installed_version() );
		$this->assertFalse( get_option( 'be_upgrade_error' ) );
	}

	public function test_an_upgrade_already_running_elsewhere_is_not_run_again_alongside_it(): void {
		$this->set_lock( time() );

		Schema::maybe_upgrade();

		$this->assertSame( 0, $this->runs );
		$this->assertSame( '0.99.0', $this->installed_version() );
	}

	public function test_a_finished_upgrade_records_the_version_and_lets_the_next_one_run(): void {
		Schema::maybe_upgrade();

		$this->assertSame( 1, $this->runs );
		$this->assertSame( Schema::DB_VERSION, $this->installed_version() );
		global $wpdb;
		$this->assertNull( $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'be_upgrade_lock'" ) );
	}
}
