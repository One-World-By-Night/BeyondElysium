<?php

namespace BeyondElysium\Services\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Grid-layout math for rendering a template's sections: the shared 6-unit track count, each section's column span by
 * its declared width, and the row-flow reading order sections should be laid out in.
 */
class Layout_Flow {

	/**
	 * Grid track count for the layout grid.
	 */
	public const LAYOUT_GRID_UNITS = 6;

	/**
	 * Column span for each named section width, out of LAYOUT_GRID_UNITS.
	 */
	private const SPAN = [
		'third' => 2,
		'half'  => 3,
		'full'  => 6,
	];

	/**
	 * Returns the number of grid columns a section should span, based on its declared `width` of 'third', 'half', or
	 * 'full'.
	 *
	 * @param string|null $width
	 * @return int
	 */
	public static function span_for( ?string $width ): int {
		return self::SPAN[ (string) $width ] ?? self::SPAN['third'];
	}

	/**
	 * Returns a copy of $sections sorted into row-flow reading order, first by `column` and then by `order` within each
	 * column.
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
