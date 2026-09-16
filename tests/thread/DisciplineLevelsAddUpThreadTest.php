<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Cost_Engine;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-040. Owner ruling 2026-09-14: "levels add up" - raising a Discipline costs every
 * level passed through, so buying level 3 fresh costs levels 1, 2, and 3.
 *
 * Every numbered power ladder but Mage Spheres was seeded non-sequential, so a purchase cost only
 * the target rung's own price: a fresh Celerity 3 cost what level 3 costs, and Celerity 1 to 5 cost
 * level 5's price minus level 1's. Every numbered ladder now prices step by step; an Elder-and-above
 * pick keeps its own flat price.
 */
class DisciplineLevelsAddUpThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-levels-add-up';

	public function setUp(): void {
		parent::setUp();
		Game::create( [ 'slug' => $this->slug, 'name' => 'Levels Add Up' ] );
	}

	/** @return array<int,int> level => cost for one family of a real seeded block. */
	private function ladder( string $block, string $family ): array {
		foreach ( Schema_Block::find_by_slug( $block )->definition->powers as $power ) {
			if ( $power->name !== $family ) {
				continue;
			}
			$costs = [];
			foreach ( $power->levels as $level ) {
				if ( ( $level->level ?? null ) !== null && isset( $level->cost ) ) {
					$costs[ (int) $level->level ] = (int) $level->cost;
				}
			}
			return $costs;
		}
		return [];
	}

	private function brujah( array $disciplines = [] ): object {
		return Character::find( Character::create( [
			'name' => 'Rabble Rouser', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'sheet_data' => [ 'vampire-identity' => [ 'Clan' => 'Brujah' ], 'vampire-disciplines' => $disciplines ],
		] ) );
	}

	public function test_buying_a_discipline_level_fresh_costs_every_level_up_to_it(): void {
		$celerity = $this->ladder( 'vampire-disciplines', 'Celerity' );
		$this->assertArrayHasKey( 3, $celerity, 'Celerity must have a real priced level 3' );

		$cost = Cost_Engine::cost_for_change( $this->brujah(), [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-disciplines', 'trait' => [ 'name' => 'Celerity', 'level' => 3 ] ],
		] );

		$this->assertSame( $celerity[1] + $celerity[2] + $celerity[3], $cost );
	}

	public function test_raising_a_discipline_costs_each_level_passed_through(): void {
		$celerity = $this->ladder( 'vampire-disciplines', 'Celerity' );

		$cost = Cost_Engine::cost_for_change( $this->brujah( [ [ 'name' => 'Celerity', 'level' => 1 ] ] ), [
			'change_type' => 'modify_trait',
			'change_data' => [ 'block_slug' => 'vampire-disciplines', 'trait' => [ 'name' => 'Celerity', 'level' => 5 ] ],
		] );

		$this->assertSame( $celerity[2] + $celerity[3] + $celerity[4] + $celerity[5], $cost );
	}

	public function test_every_numbered_power_ladder_adds_up(): void {
		foreach ( [ 'vampire-disciplines', 'vampire-blood-magic', 'kueijin-disciplines', 'changeling-arts', 'changeling-realms', 'wraith-arcanoi', 'mummy-hekau', 'mortal-numina', 'mage-spheres' ] as $slug ) {
			$this->assertTrue( ! empty( Schema_Block::find_by_slug( $slug )->definition->sequential ), $slug );
		}
	}

	public function test_a_chronicles_copy_of_a_power_block_adds_up_too(): void {
		Schema_Block::find_or_create_fork_for_game( 'vampire-disciplines', $this->slug );
		$fork       = Schema_Block::find_for_game( 'vampire-disciplines', $this->slug );
		$definition = json_decode( wp_json_encode( $fork->definition ), true );
		$definition['sequential'] = false; // What every copy made before this release inherited.
		Schema_Block::update( 'vampire-disciplines', [ 'definition' => $definition ], $this->slug );
		delete_option( 'be_power_ladders_cumulative' );

		Schema::make_power_ladders_cumulative();

		$this->assertTrue( ! empty( Schema_Block::find_for_game( 'vampire-disciplines', $this->slug )->definition->sequential ) );
	}
}
