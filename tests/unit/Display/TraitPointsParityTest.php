<?php

namespace BeyondElysium\Tests\Unit\Display;

use BeyondElysium\Services\Display\Trait_Grouping;
use PHPUnit\Framework\TestCase;

/**
 * `Trait_Grouping::points_for()` and `pointsFor()` in `BlockRenderer.tsx` agree on every case in the shared fixture.
 */
class TraitPointsParityTest extends TestCase {

	public function test_points_for_matches_every_shared_fixture_case(): void {
		$cases = json_decode( (string) file_get_contents( BE_PLUGIN_ROOT . '/tests/fixtures/trait-points.json' ), true );

		foreach ( $cases as $case ) {
			$item = $case['item_cost'] === null ? null : (object) [ 'cost' => $case['item_cost'] ];
			$this->assertSame( $case['points'], Trait_Grouping::points_for( $case['entry'], $item ), $case['name'] );
		}
	}

	public function test_point_traits_carry_each_entrys_points_as_its_total(): void {
		$definition = (object) [
			'items' => [
				(object) [ 'name' => 'Daredevil', 'cost' => '3' ],
				(object) [ 'name' => 'Ambidextrous', 'cost' => '1' ],
			],
		];
		$data = [
			[ 'name' => 'Daredevil', 'count' => 1 ],
			[ 'name' => 'Alternate Sense', 'count' => 14, 'note' => 'Radar' ],
			[ 'name' => 'Ambidextrous' ],
		];

		$this->assertSame(
			[
				[ 'name' => 'Daredevil', 'total' => 3, 'note' => null ],
				[ 'name' => 'Alternate Sense', 'total' => 14, 'note' => 'Radar' ],
				[ 'name' => 'Ambidextrous', 'total' => 1, 'note' => null ],
			],
			Trait_Grouping::to_point_traits( $data, $definition )
		);
	}

	public function test_point_traits_of_nothing_are_empty(): void {
		$this->assertSame( [], Trait_Grouping::to_point_traits( null, (object) [ 'items' => [] ] ) );
	}
}
