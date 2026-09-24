<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use PHPUnit\Framework\TestCase;

/**
 * A chronicle rename rolls back completely when a step fails, on a real autocommit connection.
 */
class GameRenameAtomicityTest extends TestCase {

	/** @var string[] */
	private static array $cleanup_slugs = [];

	private string $prior_autocommit = '1';

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'BE_WP_TESTS_AVAILABLE' ) || ! BE_WP_TESTS_AVAILABLE ) {
			self::markTestSkipped( 'WP_TESTS_DIR not configured - see BE_PROCESS/now/PLATFORM.md.' );
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

	public function test_rename_nests_correctly_inside_an_outer_transaction_and_rolls_back_completely(): void {
		global $wpdb;
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

		// The outer transaction MUST be resolved before this test ends no matter what.
		$outer = Transaction::begin( 'atomicity_test_outer' );
		try {
			$result = Game::rename( (int) $game_id, $new_slug );
			$this->assertTrue( $result['changed'], 'the rename itself must succeed before what happens to it under rollback means anything' );

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
