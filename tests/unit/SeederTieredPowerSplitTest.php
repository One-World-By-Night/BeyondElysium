<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 1.2.10 S2/S2b - a family's flat level list splits into three containers, and the split is
 * the D68 fix: the stepper reads `levels`, the pick list reads `elder`, and they cannot be
 * confused because they are not the same array.
 *
 * The invariant every test here protects is that the three containers **partition** the
 * input - nothing invented, nothing dropped. Measured against the real seeded catalog while
 * this was built: 5,518 levels in, 3,039 rungs + 503 picks + 1,976 overflow, exact.
 */
class SeederTieredPowerSplitTest extends TestCase {

	/** @param array<int,array<string,mixed>> $levels */
	private function split( string $slug, array $levels ): array {
		$m = new ReflectionMethod( Seeder::class, 'split_levels' );
		$m->setAccessible( true );
		return $m->invoke( null, $slug, $levels );
	}

	/** @param array<int,array<string,mixed>> $powers */
	private function meta( string $slug, array $powers ): array {
		$m = new ReflectionMethod( Seeder::class, 'meta_for' );
		$m->setAccessible( true );
		return $m->invoke( null, $slug, $powers );
	}

	private function level( string $tier, string $name, ?string $cost = null ): array {
		$l = [ 'level' => null, 'power_name' => $name, 'tier' => $tier ];
		if ( null !== $cost ) {
			$l['cost'] = $cost;
		}
		return $l;
	}

	public function test_a_full_ladder_numbers_its_rungs_one_to_five(): void {
		$split = $this->split( 'vampire-disciplines', [
			$this->level( 'basic', 'One' ),
			$this->level( 'basic', 'Two' ),
			$this->level( 'intermediate', 'Three' ),
			$this->level( 'intermediate', 'Four' ),
			$this->level( 'advanced', 'Five' ),
		] );

		$this->assertSame( [ 1, 2, 3, 4, 5 ], array_column( $split['levels'], 'level' ) );
		$this->assertSame( [], $split['elder'] );
		$this->assertSame( [], $split['overflow'] );
	}

	public function test_above_ladder_ranks_become_picks_keyed_by_rank_never_flattened(): void {
		// The two depths must stay separate: `elder` is the section, its keys are ranks.
		// Merging them is precisely what recreated D68.
		$split = $this->split( 'vampire-disciplines', [
			$this->level( 'basic', 'One' ),
			$this->level( 'elder', 'Blink' ),
			$this->level( 'elder', 'Unerring Aim' ),
			$this->level( 'master', 'Stampede' ),
		] );

		$this->assertSame( [ 'Blink', 'Unerring Aim' ], array_column( $split['elder']['elder'], 'power_name' ) );
		$this->assertSame( [ 'Stampede' ], array_column( $split['elder']['master'], 'power_name' ) );
		$this->assertSame( [ 'One' ], array_column( $split['levels'], 'power_name' ) );
	}

	public function test_wraith_innate_is_a_pick_below_the_ladder_not_a_rung(): void {
		// MET-POWER-ACQUISITION.md: innate "sits below the ladder, is never counted in the
		// rating." It is in `ranks` but absent from `ladder`, so it files as a pick - the
		// same mechanism as an above-ladder rank, in the other direction.
		$split = $this->split( 'wraith-arcanoi', [
			$this->level( 'innate', 'Sense Gauntlet' ),
			$this->level( 'basic', 'Enshroud' ),
			$this->level( 'basic', 'Flicker' ),
		] );

		$this->assertSame( [ 'Sense Gauntlet' ], array_column( $split['elder']['innate'], 'power_name' ) );
		$this->assertSame( [ 1, 2 ], array_column( $split['levels'], 'level' ) );
	}

	public function test_ladder_rank_levels_beyond_the_ceiling_go_to_overflow_carrying_no_rung_number(): void {
		// D67's signal. A family whose two ladders are split correctly has none of these.
		$levels = [];
		foreach ( [ 'basic', 'basic', 'basic', 'intermediate', 'intermediate', 'advanced', 'advanced' ] as $i => $tier ) {
			$levels[] = $this->level( $tier, 'P' . $i );
		}
		$split = $this->split( 'vampire-disciplines', $levels );

		$this->assertCount( 5, $split['levels'], 'the ladder sum is 2+2+1' );
		$this->assertCount( 2, $split['overflow'] );
		$this->assertSame( [ null, null ], array_column( $split['overflow'], 'level' ), 'overflow is not a rung and carries no number' );
	}

	public function test_the_three_containers_partition_the_input_exactly(): void {
		$levels = [];
		foreach ( [ 'basic', 'basic', 'basic', 'intermediate', 'advanced', 'elder', 'master', 'innate' ] as $i => $tier ) {
			$levels[] = $this->level( $tier, 'P' . $i );
		}
		$split = $this->split( 'wraith-arcanoi', $levels );

		$picks = 0;
		foreach ( $split['elder'] as $rank ) {
			$picks += count( $rank );
		}
		$this->assertSame(
			count( $levels ),
			count( $split['levels'] ) + $picks + count( $split['overflow'] ),
			'nothing invented, nothing dropped'
		);
	}

	public function test_rungs_order_by_rank_then_source_order_so_rung_one_is_the_lowest(): void {
		$split = $this->split( 'vampire-disciplines', [
			$this->level( 'advanced', 'Adv' ),
			$this->level( 'basic', 'BasicA' ),
			$this->level( 'intermediate', 'Int' ),
			$this->level( 'basic', 'BasicB' ),
		] );

		$this->assertSame( [ 'BasicA', 'BasicB', 'Int', 'Adv' ], array_column( $split['levels'], 'power_name' ) );
	}

	public function test_meta_declares_the_ladder_and_derives_costs_from_the_blocks_own_data(): void {
		// Costs are read back out of the block, never declared here - so this release
		// changes no price on any sheet. D69/D70's wrong values stay wrong until 1.3.0
		// corrects them against the books.
		$meta = $this->meta( 'vampire-disciplines', [ [
			'name'   => 'Celerity',
			'levels' => [ $this->level( 'basic', 'One', '3' ), $this->level( 'intermediate', 'Two', '6' ) ],
			'elder'  => [ 'elder' => [ $this->level( 'elder', 'Blink', '12' ) ] ],
		] ] );

		$this->assertSame( 5, array_sum( $meta['ladder'] ) );
		$this->assertSame( 'vampire-identity.Clan', $meta['in_type_source'], 'D75: the join that makes the surcharge fire' );
		$this->assertSame( [ 'basic' => 3, 'intermediate' => 6, 'elder' => 12 ], $meta['costs'] );
	}

	public function test_meta_costs_never_invent_a_rank_the_block_does_not_declare(): void {
		// D72 leaked combo-Discipline names into `mortal-numina`'s tier field. They must not
		// reappear as declared ranks carrying declared prices.
		$meta = $this->meta( 'mortal-numina', [ [
			'name'   => 'Numen',
			'levels' => [ $this->level( 'basic', 'Real', '3' ) ],
			'elder'  => [ 'telepathy + presence' => [ $this->level( 'telepathy + presence', 'Bogus', '9' ) ] ],
		] ] );

		$this->assertSame( [ 'basic' => 3 ], $meta['costs'] );
	}

	public function test_out_of_type_is_an_expression_per_rank_not_a_number(): void {
		// The two cases a scalar broke on, and the exemption it made unnecessary.
		$mage = $this->meta( 'mage-spheres', [] );
		$this->assertSame( [ 'basic' => '+1', 'intermediate' => '+2', 'advanced' => '+3' ], $mage['out_of_type'], 'Mage scales' );

		$wraith = $this->meta( 'wraith-arcanoi', [] );
		$this->assertSame( '+0', $wraith['out_of_type']['innate'], "Wraith's Innate exemption is just +0" );
		$this->assertSame( '-1', $wraith['out_of_type']['basic'], 'the Guild discount is a discount' );

		$kuei = $this->meta( 'kueijin-disciplines', [] );
		$this->assertArrayNotHasKey( 'out_of_type', $kuei, 'Kuei-Jin has no modifier in the chart at all' );
	}
}
