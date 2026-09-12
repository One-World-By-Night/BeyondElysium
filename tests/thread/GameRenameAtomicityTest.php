<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use PHPUnit\Framework\TestCase;

/**
 * Deliberately NOT a `WP_UnitTestCase`, for the exact reason
 * `TransactionRealAutocommitTest.php` gives: that harness forces
 * `autocommit = 0` for the life of every test it wraps, which is precisely the
 * condition that makes a broken nested-transaction pattern indistinguishable
 * from a correct one - both would take the SAVEPOINT branch regardless.
 * `Game::rename()`'s own atomicity claim (BE_PROCESS/chronicle-rename-design.md
 * §7.2: one `Transaction::begin()`, real rollback on failure) is only
 * provable under real `autocommit = 1`.
 *
 * Rather than construct an artificial SQL failure at a specific step (both
 * of `rename()`'s own pre-flight checks already prevent the two realistic
 * failure modes, so forcing a genuine write failure past them would be
 * testing an unrealistic scenario), this proves the more general and more
 * important guarantee: `rename()` nests correctly under a caller that
 * already has its own transaction open, the same shape
 * `Game::delete_with_content()` or a future batch operation would need.
 */
class GameRenameAtomicityTest extends TestCase {

	/** @var string[] */
	private static array $cleanup_slugs = [];

	private string $prior_autocommit = '1';

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'BE_WP_TESTS_AVAILABLE' ) || ! BE_WP_TESTS_AVAILABLE ) {
			self::markTestSkipped( 'WP_TESTS_DIR not configured - see BE_PROCESS/PLATFORM.md.' );
		}
	}

	protected function setUp(): void {
		global $wpdb;
		$this->prior_autocommit = (string) $wpdb->get_var( 'SELECT @@autocommit' );
		$wpdb->query( 'SET autocommit = 1;' );
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( self::$cleanup_slugs as $slug ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}be_characters WHERE owner_slug IN (%s, %s)", $slug, $slug . '-renamed' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}be_games WHERE slug IN (%s, %s)", $slug, $slug . '-renamed' ) );
		}
		self::$cleanup_slugs = [];

		$value = $this->prior_autocommit === '0' ? '0' : '1';
		$wpdb->query( "SET autocommit = {$value};" );
	}

	public function test_environment_sanity_autocommit_is_really_on_here(): void {
		global $wpdb;
		$this->assertSame(
			'1',
			(string) $wpdb->get_var( 'SELECT @@autocommit' ),
			'this file only proves anything under real autocommit=1'
		);
	}

	/**
	 * The realistic risk this file exists to rule out: Game::rename() opens its own
	 * Transaction::begin(), and if that were built the naive way (checking bare
	 * @@autocommit instead of the Transaction class's own depth counter), a caller who
	 * already had a transaction open would have it silently committed out from under them
	 * the moment rename()'s own nested START TRANSACTION ran (Decision 073's exact bug
	 * class). Proven by wrapping a real rename() call inside an outer unit of work and
	 * rolling the outer one back - both the slug change AND the character's owner_slug
	 * change must revert together, because they were never really independent.
	 */
	public function test_rename_nests_correctly_inside_an_outer_transaction_and_rolls_back_completely(): void {
		global $wpdb;
		// sanitize_title() lowercases - Game::create()/rename() both apply it, so the test's
		// own tracking variable must match what actually gets stored, or cleanup silently
		// matches nothing and leaks a row into every test that runs after this one.
		$slug = sanitize_title( 'txn-rename-' . wp_generate_password( 8, false ) );
		self::$cleanup_slugs[] = $slug;
		$new_slug = $slug . '-renamed';

		$game_id = Game::create( [ 'name' => 'Atomicity Test', 'slug' => $slug ] );
		$this->assertIsInt( $game_id );
		$character_id = Character::create( [
			'name'       => 'Atomicity Character',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $slug,
		] );

		// The outer transaction MUST be resolved before this test ends no matter what -
		// Transaction's own depth counter is static/shared across the whole PHPUnit
		// process, and an unresolved begin() here would desync every later test's
		// savepoint nesting, not just this one (this is exactly what happened while
		// writing this test: an assertion failure before the rollback call left the
		// counter one level deep for the rest of the run).
		$outer = Transaction::begin( 'atomicity_test_outer' );
		try {
			$result = Game::rename( (int) $game_id, $new_slug );
			$this->assertTrue( $result['changed'], 'the rename itself must succeed before what happens to it under rollback means anything' );

			// Sanity check mid-flight: the rename is visible on this same connection before
			// the outer rollback happens, same as any real caller mid-request would see it.
			$this->assertSame( $new_slug, Game::find( (int) $game_id )->slug );
			$this->assertSame( $new_slug, Character::find( (int) $character_id )->owner_slug );
		} finally {
			Transaction::rollback( $outer );
		}

		$this->assertSame(
			$slug,
			Game::find( (int) $game_id )->slug,
			'the game slug must revert to its pre-rename value once the OUTER caller rolls back - ' .
			'if this fails, rename()\'s own inner transaction silently committed the outer one first, ' .
			'exactly the bug class Decision 073 exists to prevent'
		);
		$this->assertSame(
			$slug,
			Character::find( (int) $character_id )->owner_slug,
			'the character must revert together with the game - a rename that only half-undoes is worse than one that never ran'
		);
	}
}
