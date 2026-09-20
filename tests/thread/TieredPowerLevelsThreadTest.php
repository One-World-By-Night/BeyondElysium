<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Schema_Block;
use WP_UnitTestCase;

/**
 * D66 (1.2.5-design-workflow.md §A, A1): a tiered_power family's level is derived from
 * its tier (Seeder::TIER_RANKS), not array position - two or more items sharing a tier
 * share level=null, the same named-pool shape Decision 037 already established for
 * Elder-and-above. Confirmed against real seeded data (`$wpdb`-touching, hence thread
 * layer, not unit) rather than a hand-built fixture, since the whole point is that this
 * matches Grapevine's real menu data, not an idealized shape.
 *
 * @see BE_PROCESS/reference/DECISIONLOG.md Decision 037
 * @see BE_PROCESS/releases/1.2.5-design-workflow.md §A
 */
class TieredPowerLevelsThreadTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Seeder::seed_schema_blocks();
	}

	/** @return array<string,object> Celerity's levels, keyed by power_name. */
	private function celerity_levels(): array {
		$block = Schema_Block::find_by_slug( 'vampire-disciplines' );
		foreach ( $block->definition->powers as $power ) {
			if ( $power->name === 'Celerity' ) {
				$byName = [];
				foreach ( $power->levels as $level ) {
					$byName[ $level->power_name ] = $level;
				}
				return $byName;
			}
		}
		$this->fail( 'Celerity not found in vampire-disciplines.' );
	}

	public function test_a_family_s_only_item_at_a_tier_gets_that_tier_s_real_rank(): void {
		$levels = $this->celerity_levels();

		// Fleetness, Zephyr, and Between the Ticks are each the ONLY real Celerity power
		// at their own tier - unlike Alacrity/Swiftness (both basic) or Rapidity/Legerity
		// (both intermediate), nothing else in the family shares their rank, so each gets
		// self::TIER_RANKS' real fixed number rather than level=null.
		$this->assertSame( 3, $levels['Fleetness']->level );
		$this->assertSame( 'advanced', $levels['Fleetness']->tier );
		$this->assertSame( 6, $levels['Zephyr']->level );
		$this->assertSame( 'ascended', $levels['Zephyr']->tier );
		$this->assertSame( 7, $levels['Between the Ticks']->level );
		$this->assertSame( 'methuselah', $levels['Between the Ticks']->tier );
	}

	public function test_two_items_sharing_a_tier_both_get_a_null_level_not_sequential_numbers(): void {
		$levels = $this->celerity_levels();

		// Alacrity and Swiftness are both real basic-tier (cost 3) Celerity powers - the
		// D66 defect this fix closes numbered them 1 and 2 as if one came before the
		// other. Neither is more "basic" than the other, so both get level=null and share
		// the real tier label, the same named-pool shape Decision 037 already established
		// for Elder-and-above.
		$this->assertNull( $levels['Alacrity']->level );
		$this->assertSame( 'basic', $levels['Alacrity']->tier );
		$this->assertNull( $levels['Swiftness']->level );
		$this->assertSame( 'basic', $levels['Swiftness']->tier );
	}

	public function test_same_cost_tier_items_share_a_tier_label_not_sequential_numbers(): void {
		$levels = $this->celerity_levels();

		// Two real Elder-cost (12 XP) Celerity powers - both null (tied), and must always
		// agree on tier regardless.
		$this->assertNull( $levels['Precision']->level );
		$this->assertNull( $levels['Projectile']->level );
		$this->assertSame( $levels['Precision']->tier, $levels['Projectile']->tier );
		$this->assertSame( 'elder', $levels['Precision']->tier );
	}

	public function test_innate_and_dark_ages_notes_normalize_correctly(): void {
		// Argos (Wraith Arcanoi) has real cost=0 "innate" powers - confirm the tier
		// normalizer recognizes them, using the same real-data path as Celerity above.
		$block = Schema_Block::find_by_slug( 'wraith-arcanoi' );
		$argos = null;
		foreach ( $block->definition->powers as $power ) {
			if ( $power->name === 'Argos' ) {
				$argos = $power;
			}
		}
		$this->assertNotNull( $argos, 'Argos not found in wraith-arcanoi.' );

		$byName = [];
		foreach ( $argos->levels as $level ) {
			$byName[ $level->power_name ] = $level;
		}

		$this->assertSame( 'innate', $byName['Orienteering']->tier );
		// Enshroud and Phantom Wings are both real basic-tier Argos powers and are tied
		// (level=null); Flicker is Argos' only intermediate-tier power and is the first
		// one in the family to get a real numeric level.
		$this->assertNull( $byName['Enshroud']->level );
		$this->assertSame( 2, $byName['Flicker']->level, 'Argos\' only intermediate-tier power gets the real numeric level.' );
		$this->assertSame( 'intermediate', $byName['Flicker']->tier );
	}
}
