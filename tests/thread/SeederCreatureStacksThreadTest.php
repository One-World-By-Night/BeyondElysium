<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Services\Catalog_Reader;
use WP_UnitTestCase;

/**
 * Seeding the creature stacks writes each stack's sections from the declared catalog, with each creature type on its
 * own blocks.
 */
class SeederCreatureStacksThreadTest extends WP_UnitTestCase {

	public function test_seeding_writes_the_declared_catalogs_sections(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}

		Seeder::seed_creature_stacks();

		$vampire = Creature_Stack::find_by_slug( 'vampire' );
		$slugs   = array_column( $vampire->stack_definition->sections, 'block_slug' );
		$this->assertContains( 'vampire-abilities', $slugs );
		$this->assertNotContains( 'met-abilities', $slugs );
		// A declared section's own `replaces` key is not stored on the row.
		foreach ( $vampire->stack_definition->sections as $section ) {
			$this->assertFalse( property_exists( $section, 'replaces' ) );
		}
	}

	public function test_every_creature_type_lists_its_own_abilities_merits_and_flaws(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}

		Seeder::seed_creature_stacks();

		foreach ( Catalog_Reader::stacks_to_seed() as $slug => $declared ) {
			$stored = array_column( Creature_Stack::find_by_slug( $slug )->stack_definition->sections, 'block_slug' );
			$this->assertSame( array_column( $declared['stack_definition']['sections'], 'block_slug' ), $stored, $slug );
			$this->assertEmpty( array_intersect( [ 'met-abilities', 'met-merits', 'met-flaws' ], $stored ), "{$slug} lists no shared block" );
		}
	}
}
