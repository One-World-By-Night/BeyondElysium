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
 * A new install is marked as on the per-creature catalog.
 */
class FreshInstallDeclaredThreadTest extends WP_UnitTestCase {

	private const STACKS = [ 'vampire', 'werewolf', 'mage', 'wraith', 'changeling', 'demon', 'mummy', 'kueijin', 'fera', 'bete', 'mortal' ];

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Manager::table( 'characters' ) );
		delete_option( Catalog_Cutover::OPTION );
	}

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		Option_Lock::release( Catalog_Cutover::LOCK );
		parent::tearDown();
	}

	/** @return string[] */
	private function sections( string $stack ): array {
		$found = Creature_Stack::find_by_slug( $stack );
		$this->assertNotNull( $found, "stack {$stack} is not seeded" );
		return array_column( (array) $found->stack_definition->sections, 'block_slug' );
	}

	public function test_a_new_install_is_marked(): void {
		$this->assertFalse( Catalog_Cutover::is_declared() );

		$this->assertTrue( Catalog_Cutover::declare_fresh_install() );

		$this->assertTrue( Catalog_Cutover::is_declared() );
	}

	public function test_stacks_seeded_afterwards_name_only_per_creature_blocks(): void {
		Catalog_Cutover::declare_fresh_install();
		Seeder::seed_creature_stacks();

		foreach ( self::STACKS as $stack ) {
			// What a stack replaced is that stack's own: `werewolf-rites` is replaced for Fera and kept for Werewolf.
			$replaced = array_keys( Catalog_Reader::replacement_maps()[ $stack ] ?? [] );
			$sections = $this->sections( $stack );
			$this->assertContains( 'met-abilities', $replaced, $stack );
			$this->assertNotEmpty( $sections, $stack );
			$this->assertSame( [], array_values( array_intersect( $sections, $replaced ) ), "{$stack} still names a replaced block" );
		}
		$this->assertContains( 'vampire-abilities', $this->sections( 'vampire' ) );
		$this->assertContains( 'fera-rites', $this->sections( 'fera' ) );
	}

	public function test_an_install_that_already_holds_a_character_is_not_marked(): void {
		Game::create( [ 'slug' => 'fresh-guard', 'name' => 'Fresh Guard' ] );
		Character::create( [
			'name' => 'Already Here', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => 'fresh-guard', 'status' => 'active',
			'sheet_data' => [ 'met-abilities' => [ [ 'name' => 'Brawl', 'count' => 1 ] ] ],
		] );

		$this->assertFalse( Catalog_Cutover::declare_fresh_install() );

		$this->assertFalse( Catalog_Cutover::is_declared() );
	}

	public function test_an_install_that_is_already_marked_is_left_as_it_is(): void {
		update_option( Catalog_Cutover::OPTION, 'declared', true );

		$this->assertFalse( Catalog_Cutover::declare_fresh_install() );
		$this->assertTrue( Catalog_Cutover::is_declared() );
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

	public function test_ensure_declared_marks_an_empty_install_and_leaves_a_marked_one(): void {
		$this->assertSame( [ 'status' => 'marked' ], Catalog_Cutover::ensure_declared() );
		$this->assertTrue( Catalog_Cutover::is_declared() );

		$this->assertSame( [ 'status' => 'already_declared' ], Catalog_Cutover::ensure_declared() );
	}

	public function test_a_refusal_is_worded_for_each_reason(): void {
		$this->assertStringContainsString( 'catalog files are missing', Catalog_Cutover::refusal_message( [ 'status' => 'refused', 'reason' => 'no_declared_catalog' ] ) );
		$this->assertStringContainsString( 'already running', Catalog_Cutover::refusal_message( [ 'status' => 'locked' ] ) );
		$this->assertStringContainsString( 'vampire -> vampire-abilities', Catalog_Cutover::refusal_message( [ 'status' => 'refused', 'reason' => 'declared_blocks_missing', 'missing' => [ 'vampire -> vampire-abilities' ] ] ) );
		$this->assertStringContainsString( '3 characters exist but only 1 belong', Catalog_Cutover::refusal_message( [ 'status' => 'refused', 'reason' => 'characters_outside_any_chronicle', 'on_install' => 3, 'planned' => 1 ] ) );
		$this->assertStringContainsString( 'character 7: boom', Catalog_Cutover::refusal_message( [ 'status' => 'partial', 'failed' => [ 7 => [ 'reason' => 'boom' ] ] ] ) );

		$gaps = [];
		for ( $i = 1; $i <= 7; $i++ ) {
			$gaps[] = [ 'character' => "Char {$i}", 'game' => 'g', 'name' => "Entry {$i}", 'block_to' => 'vampire-abilities' ];
		}
		$message = Catalog_Cutover::refusal_message( [ 'status' => 'refused', 'reason' => 'retention_gaps', 'retention_gaps' => $gaps ] );
		$this->assertStringContainsString( '7 held entries would be lost', $message );
		$this->assertStringContainsString( 'Char 1 (g): Entry 1 is not in vampire-abilities', $message );
		$this->assertStringContainsString( 'and 2 more', $message );
		$this->assertStringNotContainsString( 'Char 6', $message, 'only the first five are named' );
	}
}
