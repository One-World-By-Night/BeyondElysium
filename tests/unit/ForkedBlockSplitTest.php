<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Services\Cost_Engine;
use PHPUnit\Framework\TestCase;

/**
 * A chronicle's forked `tiered_power` block is re-split into the three containers like the global block.
 */
class ForkedBlockSplitTest extends TestCase {

	/**
	 * A fork as it sits in the database: flat `levels`, no `_meta`, ties nulled.
	 */
	private function flat_fork(): array {
		return [
			'sequential' => true,
			'powers'     => [
				[
					'name'   => 'Animalism',
					'levels' => [
						[ 'level' => null, 'tier' => 'basic', 'cost' => '3', 'power_name' => 'Feral Whispers' ],
						[ 'level' => null, 'tier' => 'basic', 'cost' => '3', 'power_name' => 'Beckoning' ],
						[ 'level' => null, 'tier' => 'intermediate', 'cost' => '6', 'power_name' => 'Quell the Beast' ],
						[ 'level' => null, 'tier' => 'intermediate', 'cost' => '6', 'power_name' => 'Subsume the Spirit' ],
						[ 'level' => null, 'tier' => 'advanced', 'cost' => '9', 'power_name' => 'Drawing Out the Beast' ],
						[ 'level' => null, 'tier' => 'elder', 'cost' => '12', 'power_name' => 'Animal Succulence' ],
						[ 'level' => null, 'tier' => 'master', 'cost' => '15', 'power_name' => 'Stampede' ],
					],
				],
				[
					// The chronicle's own house power.
					'name'   => 'House Discipline',
					'levels' => [
						[ 'level' => null, 'tier' => 'basic', 'cost' => '3', 'power_name' => 'House One' ],
						[ 'level' => null, 'tier' => 'basic', 'cost' => '3', 'power_name' => 'House Two' ],
					],
				],
			],
		];
	}

	private function decoded( array $definition ): object {
		return json_decode( (string) json_encode( $definition ) );
	}

	public function test_an_unmigrated_fork_prices_the_old_way(): void {
		$result = Cost_Engine::price_held_tiered_power( $this->decoded( $this->flat_fork() ), [ 'name' => 'Animalism', 'level' => 5 ], true );

		$this->assertSame( 45, $result['xp'], 'a flat fork falls through to the pre-1.2.10 one-tier-per-rank ladder' );
	}

	public function test_a_migrated_fork_prices_the_same_as_a_reseeded_global(): void {
		$split = Seeder::split_stored_definition( 'vampire-disciplines', $this->flat_fork() );
		$this->assertNotNull( $split );

		$result = Cost_Engine::price_held_tiered_power( $this->decoded( $split ), [ 'name' => 'Animalism', 'level' => 5 ], true );

		$this->assertSame( 27, $result['xp'], '3+3+6+6+9 - the declared ladder, same as the global block' );
	}

	public function test_the_migration_splits_into_the_three_containers(): void {
		$split  = Seeder::split_stored_definition( 'vampire-disciplines', $this->flat_fork() );
		$family = $split['powers'][0];

		$this->assertCount( 5, $family['levels'], 'the ladder is exactly the declared ceiling' );
		$this->assertSame( [ 1, 2, 3, 4, 5 ], array_column( $family['levels'], 'level' ), 'rungs are renumbered 1..5' );
		$this->assertArrayHasKey( 'elder', $family );
		$this->assertSame( 'Animal Succulence', $family['elder']['elder'][0]['power_name'] );
		$this->assertSame( 'Stampede', $family['elder']['master'][0]['power_name'] );
	}

	/**
	 * The chronicle's own reason for forking must survive the migration untouched.
	 */
	public function test_a_chronicles_own_house_power_survives(): void {
		$split = Seeder::split_stored_definition( 'vampire-disciplines', $this->flat_fork() );

		$names = array_column( $split['powers'], 'name' );
		$this->assertContains( 'House Discipline', $names, 'a fork exists because it differs - the difference must be kept' );

		$house = $split['powers'][1];
		$this->assertSame( 'House One', $house['levels'][0]['power_name'] );
	}

	public function test_a_named_pick_on_a_migrated_fork_prices_correctly(): void {
		$split = Seeder::split_stored_definition( 'vampire-disciplines', $this->flat_fork() );

		$result = Cost_Engine::price_held_tiered_power( $this->decoded( $split ), [ 'name' => 'Animalism', 'power_name' => 'Stampede' ], true );

		$this->assertSame( 15, $result['xp'], 'Stampede is master, and must price as master on a fork too' );
	}

	/**
	 * Idempotent: an upgrade runs this every time, and a second run must change nothing.
	 */
	public function test_running_it_twice_is_a_no_op(): void {
		$once = Seeder::split_stored_definition( 'vampire-disciplines', $this->flat_fork() );
		$this->assertNotNull( $once );

		$twice = Seeder::split_stored_definition( 'vampire-disciplines', $once );
		$this->assertNull( $twice, 'an already-declared definition is left alone' );
	}

	public function test_a_definition_with_no_powers_is_left_alone(): void {
		$this->assertNull( Seeder::split_stored_definition( 'vampire-disciplines', [ 'items' => [] ] ) );
	}
}
