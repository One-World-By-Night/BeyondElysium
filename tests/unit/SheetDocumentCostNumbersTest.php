<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Sheet_Document;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 1.1.0 D3: a count_is_cost trait_list block (Combo Disciplines) draws its held
 * entries' stored count as "N XP" instead of dots when the `cost_numbers` document
 * option is on - proving the option actually reaches `Trait_Display.php` through
 * `Sheet_Document::build_sections()`, not just the mode-switch logic in isolation.
 */
class SheetDocumentCostNumbersTest extends TestCase {

	private function sections( array $sections, array $blocks, array $sheet_data, array $options = [] ): array {
		$method = new ReflectionMethod( Sheet_Document::class, 'build_sections' );
		$method->setAccessible( true );
		return $method->invoke( null, $sections, $blocks, $sheet_data, $options );
	}

	private function combo_block( bool $count_is_cost ): array {
		return [
			'vampire-combo-disciplines' => (object) [
				'section_type' => 'trait_list',
				'definition'   => (object) [ 'items' => [], 'atomic' => false, 'count_is_cost' => $count_is_cost ],
			],
		];
	}

	public function test_cost_numbers_on_a_flagged_block_reads_as_a_number(): void {
		$sections   = [ [ 'block_slug' => 'vampire-combo-disciplines', 'title' => 'Combo Disciplines' ] ];
		$sheet_data = [ 'vampire-combo-disciplines' => [ [ 'name' => 'Bestial Charm', 'total' => 6 ] ] ];

		$entry = $this->sections( $sections, $this->combo_block( true ), $sheet_data, [ 'cost_numbers' => true ] )[0];

		$this->assertSame( [ [ 'label' => null, 'rows' => [ 'Bestial Charm 6 XP' ] ] ], $entry['groups'] );
	}

	public function test_cost_numbers_off_renders_as_it_always_has(): void {
		$sections   = [ [ 'block_slug' => 'vampire-combo-disciplines', 'title' => 'Combo Disciplines' ] ];
		$sheet_data = [ 'vampire-combo-disciplines' => [ [ 'name' => 'Bestial Charm', 'total' => 6 ] ] ];

		$entry = $this->sections( $sections, $this->combo_block( true ), $sheet_data, [ 'cost_numbers' => false ] )[0];

		$this->assertSame( [ [ 'label' => null, 'rows' => [ 'Bestial Charm' ] ] ], $entry['groups'] );
	}

	public function test_cost_numbers_on_an_unflagged_block_is_ignored(): void {
		$sections   = [ [ 'block_slug' => 'vampire-combo-disciplines', 'title' => 'Combo Disciplines' ] ];
		$sheet_data = [ 'vampire-combo-disciplines' => [ [ 'name' => 'Bestial Charm', 'total' => 6 ] ] ];

		$entry = $this->sections( $sections, $this->combo_block( false ), $sheet_data, [ 'cost_numbers' => true ] )[0];

		$this->assertSame( [ [ 'label' => null, 'rows' => [ 'Bestial Charm' ] ] ], $entry['groups'] );
	}
}
