<?php

namespace BeyondElysium\Tests\Unit\Display;

use BeyondElysium\Services\Display\Layout_Flow;
use PHPUnit\Framework\TestCase;

/**
 * `Layout_Flow.php` (authoritative) and `templateLayout.ts` (the on-screen sheet's own
 * grid math) must agree - same width or section list in, same span or flow order out. Both
 * read the same fixture and are checked against the same expected output; this half proves
 * the PHP side, `src/lib/templateLayout.test.ts` proves the TypeScript side.
 *
 * The first six cases below are `templateLayout.test.ts`'s own existing named cases,
 * transcribed 1:1 rather than only left to the fixture loop; the stability test and the
 * fixture-driven parity tests are this port's own required additions - see
 * signed-pdf-design.md's Test Plan (`Display\Layout_FlowTest.php` - `spanFor(null) === 2`;
 * `sortedForFlow()` stable within equal `(column, order)`).
 *
 * @see BE_PROCESS/signed-pdf-design.md Section 3b, SP-3
 */
class Layout_FlowTest extends TestCase {

	private function fixture( string $name ) {
		$path = BE_PLUGIN_ROOT . '/tests/fixtures/' . $name;
		return json_decode( file_get_contents( $path ) );
	}

	// -- Transcribed 1:1 from templateLayout.test.ts ---------------------------------

	/**
	 * Every template authored before the width field existed has no width at all - on
	 * the PHP side that arrives as null, never an unset array key. This is also the
	 * single named assertion signed-pdf-design.md's Test Plan calls out explicitly:
	 * `spanFor(null) === 2`.
	 */
	public function test_span_for_defaults_to_third_two_of_six_when_width_is_unset(): void {
		$this->assertSame( 2, Layout_Flow::span_for( null ) );
	}

	public function test_three_thirds_fill_exactly_one_row(): void {
		$this->assertSame( Layout_Flow::LAYOUT_GRID_UNITS, Layout_Flow::span_for( 'third' ) * 3 );
	}

	public function test_two_halves_fill_exactly_one_row(): void {
		$this->assertSame( Layout_Flow::LAYOUT_GRID_UNITS, Layout_Flow::span_for( 'half' ) * 2 );
	}

	public function test_full_takes_the_whole_row(): void {
		$this->assertSame( Layout_Flow::LAYOUT_GRID_UNITS, Layout_Flow::span_for( 'full' ) );
	}

	public function test_sorts_by_column_then_order_matching_real_row_reading_order(): void {
		$sections = [
			[ 'block_slug' => 'c', 'column' => 1, 'order' => 2, 'title' => 'C', 'display' => null, 'collapsed' => false ],
			[ 'block_slug' => 'a', 'column' => 1, 'order' => 1, 'title' => 'A', 'display' => null, 'collapsed' => false ],
			[ 'block_slug' => 'b', 'column' => 2, 'order' => 1, 'title' => 'B', 'display' => null, 'collapsed' => false ],
		];

		$flowed = Layout_Flow::sorted_for_flow( $sections );

		$this->assertSame( [ 'a', 'c', 'b' ], array_column( $flowed, 'block_slug' ) );
	}

	public function test_does_not_mutate_the_input_array(): void {
		$sections = [
			[ 'block_slug' => 'b', 'column' => 1, 'order' => 2, 'title' => 'B', 'display' => null, 'collapsed' => false ],
			[ 'block_slug' => 'a', 'column' => 1, 'order' => 1, 'title' => 'A', 'display' => null, 'collapsed' => false ],
		];
		$original = $sections;

		Layout_Flow::sorted_for_flow( $sections );

		$this->assertSame( $original, $sections );
	}

	// -- This port's own required addition: sortedForFlow() stability ----------------

	/**
	 * The other named assertion from signed-pdf-design.md's Test Plan: sortedForFlow()
	 * must be stable within equal (column, order). PHP's own usort() is only guaranteed
	 * stable as of PHP 8.0, and Layout_Flow::sorted_for_flow() does not rely on that
	 * guarantee anyway - it breaks ties with an explicit original-index comparison. This
	 * test proves the observable behavior a caller actually depends on, independent of
	 * how the implementation gets there.
	 */
	public function test_sorted_for_flow_is_stable_for_equal_column_and_order(): void {
		$sections = [
			[ 'block_slug' => 'first', 'column' => 1, 'order' => 1, 'title' => 'First', 'display' => null, 'collapsed' => false ],
			[ 'block_slug' => 'second', 'column' => 1, 'order' => 1, 'title' => 'Second', 'display' => null, 'collapsed' => false ],
			[ 'block_slug' => 'third-entry', 'column' => 1, 'order' => 1, 'title' => 'Third', 'display' => null, 'collapsed' => false ],
		];

		$flowed = Layout_Flow::sorted_for_flow( $sections );

		$this->assertSame(
			[ 'first', 'second', 'third-entry' ],
			array_column( $flowed, 'block_slug' ),
			'sections tied on (column, order) must keep their original relative order'
		);
	}

	// -- Shared-fixture parity, read by both this file and templateLayout.test.ts ----

	public function test_span_for_matches_the_shared_fixture_for_every_case(): void {
		$input    = $this->fixture( 'layout-flow-input.json' );
		$expected = $this->fixture( 'layout-flow-expected.json' );

		$this->assertSame(
			count( $expected->spanFor ),
			count( $input->spanFor ),
			'input/expected spanFor fixture case count must match'
		);

		foreach ( $input->spanFor as $i => $case ) {
			$this->assertSame(
				$expected->spanFor[ $i ]->span,
				Layout_Flow::span_for( $case->width ),
				sprintf( 'case "%s"', $case->name )
			);
		}
	}

	public function test_sorted_for_flow_matches_the_shared_fixture_for_every_case(): void {
		$input    = $this->fixture( 'layout-flow-input.json' );
		$expected = $this->fixture( 'layout-flow-expected.json' );

		$this->assertSame(
			count( $expected->sortedForFlow ),
			count( $input->sortedForFlow ),
			'input/expected sortedForFlow fixture case count must match'
		);

		foreach ( $input->sortedForFlow as $i => $case ) {
			$sections = array_map(
				static function ( $section ): array {
					return (array) $section;
				},
				(array) $case->sections
			);

			$flowed = Layout_Flow::sorted_for_flow( $sections );

			$this->assertSame(
				$expected->sortedForFlow[ $i ]->block_slugs,
				array_column( $flowed, 'block_slug' ),
				sprintf( 'case "%s"', $case->name )
			);
		}
	}
}
