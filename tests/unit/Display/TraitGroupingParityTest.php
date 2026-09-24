<?php

namespace BeyondElysium\Tests\Unit\Display;

use BeyondElysium\Services\Display\Trait_Grouping;
use PHPUnit\Framework\TestCase;

/**
 * `Trait_Grouping.php` (authoritative) and its TypeScript twins.
 */
class TraitGroupingParityTest extends TestCase {

	private function fixture( string $name ) {
		$path = BE_PLUGIN_ROOT . '/tests/fixtures/' . $name;
		return json_decode( file_get_contents( $path ) );
	}

	private function rows( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		return array_map( static fn( $item ) => is_object( $item ) ? (array) $item : $item, $value );
	}

	/**
	 * Normalizes an expected fixture value to the plain-array shape every method under test returns.
	 */
	private function expected_value( $expected ) {
		return json_decode( json_encode( $expected ), true );
	}

	public function test_to_traits_reads_count_falling_back_to_total(): void {
		$input    = $this->fixture( 'trait-grouping-input.json' );
		$expected = $this->fixture( 'trait-grouping-expected.json' );

		foreach ( $input->toTraits as $i => $case ) {
			$actual = Trait_Grouping::to_traits( $this->rows( $case->data ) );
			$this->assertSame(
				$this->expected_value( $expected->toTraits[ $i ] ),
				$actual,
				'toTraits case: ' . $case->case
			);
		}
	}

	public function test_group_traits_by_field_matches_the_shared_fixture(): void {
		$input    = $this->fixture( 'trait-grouping-input.json' );
		$expected = $this->fixture( 'trait-grouping-expected.json' );

		foreach ( $input->groupTraitsByField as $i => $case ) {
			$actual = Trait_Grouping::group_traits_by_field( $this->rows( $case->data ), $case->definition );
			$this->assertSame(
				$this->expected_value( $expected->groupTraitsByField[ $i ] ),
				$actual,
				'groupTraitsByField case: ' . $case->case
			);
		}
	}

	public function test_resolve_display_matches_the_shared_fixture(): void {
		$input    = $this->fixture( 'trait-grouping-input.json' );
		$expected = $this->fixture( 'trait-grouping-expected.json' );

		foreach ( $input->resolveDisplay as $i => $case ) {
			$actual = Trait_Grouping::resolve_display( $case->sectionDisplay, $case->blockDisplay );
			$this->assertSame( $expected->resolveDisplay[ $i ], $actual, 'resolveDisplay case: ' . $case->case );
		}
	}

	/**
	 * A `count_is_cost` block's number is a price.
	 */
	public function test_resolve_mode_matches_the_shared_fixture(): void {
		$input    = $this->fixture( 'trait-grouping-input.json' );
		$expected = $this->fixture( 'trait-grouping-expected.json' );

		foreach ( $input->resolveMode as $i => $case ) {
			$actual = Trait_Grouping::resolve_mode( $case->definition, $case->sectionDisplay, $case->showCost );
			$this->assertSame( $expected->resolveMode[ $i ], $actual, 'resolveMode case: ' . $case->case );
		}
	}

	public function test_group_by_category_matches_the_shared_fixture(): void {
		$input    = $this->fixture( 'trait-grouping-input.json' );
		$expected = $this->fixture( 'trait-grouping-expected.json' );

		foreach ( $input->groupByCategory as $i => $case ) {
			$actual = Trait_Grouping::group_by_category( $this->rows( $case->data ), $case->definition );
			$this->assertSame(
				$this->expected_value( $expected->groupByCategory[ $i ] ),
				$actual,
				'groupByCategory case: ' . $case->case
			);
		}
	}

	public function test_sort_if_alphabetized_matches_the_shared_fixture(): void {
		$input    = $this->fixture( 'trait-grouping-input.json' );
		$expected = $this->fixture( 'trait-grouping-expected.json' );

		foreach ( $input->sortIfAlphabetized as $i => $case ) {
			$actual = Trait_Grouping::sort_if_alphabetized( $this->rows( $case->traits ), $case->alphabetize ?? false );
			$this->assertSame(
				$this->expected_value( $expected->sortIfAlphabetized[ $i ] ),
				$actual,
				'sortIfAlphabetized case: ' . $case->case
			);
		}
	}

	/**
	 * `section_total()` and its TypeScript twin `sectionTotal()` (`src/lib/sectionTotal.test.ts` proves the other side)
	 * must agree.
	 */
	public function test_section_total_matches_the_shared_fixture(): void {
		$input    = $this->fixture( 'trait-grouping-input.json' );
		$expected = $this->fixture( 'trait-grouping-expected.json' );

		foreach ( $input->sectionTotal as $i => $case ) {
			$actual = Trait_Grouping::section_total( $this->rows( $case->traits ) );
			$this->assertSame( $expected->sectionTotal[ $i ], $actual, 'sectionTotal case: ' . $case->case );
		}
	}
}
