<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Point_Audit;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 1.2.11 D94, the third place a `count_is_cost` block's stored number was read as a
 * quantity. The Point Audit labelled every held row `{name} ×{count}` whenever the count
 * was above one, so a Combo Discipline costing 8 XP printed as `Emerge Unscathed ×8` -
 * eight copies of a power a character holds once. Unlike the sheet and the PDF this was
 * never gated behind a viewer preference; it simply had no idea the number was a price.
 */
class PointAuditCostLabelTest extends TestCase {

	/** @return array<int,array<string,mixed>> */
	private function lines( array $definition, array $held ): array {
		$method = new ReflectionMethod( Point_Audit::class, 'trait_list_lines' );
		$method->setAccessible( true );
		return $method->invoke(
			null,
			(object) $definition,
			[ 'slug' => 'vampire-combo-disciplines', 'label' => 'Combo Disciplines', 'undeclared' => false ],
			$held
		);
	}

	public function test_a_priced_row_reads_as_a_price_not_a_quantity(): void {
		$lines = $this->lines(
			[ 'items' => [], 'count_is_cost' => true ],
			[ [ 'name' => 'Emerge Unscathed', 'count' => 8 ] ]
		);

		$this->assertSame( 'Emerge Unscathed (8 XP)', $lines[0]['label'] );
	}

	/** A one-point price is still a price - the `×N` rule hid it entirely below two. */
	public function test_a_price_of_one_is_still_labelled(): void {
		$lines = $this->lines(
			[ 'items' => [], 'count_is_cost' => true ],
			[ [ 'name' => 'Cheap Combo', 'count' => 1 ] ]
		);

		$this->assertSame( 'Cheap Combo (1 XP)', $lines[0]['label'] );
	}

	public function test_an_ordinary_block_still_reads_as_a_quantity(): void {
		$lines = $this->lines(
			[ 'items' => [] ],
			[ [ 'name' => 'Retainers', 'count' => 3 ], [ 'name' => 'Iron Will', 'count' => 1 ] ]
		);

		$this->assertSame( 'Retainers ×3', $lines[0]['label'] );
		$this->assertSame( 'Iron Will', $lines[1]['label'] );
	}
}
