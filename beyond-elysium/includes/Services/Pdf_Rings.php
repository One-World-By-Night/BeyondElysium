<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Draws a row of empty circular rings on a TCPDF page - a rated trait's dots, or a resource pool's points in groups
 * of five. Shared by the character sheet (`Pdf_Writer`) and reports (`Report_Writer`) so both draw a rating
 * identically.
 */
class Pdf_Rings {

	const RING_SIZE      = 2.6; // mm across
	const RING_STEP      = 3.1; // mm from one ring to the next
	const RING_STROKE    = 0.15;
	const RING_GREY      = 120; // 0 is black, 255 white
	const RING_ROW       = 5;   // rings to a row beside a trait
	const RING_GROUP_GAP = 1.2; // between groups of five in a pool's rings

	/**
	 * How many rows of rings `$count` rings take, `$per_row` to a row.
	 */
	public static function rows( int $count, int $per_row ): int {
		return max( 1, intdiv( max( 0, $count ) + $per_row - 1, $per_row ) );
	}

	/**
	 * Draws `$count` empty rings from `$top` down, `$per_row` to a row, with a gap after every fifth ring when `$grouped`.
	 *
	 * @return float The bottom edge of the last row.
	 */
	public static function rings( \TCPDF $pdf, int $count, float $x, float $top, int $per_row, bool $grouped, int $limit ): float {
		$count = min( max( 0, $count ), $limit );
		$pdf->setLineWidth( self::RING_STROKE );
		$pdf->setDrawColor( self::RING_GREY );
		for ( $i = 0; $i < $count; $i++ ) {
			$column = $i % $per_row;
			$left   = $x + $column * self::RING_STEP + ( $grouped ? intdiv( $column, 5 ) * self::RING_GROUP_GAP : 0.0 );
			$pdf->Circle(
				$left + self::RING_SIZE / 2,
				$top + intdiv( $i, $per_row ) * self::RING_STEP + self::RING_SIZE / 2,
				self::RING_SIZE / 2,
				0,
				360,
				'D',
				[],
				[],
				2
			);
		}
		$pdf->setDrawColor( 0 );
		return $top + ( self::rows( $count, $per_row ) - 1 ) * self::RING_STEP + self::RING_SIZE;
	}

	/**
	 * A pool's rings in groups of five, as many whole groups to a line as fit the width.
	 *
	 * @return float The bottom edge of the last row.
	 */
	public static function pool_rings( \TCPDF $pdf, int $count, float $x, float $top, float $width, int $limit ): float {
		$group  = self::RING_ROW * self::RING_STEP;
		$groups = max( 1, (int) floor( ( $width + self::RING_GROUP_GAP ) / ( $group + self::RING_GROUP_GAP ) ) );
		return self::rings( $pdf, $count, $x, $top, $groups * self::RING_ROW, true, $limit );
	}

	/**
	 * Draws `$count` small square boxes from `$x`, left to right, crossing out the first `$used` of them with a
	 * diagonal line. An item card's uses: `uses_max` boxes, `uses_max - uses_left` crossed out.
	 *
	 * @return float The right edge of the last box.
	 */
	public static function use_boxes( \TCPDF $pdf, int $count, int $used, float $x, float $top, int $per_row = 5 ): float {
		$count = max( 0, $count );
		$used  = min( max( 0, $used ), $count );
		$pdf->setLineWidth( self::RING_STROKE );
		$pdf->setDrawColor( self::RING_GREY );

		$right = $x;
		for ( $i = 0; $i < $count; $i++ ) {
			$column = $i % $per_row;
			$row    = intdiv( $i, $per_row );
			$left   = $x + $column * self::RING_STEP;
			$box_top = $top + $row * self::RING_STEP;
			$pdf->Rect( $left, $box_top, self::RING_SIZE, self::RING_SIZE, 'D' );
			if ( $i < $used ) {
				$pdf->Line( $left, $box_top, $left + self::RING_SIZE, $box_top + self::RING_SIZE );
				$pdf->Line( $left, $box_top + self::RING_SIZE, $left + self::RING_SIZE, $box_top );
			}
			$right = $left + self::RING_SIZE;
		}
		$pdf->setDrawColor( 0 );
		return $right;
	}
}
