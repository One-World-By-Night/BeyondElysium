<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use WP_UnitTestCase;

/**
 * C4: `Seeder::get_stacks_to_seed()` (private, exercised through the public
 * `seed_creature_stacks()`) follows `Catalog_Cutover::is_declared()`. Vampire is the
 * fixture stack throughout, since C1/C2 already established its real retirement map
 * (`met-abilities` -> `vampire-abilities`, among others).
 */
class SeederStacksCutoverThreadTest extends WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		Catalog_Cutover::reset_cache();
		parent::tearDown();
	}

	public function test_legacy_seeding_leaves_todays_stacks_unchanged(): void {
		Seeder::seed_creature_stacks();
		$vampire = Creature_Stack::find_by_slug( 'vampire' );
		$slugs   = array_column( $vampire->stack_definition->sections, 'block_slug' );
		$this->assertContains( 'met-abilities', $slugs );
		$this->assertNotContains( 'vampire-abilities', $slugs );
	}

	public function test_declared_seeding_uses_the_declared_catalog_sections(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		update_option( Catalog_Cutover::OPTION, 'declared' );

		Seeder::seed_creature_stacks();

		$vampire = Creature_Stack::find_by_slug( 'vampire' );
		$slugs   = array_column( $vampire->stack_definition->sections, 'block_slug' );
		$this->assertContains( 'vampire-abilities', $slugs );
		$this->assertNotContains( 'met-abilities', $slugs );
		// C2: a declared section's own `replaces` key must never reach a seeded row.
		foreach ( $vampire->stack_definition->sections as $section ) {
			$this->assertFalse( property_exists( $section, 'replaces' ) );
		}
	}
}
