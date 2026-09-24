<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Option_Lock;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use WP_UnitTestCase;

/**
 * 1.3.4: a brand-new install starts on the per-creature catalog. `declare_fresh_install()` is what
 * activation calls before it seeds anything, so stacks, templates and demo characters come up on the
 * per-creature blocks and there is nothing to re-key. An install that already exists is never flipped
 * by it: those stay where they are until somebody runs `apply()`.
 */
class FreshInstallDeclaredThreadTest extends WP_UnitTestCase {

	private const STACKS = [ 'vampire', 'werewolf', 'mage', 'wraith', 'changeling', 'demon', 'mummy', 'kueijin', 'fera', 'bete', 'mortal' ];

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		// This database's plugin tables outlive a run, so start from an empty roster and a legacy
		// install (both rolled back with the test).
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Manager::table( 'characters' ) );
		delete_option( Catalog_Cutover::OPTION );
		delete_option( Catalog_Cutover::RECORD_OPTION );
		Catalog_Cutover::reset_cache();
	}

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		delete_option( Catalog_Cutover::RECORD_OPTION );
		Option_Lock::release( Catalog_Cutover::LOCK );
		Catalog_Cutover::reset_cache();
		parent::tearDown();
	}

	/** @return string[] */
	private function sections( string $stack ): array {
		$found = Creature_Stack::find_by_slug( $stack );
		$this->assertNotNull( $found, "stack {$stack} is not seeded" );
		return array_column( (array) $found->stack_definition->sections, 'block_slug' );
	}

	public function test_a_new_install_is_declared_and_says_so(): void {
		$this->assertFalse( Catalog_Cutover::is_declared() );

		$this->assertTrue( Catalog_Cutover::declare_fresh_install() );

		$this->assertTrue( Catalog_Cutover::is_declared() );
		$status = Catalog_Cutover::status();
		$this->assertTrue( $status['declared'] );
		$this->assertTrue( $status['fresh_install'] );
		$this->assertSame( BE_VERSION, $status['plugin_version'] );
		$this->assertNotNull( $status['applied_at'] );
		$this->assertSame( 0, $status['characters_rekeyed'] );
	}

	public function test_stacks_seeded_afterwards_name_only_per_creature_blocks(): void {
		// The control: on a legacy install the same seeding names the shared blocks, so the
		// assertions below cannot pass by accident.
		Seeder::seed_creature_stacks();
		$this->assertContains( 'met-abilities', $this->sections( 'vampire' ) );

		Catalog_Cutover::declare_fresh_install();
		Seeder::seed_creature_stacks();

		foreach ( self::STACKS as $stack ) {
			// What a stack's catalog retired is that stack's own: `werewolf-rites` is retired for Fera
			// and live for Werewolf.
			$retired  = array_keys( Catalog_Cutover::replacement_map( $stack ) );
			$sections = $this->sections( $stack );
			$this->assertContains( 'met-abilities', $retired, $stack );
			$this->assertNotEmpty( $sections, $stack );
			$this->assertSame( [], array_values( array_intersect( $sections, $retired ) ), "{$stack} still names a retired block" );
		}
		$this->assertContains( 'vampire-abilities', $this->sections( 'vampire' ) );
		$this->assertContains( 'fera-rites', $this->sections( 'fera' ) );
	}

	public function test_an_install_that_already_holds_a_character_is_never_flipped(): void {
		Game::create( [ 'slug' => 'fresh-guard', 'name' => 'Fresh Guard' ] );
		Character::create( [
			'name' => 'Already Here', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => 'fresh-guard', 'status' => 'active',
			'sheet_data' => [ 'met-abilities' => [ [ 'name' => 'Brawl', 'count' => 1 ] ] ],
		] );

		$this->assertFalse( Catalog_Cutover::declare_fresh_install() );

		$this->assertFalse( Catalog_Cutover::is_declared() );
		$this->assertFalse( get_option( Catalog_Cutover::RECORD_OPTION ) );
	}

	public function test_an_install_that_is_already_declared_keeps_its_own_record(): void {
		update_option( Catalog_Cutover::OPTION, 'declared', true );
		update_option( Catalog_Cutover::RECORD_OPTION, [ 'applied_at' => '2026-09-23T00:00:00+00:00', 'actor' => 7, 'plugin_version' => '1.3.3' ], false );

		$this->assertFalse( Catalog_Cutover::declare_fresh_install() );

		$record = get_option( Catalog_Cutover::RECORD_OPTION );
		$this->assertSame( 7, $record['actor'] );
		$this->assertArrayNotHasKey( 'fresh_install', $record );
		$this->assertFalse( Catalog_Cutover::status()['fresh_install'] );
	}

	public function test_a_build_without_a_declared_catalog_leaves_the_install_alone(): void {
		$without = new class() extends Catalog_Cutover {
			protected static function catalog_available(): bool {
				return false;
			}
		};

		$this->assertFalse( $without::declare_fresh_install() );
		$this->assertFalse( Catalog_Cutover::is_declared() );
	}

	public function test_a_new_install_has_no_earlier_state_to_roll_back_to(): void {
		Catalog_Cutover::declare_fresh_install();
		$actor = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$result = Catalog_Cutover::rollback( $actor );

		$this->assertSame( 'started_declared', $result['status'] );
		$this->assertTrue( Catalog_Cutover::is_declared() );
		$this->assertTrue( Catalog_Cutover::status()['fresh_install'] );
	}

	public function test_a_switched_install_does_not_read_as_a_new_one(): void {
		$actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Catalog_Cutover::apply( $actor );

		$status = Catalog_Cutover::status();

		$this->assertTrue( $status['declared'] );
		$this->assertFalse( $status['fresh_install'] );
	}
}
