<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Transaction;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Decision 073, and deliberately NOT a `WP_UnitTestCase` - that is the
 * entire point of this file.
 *
 * `WP_UnitTestCase::set_up()` forces `autocommit = 0` for the life of every test wrapped by
 * it, as its own mechanism for isolating each test inside a transaction it rolls back
 * afterward. That means `@@autocommit === 0` is true for the ENTIRE body of every other
 * thread/workflow test in this suite - which is exactly the condition the old, buggy
 * cascading-delete code (`Character::delete()`, `Plot::delete()`, `World_Object::delete()`,
 * `Game::delete_with_content()`, before Decision 073) used to decide "am I already nested
 * inside a transaction, so I should use SAVEPOINT instead of START TRANSACTION." Under that
 * wrapping, the old code and the new `Transaction` class are indistinguishable: both always
 * see `@@autocommit === 0` and both always choose SAVEPOINT. The real bug - `@@autocommit`
 * staying `1` in a genuinely nested call under real production, where autocommit mode
 * itself never changes just because a transaction is open - cannot be exercised by any test
 * that extends `WP_UnitTestCase`, no matter how the fixture is built, because the branching
 * variable the old code read never reaches `1` inside that harness.
 *
 * So this test runs against the SAME already-bootstrapped WordPress + MySQL connection
 * (`tests/bootstrap.php` loads the WP test suite once for the whole PHPUnit process,
 * independent of which base class an individual test extends) but WITHOUT
 * `WP_UnitTestCase`'s per-test transaction wrapping - real `@@autocommit`, real commits, and
 * manual cleanup in `tearDown()` since there is no ambient rollback safety net here.
 *
 * Proves the point two ways in one test: a local closure reproducing the OLD bare-
 * `@@autocommit` check demonstrably loses an outer row it should have been able to roll
 * back, then the real `Transaction` class is shown NOT to have that problem under the
 * identical sequence - matching this project's own testing rule that a guard must be shown
 * to actually fail before it's trusted (`BE_PROCESS/TESTING.md`, "a test that has never
 * failed has proven nothing").
 */
class TransactionRealAutocommitTest extends TestCase {

	/** @var string[] */
	private static array $cleanup_slugs = [];

	private string $prior_autocommit = '1';

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'BE_WP_TESTS_AVAILABLE' ) || ! BE_WP_TESTS_AVAILABLE ) {
			self::markTestSkipped( 'WP_TESTS_DIR not configured - see BE_PROCESS/PLATFORM.md.' );
		}
	}

	/**
	 * Force real autocommit=1 regardless of what ran before this file in the same suite.
	 * `WP_UnitTestCase::set_up()` (`abstract-testcase.php`) calls `SET autocommit = 0;`
	 * unconditionally on every single test it wraps, with nothing ever setting it back to
	 * `1` afterward - so once any earlier thread test in the same PHPUnit process has run,
	 * the shared connection stays at `autocommit = 0` for the rest of the run, this file
	 * included, regardless of this class not extending WP_UnitTestCase itself. Restored in
	 * tearDown() - harmless either way, since the next WP_UnitTestCase test's own set_up()
	 * forces it back to 0 again regardless, but leaving it flipped for whatever runs after
	 * this file is not this file's call to make.
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

		// Not $wpdb->prepare()'d - MySQL's SET autocommit = ... takes a bare 0/1, not a
		// quoted string; $this->prior_autocommit only ever holds one of those two literals,
		// read back from @@autocommit itself in setUp(), never external input.
		$value = $this->prior_autocommit === '0' ? '0' : '1';
		$wpdb->query( "SET autocommit = {$value};" );
	}

	/**
	 * Old code's exact decision rule, reproduced locally rather than imported - the class
	 * it lived in no longer contains it after Decision 073's fix, and re-adding it anywhere
	 * real would reintroduce the bug this file exists to keep fixed.
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

		// A nested call - e.g. Character::delete() from inside Game::delete_with_content()'s
		// loop - re-checks @@autocommit, sees the identical `1`, and opens its OWN
		// START TRANSACTION, which MySQL's documented behavior silently commits the outer
		// one to make room for.
		$this->old_buggy_begin();
		$wpdb->query( 'COMMIT' );

		// The outer caller believes it can still roll back its own row - it cannot, because
		// the outer transaction was already destroyed by the nested call above.
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

		// Identical nested-call shape to the test above - Transaction::begin() must
		// recognize this as nested via its own depth counter, not by re-reading
		// @@autocommit (which is still `1`, unchanged, exactly as above).
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

	/**
	 * The realistic shape: a failure partway through a `Game::delete_with_content()`-style
	 * loop must still be able to undo work already done earlier in that same loop. This is
	 * the concrete guarantee the old code only appeared to provide.
	 */
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

		// Something after the loop fails (e.g. the final games-row delete in
		// Game::delete_with_content()) - the outer caller rolls back, expecting every
		// item processed above to be undone too.
		Transaction::rollback( $outer );

		foreach ( $slugs as $slug ) {
			$survived = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}be_games WHERE slug = %s",
				$slug
			) );
			$this->assertSame( 0, $survived, "slug $slug must not survive the outer rollback" );
		}
	}
}
