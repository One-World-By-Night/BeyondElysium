<?php

namespace BeyondElysium\Services\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Grid-layout math for rendering a template's sections: the shared 6-unit track count,
 * each section's column span by its declared width, and the row-flow reading order
 * sections should be laid out in. Exact PHP twin of `src/lib/templateLayout.ts`, kept in
 * parity by having both languages read the same fixture; see
 * tests/unit/Display/Layout_FlowTest.php and src/lib/templateLayout.test.ts.
 *
 * This class only computes spans and flow order, not a PDF layout itself - the signed-PDF
 * writer that calls it maps the 6-unit row to a 3-column grid at `third`, matching what the
 * on-screen character sheet already renders and what every shipped Grapevine HTML character
 * sheet template uses.
 *
 * @see BE_PROCESS/design/signed-pdf-design.md Section 3b, SP-3
 */
class Layout_Flow {

	/** Grid track count for the layout grid; divides evenly into thirds and halves. */
	public const LAYOUT_GRID_UNITS = 6;

	/** Column span for each named section width, out of LAYOUT_GRID_UNITS. */
	private const SPAN = [
		'third' => 2,
		'half'  => 3,
		'full'  => 6,
	];

	/**
	 * Returns the number of grid columns a section should span, based on its declared
	 * `width` of 'third', 'half', or 'full'. A section with no width set - null, not just
	 * a missing array key - or any other width defaults to 'third': a stored layout with
	 * a width outside the three failed signed-sheet generation outright for every
	 * character on its stack (1.0.0-review F-077).
	 *
	 * @param string|null $width
	 * @return int
	 */
	public static function span_for( ?string $width ): int {
		return self::SPAN[ (string) $width ] ?? self::SPAN['third'];
	}

	/**
	 * Returns a copy of $sections sorted into row-flow reading order, first by `column`
	 * and then by `order` within each column. This is the sequence a CSS grid auto-flow
	 * lays the sections into rows from on screen, and the sequence the PDF writer places
	 * sections into rows from on paper.
	 *
	 * Stability is explicit via an index tiebreaker rather than assumed from the runtime:
	 * two sections with equal column and order keep their original relative order. This
	 * matches the stability of JavaScript's Array.prototype.sort - used by this class's
	 * TypeScript twin, sortedForFlow() in templateLayout.ts - without depending on PHP's
	 * own sort stability guarantee (usort() is only stable as of PHP 8.0).
	 *
	 * @param array<int, array<string, mixed>> $sections
	 * @return array<int, array<string, mixed>>
	 */
	public static function sorted_for_flow( array $sections ): array {
		$decorated = [];
		foreach ( array_values( $sections ) as $index => $section ) {
			$decorated[] = [ $index, $section ];
		}

		usort(
			$decorated,
			static function ( array $a, array $b ): int {
				[ $index_a, $section_a ] = $a;
				[ $index_b, $section_b ] = $b;

				$column = ( $section_a['column'] ?? 0 ) <=> ( $section_b['column'] ?? 0 );
				if ( 0 !== $column ) {
					return $column;
				}

				$order = ( $section_a['order'] ?? 0 ) <=> ( $section_b['order'] ?? 0 );
				if ( 0 !== $order ) {
					return $order;
				}

				// Explicit index tiebreaker: guarantees stability for two sections tied on
				// (column, order) regardless of which PHP version runs this code.
				return $index_a <=> $index_b;
			}
		);

		return array_map(
			static function ( array $pair ) {
				return $pair[1];
			},
			$decorated
		);
	}
}
