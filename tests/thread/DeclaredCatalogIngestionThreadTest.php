<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Cost_Engine;
use WP_UnitTestCase;

/**
 * 1.3.2: seeds Vampire Disciplines and Wraith Arcanoi (OWBN variant included) end to end
 * from the real declared files under `data/catalog/`, through `Seeder::seed_schema_blocks()`
 * -> `Catalog_Reader::blocks_to_seed()` -> the real `Cost_Engine`, and confirms
 * ceiling/cost/out-of-type/elder all resolve identically to the same numbers
 * `CostEngineHeldPricingTest` (unit layer, a hand-built fixture) and
 * `TieredPowerLevelsThreadTest` already assert for the GVM-seeded shape - the whole point of
 * "closer to a direct decode than a transform" (1.3.2 build brief item 1).
 *
 * Do not run this against a shared WP_TESTS_DIR database until told it is free - see
 * `BE_PROCESS/releases/1.3.2-design-workflow.md`.
 *
 * @see BE_PROCESS/releases/1.3.2-design-workflow.md
 */
class DeclaredCatalogIngestionThreadTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Seeder::seed_schema_blocks();
	}

	private function definition( string $slug ): object {
		return Schema_Block::find_by_slug( $slug )->definition;
	}

	private function power( string $slug, string $name ): object {
		foreach ( $this->definition( $slug )->powers as $power ) {
			if ( $power->name === $name ) {
				return $power;
			}
		}
		$this->fail( "{$name} not found in {$slug}." );
	}

	// -------------------------------------------------------------------------
	// Vampire Disciplines - the one genre whose out-of-type surcharge already
	// worked under the GVM path (D75). The bridge must not regress it.
	// -------------------------------------------------------------------------

	public function test_vampire_disciplines_seeds_from_the_declared_file_not_gvm(): void {
		$definition = $this->definition( 'vampire-disciplines' );

		// assertEquals, not assertSame: the declared file's own key order need not match a
		// PHP array literal's (CATALOG-JSON-FORMAT.md principle 4), and every rank here is
		// read by name, never by position.
		$this->assertEquals( [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ], (array) $definition->_meta->ladder );
		// The declared file's own out_of_type_cost_modifier bridge (Catalog_Reader) - D75's
		// one already-working case, preserved exactly rather than silently zeroed by cutover.
		$this->assertSame( 1, $definition->out_of_type_cost_modifier );
	}

	public function test_celerity_ceiling_prices_identically_to_the_unit_fixture(): void {
		$result = Cost_Engine::price_held_tiered_power( $this->definition( 'vampire-disciplines' ), [ 'name' => 'Celerity', 'level' => 5 ], true );

		// Matches CostEngineHeldPricingTest::test_a_stored_level_at_the_ceiling_is_unaffected_by_c1.
		$this->assertSame( 27, $result['xp'] );
		$this->assertSame( 'sequential_sum', $result['basis'] );
	}

	public function test_celerity_out_of_clan_charges_the_bridged_modifier_on_every_rung_and_pick(): void {
		$definition  = $this->definition( 'vampire-disciplines' );
		$in_clan     = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => 'Celerity', 'level' => 6 ], true );
		$out_of_clan = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => 'Celerity', 'level' => 6 ], false );

		// Matches CostEngineHeldPricingTest::test_an_above_ceiling_level_out_of_clan_charges_the_modifier_on_every_rung_and_pick:
		// 5 rungs + 1 pick = 6 chargeable units, each +1 out-of-clan.
		$this->assertSame( $in_clan['xp'] + 6, $out_of_clan['xp'] );
	}

	public function test_celerity_elder_pick_precision_prices_from_the_declared_elder_cost(): void {
		$result = Cost_Engine::price_held_tiered_power( $this->definition( 'vampire-disciplines' ), [ 'name' => 'Celerity', 'power_name' => 'Precision' ], true );

		// Matches CostEngineHeldPricingTest::test_a_named_pick_survives_the_declared_ladder_split.
		$this->assertSame( 12, $result['xp'] );
		$this->assertSame( 'elder_pick', $result['basis'] );
	}

	// -------------------------------------------------------------------------
	// Wraith Arcanoi - the book (base file), a scaling/exempting out-of-type
	// expression the bridge must NOT flatten, and Innate filed as a pick, never
	// a rung (matches TieredPowerLevelsThreadTest's own Argos assertion).
	// -------------------------------------------------------------------------

	public function test_wraith_arcanoi_seeds_from_the_declared_file_with_the_book_ladder(): void {
		$definition = $this->definition( 'wraith-arcanoi' );

		$this->assertSame( [ 'innate', 'basic', 'intermediate', 'advanced' ], (array) $definition->_meta->ranks );
		// assertEquals, not assertSame - same key-order note as above.
		$this->assertEquals( [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ], (array) $definition->_meta->ladder );
		$this->assertSame( 2, $definition->_meta->costs->innate, 'Oblivion p.165: Innate now prices, never free' );
		// out_of_type is not flat (innate is exempt at +0, every other rank is -1 for the
		// Guild) - the bridge must leave the deprecated scalar unset rather than guess at a
		// single flattened number the way D75 measured every non-Vampire genre wrongly doing.
		$this->assertObjectNotHasProperty( 'out_of_type_cost_modifier', $definition );
	}

	public function test_argos_ceiling_prices_from_the_books_own_4_6_9_scale(): void {
		$result = Cost_Engine::price_held_tiered_power( $this->definition( 'wraith-arcanoi' ), [ 'name' => 'Argos', 'level' => 5 ], true );

		// 2 basic (4 each) + 2 intermediate (6 each) + 1 advanced (9) = 29.
		$this->assertSame( 29, $result['xp'] );
		$this->assertSame( 'sequential_sum', $result['basis'] );
	}

	public function test_argos_innate_pick_orienteering_is_a_pick_never_a_rung(): void {
		$argos = $this->power( 'wraith-arcanoi', 'Argos' );

		$innate = array_column( (array) $argos->elder->innate, 'power_name' );
		$this->assertContains( 'Orienteering', $innate );
		$this->assertNotContains( 'innate', array_column( (array) $argos->levels, 'tier' ) );

		$result = Cost_Engine::price_held_tiered_power( $this->definition( 'wraith-arcanoi' ), [ 'name' => 'Argos', 'power_name' => 'Orienteering' ], true );
		$this->assertSame( 2, $result['xp'], "Oblivion's own Innate price, no longer free" );
		$this->assertSame( 'elder_pick', $result['basis'] );
	}

	// -------------------------------------------------------------------------
	// The OWBN variant - a `mode: replace` file, seeded as its own complete row
	// under its own slug, never merged into the base.
	// -------------------------------------------------------------------------

	public function test_the_owbn_variant_seeds_as_its_own_complete_row(): void {
		$block = Schema_Block::find_by_slug( 'owbn-wraith_arcanoi' );
		$this->assertNotNull( $block, 'the replace variant seeds under its own slug' );
		$this->assertSame( 'tiered_power', $block->section_type );

		$definition = $block->definition;
		$this->assertSame( 0, $definition->_meta->costs->innate, 'OWBN0124: Innate is free "at no additional cost"' );
		$this->assertSame( 4, $definition->_meta->costs->basic );
		$this->assertSame( 7, $definition->_meta->costs->intermediate );
		$this->assertSame( 10, $definition->_meta->costs->advanced );

		// The base block is untouched by the variant's existence.
		$this->assertSame( 4, $this->definition( 'wraith-arcanoi' )->_meta->costs->basic );
	}

	public function test_the_owbn_variants_argos_prices_on_its_own_packet_scale(): void {
		$result = Cost_Engine::price_held_tiered_power( $this->definition( 'owbn-wraith_arcanoi' ), [ 'name' => 'Argos', 'level' => 5 ], true );

		// 2 basic (4) + 2 intermediate (7) + 1 advanced (10) = 32, the packet's own 4/7/10.
		$this->assertSame( 32, $result['xp'] );
	}

	public function test_the_owbn_variants_innate_picks_price_free(): void {
		$result = Cost_Engine::price_held_tiered_power( $this->definition( 'owbn-wraith_arcanoi' ), [ 'name' => 'Argos', 'power_name' => 'Orienteering' ], true );

		$this->assertSame( 0, $result['xp'], 'the packet prices every Innate Ability at 0' );
	}
}
