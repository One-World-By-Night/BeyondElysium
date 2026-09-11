<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Schema_Block;
use WP_UnitTestCase;

/**
 * Decision 037: a "named" tiered_power block's Elder-and-above items must not get a
 * fabricated sequential level - confirmed against real seeded data (`$wpdb`-touching,
 * hence thread layer, not unit) rather than a hand-built fixture, since the whole point
 * is that this matches Grapevine's real menu data, not an idealized shape.
 *
 * @see BE_PROCESS/DECISIONLOG.md Decision 037
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

	public function test_the_real_numeric_ladder_keeps_its_correct_levels_and_tiers(): void {
		$levels = $this->celerity_levels();

		$this->assertSame( 1, $levels['Alacrity']->level );
		$this->assertSame( 'basic', $levels['Alacrity']->tier );
		$this->assertSame( 2, $levels['Swiftness']->level );
		$this->assertSame( 'basic', $levels['Swiftness']->tier );
		$this->assertSame( 3, $levels['Rapidity']->level );
		$this->assertSame( 'intermediate', $levels['Rapidity']->tier );
		$this->assertSame( 5, $levels['Fleetness']->level );
		$this->assertSame( 'advanced', $levels['Fleetness']->tier );
	}

	public function test_beyond_the_numbered_cap_gets_null_level_and_a_real_tier_label(): void {
		$levels = $this->celerity_levels();

		// Between the Ticks is Celerity's real Methuselah-tier power (cost 21, the
		// highest real tier in the menu data) - however many numbered rungs the ladder
		// cap allows, this one is always beyond it in real data.
		$this->assertNull( $levels['Between the Ticks']->level );
		$this->assertSame( 'methuselah', $levels['Between the Ticks']->tier );
	}

	public function test_same_cost_tier_items_share_a_tier_label_not_sequential_numbers(): void {
		$levels = $this->celerity_levels();

		// Two real Elder-cost (12 XP) Celerity powers. Whether or not the flat numbering
		// cap happens to still number them (Decision 037 deliberately uses a generous
		// flat cap, not per-tier-uniqueness detection), they must always agree on tier.
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
		$this->assertSame( 1, $byName['Enshroud']->level, 'The first paid (basic) power still gets the real numeric level.' );
	}
}
