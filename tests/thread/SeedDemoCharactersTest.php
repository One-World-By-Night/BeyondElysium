<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use WP_UnitTestCase;

/**
 * "Port ALL 22 test characters into the DEFAULT instance to demonstrate that it WORKS...
 * make it part of our core install" (2026-09-09) - ported from
 * tests/fixtures/seed-stock-characters.php (originally written against the real `kony`
 * chronicle) into a dedicated `be-demo` game instead, so it ships with the plugin without
 * ever touching a real chronicle's own roster.
 *
 * seed_demo_characters() also runs once during the WordPress test suite's own bootstrap
 * (Schema::maybe_upgrade(), version-gated - fires outside any single test's transaction,
 * the same as on a real install), so be-demo can legitimately already exist before any
 * test method here runs. Removed in setUp() so every test gets a genuinely clean,
 * deterministic slate rather than depending on bootstrap timing.
 */
class SeedDemoCharactersTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_characters WHERE owner_slug = 'be-demo'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_games WHERE slug = 'be-demo'" );
	}

	public function test_creates_the_demo_game_if_it_does_not_exist(): void {
		$this->assertNull( Game::find_by_slug( 'be-demo' ) );

		Seeder::seed_demo_characters();

		$game = Game::find_by_slug( 'be-demo' );
		$this->assertNotNull( $game );
		$this->assertSame( 'Beyond Elysium Demo', $game->name );
	}

	public function test_creates_all_22_characters_across_all_11_stacks(): void {
		Seeder::seed_demo_characters();

		global $wpdb;
		$table = $wpdb->prefix . 'be_characters';
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_slug = %s", 'be-demo' ) );
		$this->assertSame( 22, $count );

		$stacks = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT stack_slug FROM {$table} WHERE owner_slug = %s", 'be-demo' ) );
		sort( $stacks );
		$this->assertSame(
			[ 'bete', 'changeling', 'demon', 'fera', 'kueijin', 'mage', 'mortal', 'mummy', 'vampire', 'werewolf', 'wraith' ],
			$stacks
		);
	}

	public function test_a_real_character_has_its_full_sheet_data_and_xp(): void {
		Seeder::seed_demo_characters();

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}be_characters WHERE name = %s AND owner_slug = %s",
			'Isolde Marchetti', 'be-demo'
		) );

		$this->assertNotNull( $row );
		$this->assertSame( 90, (int) $row->xp_earned );
		$this->assertSame( 10, (int) $row->xp_unspent );
		$sheet = json_decode( $row->sheet_data, true );
		$this->assertSame( 'Tremere', $sheet['vampire-identity']['Clan'] );
	}

	public function test_running_it_twice_does_not_create_duplicates(): void {
		Seeder::seed_demo_characters();
		Seeder::seed_demo_characters();

		global $wpdb;
		$table = $wpdb->prefix . 'be_characters';
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_slug = %s", 'be-demo' ) );
		$this->assertSame( 22, $count );
	}

	public function test_never_touches_a_real_chronicles_own_game(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'thread-test-real-chronicle', 'name' => 'A Real Chronicle',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		Seeder::seed_demo_characters();

		$count = Character::count_for_game( 'thread-test-real-chronicle' );
		$this->assertSame( 0, $count, 'Demo characters must only ever land in be-demo, never an existing real game.' );
	}
}
