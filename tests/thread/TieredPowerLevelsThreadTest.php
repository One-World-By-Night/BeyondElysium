<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Power_Levels;
use WP_UnitTestCase;

/**
 * 1.2.10 §A/S2: a `tiered_power` family declares its ladder and separates it from its
 * above-ladder picks, so **nothing infers a rank any more**.
 *
 * This class previously asserted D66's tie-inference model - "a tier with one item gets a
 * real number, a tier with several gets `level: null` on all of them". That model is what
 * 1.2.10 removes, and D68 is why: `level: null` meant both "dots 1 and 2" and "six elder
 * powers" in one array, so the stepper read it as a ladder, the checklist read it as a pool,
 * and switching views was read as levels removed - the engine offered a refund for XP never
 * spent. Re-asserting tie-inference here would be asserting the defect.
 *
 * Measured against real seeded data (hence thread layer, not unit), because the whole point
 * is that this matches Grapevine's real menu data rather than an idealized shape.
 *
 * @see BE_PROCESS/releases/1.2.10-design-workflow.md §A, §A1b, §B
 */
class TieredPowerLevelsThreadTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Seeder::seed_schema_blocks();
	}

	private function family( string $slug, string $name ): object {
		$block = Schema_Block::find_by_slug( $slug );
		foreach ( $block->definition->powers as $power ) {
			if ( $power->name === $name ) {
				return $power;
			}
		}
		$this->fail( "{$name} not found in {$slug}." );
	}

	/** @return array<string,object> Every level in a family, keyed by power_name. */
	private function by_name( object $power ): array {
		$out = [];
		foreach ( Power_Levels::all( $power ) as $level ) {
			$out[ $level->power_name ] = $level;
		}
		return $out;
	}

	public function test_the_ladder_holds_exactly_the_declared_rungs_numbered_from_one(): void {
		$block    = Schema_Block::find_by_slug( 'vampire-disciplines' );
		$ladder   = (array) $block->definition->_meta->ladder;
		$celerity = $this->family( 'vampire-disciplines', 'Celerity' );

		$numbers = array_column( Power_Levels::ladder( $celerity ), 'level' );
		$this->assertSame( range( 1, array_sum( $ladder ) ), $numbers, 'rungs number consecutively to the declared ladder sum' );
	}

	public function test_each_rungs_tier_follows_the_declared_quota_not_one_tier_per_rank(): void {
		// The correction behind the 45 -> 27 repricing. A 2/2/1 ladder is basic, basic,
		// intermediate, intermediate, advanced - never one tier per rank, which is what
		// charged elder and master rates for ladder rungs.
		$celerity = $this->family( 'vampire-disciplines', 'Celerity' );
		$tiers    = array_column( Power_Levels::ladder( $celerity ), 'tier' );

		$this->assertSame(
			[ 'basic', 'basic', 'intermediate', 'intermediate', 'advanced' ],
			$tiers
		);
	}

	public function test_above_ladder_powers_are_picks_keyed_by_rank_and_carry_no_rung_number(): void {
		$celerity = $this->family( 'vampire-disciplines', 'Celerity' );
		$this->assertTrue( isset( $celerity->elder ), 'Celerity holds real above-ladder powers' );

		$elder = (array) $celerity->elder;
		$this->assertArrayHasKey( 'elder', $elder, 'the section is `elder`; its keys are ranks' );

		foreach ( $elder as $rank => $picks ) {
			foreach ( (array) $picks as $pick ) {
				$this->assertNull( $pick->level, "a pick is not a rung, so {$pick->power_name} carries no number" );
				$this->assertSame( $rank, $pick->tier, 'a pick is filed under its own rank' );
			}
		}
	}

	public function test_a_named_elder_power_is_reachable_and_still_knows_its_rank(): void {
		// Precision and Projectile are two real 12-XP Celerity powers. Both are picks at the
		// same rank, which is legal and must not be collapsed or renumbered.
		$levels = $this->by_name( $this->family( 'vampire-disciplines', 'Celerity' ) );

		$this->assertArrayHasKey( 'Precision', $levels );
		$this->assertArrayHasKey( 'Projectile', $levels );
		$this->assertSame( 'elder', $levels['Precision']->tier );
		$this->assertSame( $levels['Precision']->tier, $levels['Projectile']->tier );
	}

	public function test_wraith_innate_is_a_pick_below_the_ladder_never_a_rung(): void {
		// MET-POWER-ACQUISITION.md: innate "sits below the ladder, is never counted in the
		// rating." It is in `ranks` but absent from `ladder`, so it files as a pick - the
		// same mechanism as an above-ladder rank, in the other direction.
		$argos = $this->family( 'wraith-arcanoi', 'Argos' );

		$innate = (array) ( $argos->elder->innate ?? [] );
		$this->assertNotEmpty( $innate, 'Argos holds real innate powers' );
		$this->assertContains( 'Orienteering', array_column( $innate, 'power_name' ) );

		$this->assertNotContains( 'innate', array_column( Power_Levels::ladder( $argos ), 'tier' ), 'innate is never a rung' );
	}

	public function test_the_three_containers_never_duplicate_a_power(): void {
		// The containers partition the family. Measured across the whole real catalog while
		// this was built: 5,518 levels in, 3,039 rungs + 503 picks + 1,976 overflow, exact.
		foreach ( [ 'vampire-disciplines', 'wraith-arcanoi', 'mage-spheres' ] as $slug ) {
			$block = Schema_Block::find_by_slug( $slug );
			foreach ( $block->definition->powers as $power ) {
				$names = array_column( Power_Levels::all( $power ), 'power_name' );
				$this->assertSame(
					count( $names ),
					count( array_unique( $names ) ),
					"{$slug} :: {$power->name} lists no power twice across its containers"
				);
			}
		}
	}
}
