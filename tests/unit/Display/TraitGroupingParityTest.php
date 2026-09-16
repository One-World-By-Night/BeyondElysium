<?php

namespace BeyondElysium\Tests\Unit\Display;

use BeyondElysium\Services\Display\Trait_Grouping;
use PHPUnit\Framework\TestCase;

/**
 * `Trait_Grouping.php` (authoritative) and its TypeScript twins - `groupTraitsByField.ts`,
 * the pure helpers in `TraitListRenderer.tsx`, and `BlockRenderer.tsx`'s `toTraits()` -
 * must agree - same input in, same output out. Both sides read the same fixture pair and
 * are checked against the same expected output; this half proves the PHP side. See
 * `src/lib/groupTraitsByField.test.ts`, `src/components/renderers/TraitListRenderer.test.ts`,
 * and `src/components/renderers/BlockRenderer.test.ts` for the TypeScript side.
 *
 * @see BE_PROCESS/design/signed-pdf-design.md Section 2d, Section 3, SP-3
 */
class TraitGroupingParityTest extends TestCase {

	private function fixture( string $name ) {
		$path = BE_PLUGIN_ROOT . '/tests/fixtures/' . $name;
		return json_decode( file_get_contents( $path ) );
	}

	/**
	 * Converts a fixture's array-of-stdClass trait rows (the default `json_decode()`
	 * shape, used throughout this fixture so a `definition`'s catalog stays object-typed)
	 * into this class's array-of-array row shape, matching how real `sheet_data` is
	 * actually decoded in production (`json_decode( $json, true )` - confirmed against
	 * Character.php, Query_Engine.php, and Schema.php). A non-array value (null, or an
	 * empty JSON object) passes through unchanged so it's the method under test's own
	 * input validation being exercised, not this helper's.
	 */
	private function rows( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		return array_map( static fn( $item ) => is_object( $item ) ? (array) $item : $item, $value );
	}

	/** Normalizes an expected fixture value to the plain-array shape every method under test returns. */
	private function expected_value( $expected ) {
		return json_decode( json_encode( $expected ), true );
	}

	/**
	 * The single highest-value assertion in the whole signed-PDF porting effort (see
	 * signed-pdf-design.md Section 2d and SP-3): Defect D25 was exactly this class of bug
	 * - `count`/`total` drift between the write path and the read path silently zeroed
	 * every counted trait on every character sheet for three phases. This asserts
	 * `to_traits()` reads `total` first, falls back to `count` only when `total` is
	 * absent, and confirms which one wins when a raw entry somehow carries both.
	 */
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
}
