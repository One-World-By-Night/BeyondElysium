<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Transaction;
use PHPUnit\Framework\TestCase;

/**
 * `Transaction` on a real autocommit connection.
 */
class TransactionRealAutocommitTest extends TestCase {

	/** @var string[] */
	private static array $cleanup_slugs = [];

	private string $prior_autocommit = '1';

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'BE_WP_TESTS_AVAILABLE' ) || ! BE_WP_TESTS_AVAILABLE ) {
			self::markTestSkipped( 'WP_TESTS_DIR not configured - see BE_PROCESS/now/PLATFORM.md.' );
		}
	}

	/**
	 * Force real autocommit=1 regardless of what ran before this file in the same suite.
	 */
	protected function setUp(): void {
		global $wpdb;
		$this->prior_autocommit = (string) $wpdb->get_var( 'SELECT @@autocommit' );
		$wpdb->query( 'SET autocommit = 1;' );
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( self::$cleanup_slugs as $slug ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}be_games WHERE slug = %s", $slug ) );
		}
		self::$cleanup_slugs = [];

		$value = $this->prior_autocommit === '0' ? '0' : '1';
		$wpdb->query( "SET autocommit = {$value};" );
	}

	/**
	 * Begins with a savepoint when autocommit is off and a transaction otherwise.
	 */
	private function old_buggy_begin(): void {
		global $wpdb;
		$nested = (int) $wpdb->get_var( 'SELECT @@autocommit' ) === 0;
		$wpdb->query( $nested ? 'SAVEPOINT old_pattern' : 'START TRANSACTION' );
	}

	public function test_environment_sanity_autocommit_is_really_on_here(): void {
		global $wpdb;
		$this->assertSame(
			'1',
			(string) $wpdb->get_var( 'SELECT @@autocommit' ),
			'this whole file only proves anything under real autocommit=1 - if this ever ' .
			'fails, something wrapped this connection in an ambient transaction again and ' .
			'every other test below has silently stopped testing what it claims to'
		);
	}

	public function test_the_old_bare_autocommit_check_loses_the_outer_row(): void {
		global $wpdb;
		$slug = 'txn-old-bug-' . wp_generate_password( 8, false );
		self::$cleanup_slugs[] = $slug;

		// Outer unit of work begins exactly like the pre-073 code did.
		$this->old_buggy_begin();
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $slug,
			'name'       => 'Old Pattern Outer Row',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		] );

		// A nested call - e.g. Character::delete() from inside Game::delete_with_content()'s loop.
		$this->old_buggy_begin();
		$wpdb->query( 'COMMIT' );

		// The outer caller believes it can still roll back its own row.
		$wpdb->query( 'ROLLBACK' );

		$survived = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}be_games WHERE slug = %s",
			$slug
		) );
		$this->assertSame(
			1,
			$survived,
			'demonstrates the actual bug: the old pattern cannot undo the outer row via ' .
			'ROLLBACK once a nested call has opened its own START TRANSACTION - if this ' .
			'assertion ever fails, MySQL stopped implicitly committing on nested START ' .
			'TRANSACTION and D23/Decision 029/Decision 073\'s whole premise needs re-checking'
		);
	}

	public function test_transaction_class_does_not_lose_the_outer_row(): void {
		global $wpdb;
		$slug = 'txn-fixed-' . wp_generate_password( 8, false );
		self::$cleanup_slugs[] = $slug;

		$outer = Transaction::begin( 'txn_test_outer' );
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $slug,
			'name'       => 'Fixed Pattern Outer Row',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		] );

		// Identical nested-call shape to the test above.
		$inner = Transaction::begin( 'txn_test_inner' );
		Transaction::commit( $inner );

		Transaction::rollback( $outer );

		$survived = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}be_games WHERE slug = %s",
			$slug
		) );
		$this->assertSame(
			0,
			$survived,
			'Transaction::rollback() on the outer call must undo the row even though a ' .
			'nested begin()/commit() ran in between - this is the actual fix'
		);
	}

	public function test_a_failure_after_several_nested_units_rolls_back_all_of_them(): void {
		global $wpdb;
		$slugs = [
			'txn-loop-' . wp_generate_password( 8, false ),
			'txn-loop-' . wp_generate_password( 8, false ),
			'txn-loop-' . wp_generate_password( 8, false ),
		];
		foreach ( $slugs as $slug ) {
			self::$cleanup_slugs[] = $slug;
		}

		$outer = Transaction::begin( 'txn_test_loop_outer' );

		foreach ( $slugs as $i => $slug ) {
			$unit = Transaction::begin( 'txn_test_loop_item' );
			$wpdb->insert( $wpdb->prefix . 'be_games', [
				'slug'       => $slug,
				'name'       => "Loop Item $i",
				'created_by' => 1,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			] );
			Transaction::commit( $unit );
		}

		// Something after the loop fails (e.g. the final games-row delete in Game::delete_with_content()).
		Transaction::rollback( $outer );

		foreach ( $slugs as $slug ) {
			$survived = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}be_games WHERE slug = %s",
				$slug
			) );
			$this->assertSame( 0, $survived, "slug $slug must not survive the outer rollback" );
		}
	}

	/**
	 * An exception thrown inside a nested unit of work skips that unit's own commit or rollback.
	 */
	public function test_an_inner_unit_left_open_by_an_exception_does_not_break_the_outer_rollback(): void {
		global $wpdb;
		$slug      = 'txn-leak-' . wp_generate_password( 8, false );
		$next_slug = 'txn-leak-next-' . wp_generate_password( 8, false );
		self::$cleanup_slugs[] = $slug;
		self::$cleanup_slugs[] = $next_slug;

		$insert = static function ( string $row_slug ) use ( $wpdb ): void {
			$wpdb->insert( $wpdb->prefix . 'be_games', [
				'slug'       => $row_slug,
				'name'       => 'Leaked Inner Unit',
				'created_by' => 1,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			] );
		};
		$count = static fn( string $row_slug ): int => (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}be_games WHERE slug = %s",
			$row_slug
		) );

		$outer = Transaction::begin( 'txn_test_leak_outer' );
		$insert( $slug );

		try {
			Transaction::begin( 'txn_test_leak_inner' );
			throw new \RuntimeException( 'failed between begin and commit' );
		} catch ( \RuntimeException $e ) {
			Transaction::rollback( $outer );
		}

		$this->assertSame( 0, $count( $slug ), 'the outer rollback must undo the row even though an inner unit never closed' );

		$next = Transaction::begin( 'txn_test_leak_next' );
		$insert( $next_slug );
		Transaction::rollback( $next );

		$this->assertSame( 0, $count( $next_slug ), 'the next unit of work must be a real transaction again' );
	}
}
