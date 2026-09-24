<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Sheet_Document;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A `count_is_cost` trait_list block (Combo Disciplines) stores a flat XP price in the same field an ordinary block
 * stores a rating.
 */
class SheetDocumentCostNumbersTest extends TestCase {

	private function sections( array $sections, array $blocks, array $sheet_data, array $options = [] ): array {
		$method = new ReflectionMethod( Sheet_Document::class, 'build_sections' );
		$method->setAccessible( true );
		return $method->invoke( null, $sections, $blocks, $sheet_data, $options );
	}

	/** @param string|null $display The block's own display default, as a real seeded block carries it. */
	private function combo_block( bool $count_is_cost, ?string $display = null ): array {
		return [
			'vampire-combo-disciplines' => (object) [
				'section_type' => 'trait_list',
				'definition'   => (object) [ 'items' => [], 'atomic' => false, 'count_is_cost' => $count_is_cost, 'display' => $display ],
			],
		];
	}

	/** @return array<int,array<string,mixed>> */
	private function combo_sheet(): array {
		return [ 'vampire-combo-disciplines' => [ [ 'name' => 'Draw Fire', 'total' => 12 ] ] ];
	}

	/** @return array<int,array<string,mixed>> */
	private function layout(): array {
		return [ [ 'block_slug' => 'vampire-combo-disciplines', 'title' => 'Combo Disciplines' ] ];
	}

	/**
	 * No option passed at all.
	 */
	public function test_a_flagged_block_labels_its_price_with_no_option_passed(): void {
		$entry = $this->sections( $this->layout(), $this->combo_block( true ), $this->combo_sheet() )[0];

		$this->assertSame( [ [ 'label' => null, 'rows' => [ 'Draw Fire (12 XP)' ] ] ], $entry['groups'] );
	}

	/**
	 * An explicitly configured rating display loses to the price.
	 */
	public function test_a_flagged_block_ignores_a_configured_rating_display(): void {
		$entry = $this->sections( $this->layout(), $this->combo_block( true, 'dot' ), $this->combo_sheet() )[0];

		$this->assertSame( [ [ 'label' => null, 'rows' => [ 'Draw Fire (12 XP)' ] ] ], $entry['groups'] );
	}

	public function test_show_cost_on_is_the_same_as_the_default(): void {
		$entry = $this->sections( $this->layout(), $this->combo_block( true ), $this->combo_sheet(), [ 'show_cost' => true ] )[0];

		$this->assertSame( [ [ 'label' => null, 'rows' => [ 'Draw Fire (12 XP)' ] ] ], $entry['groups'] );
	}

	/**
	 * Hiding the price drops the number entirely.
	 */
	public function test_show_cost_off_hides_the_price_and_keeps_the_note(): void {
		$sheet_data = [ 'vampire-combo-disciplines' => [ [ 'name' => 'Draw Fire', 'total' => 12, 'note' => 'Tremere' ] ] ];

		$entry = $this->sections( $this->layout(), $this->combo_block( true, 'dot' ), $sheet_data, [ 'show_cost' => false ] )[0];

		$this->assertSame( [ [ 'label' => null, 'rows' => [ 'Draw Fire (Tremere)' ] ] ], $entry['groups'] );
	}

	/**
	 * An ordinary block is untouched by any of this, whichever way the option is set.
	 */
	public function test_an_unflagged_block_is_unaffected(): void {
		$entry = $this->sections( $this->layout(), $this->combo_block( false, 'dot' ), $this->combo_sheet(), [ 'show_cost' => false ] )[0];

		$this->assertSame( [ [ 'label' => null, 'rows' => [ 'Draw Fire ●●●●●●●●●●●● 12' ] ] ], $entry['groups'] );
	}
}
