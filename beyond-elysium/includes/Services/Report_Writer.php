<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a `Report_Document::build()` array into TCPDF pages.
 */
class Report_Writer {

	private const MARGIN     = 15.0; // mm
	private const FONT       = 'dejavusans';
	private const TITLE_SIZE = 16;
	private const HEAD_SIZE  = 9;
	private const BODY_SIZE  = 8;

	private const CARD_WIDTH  = 127.0; // 5in
	private const CARD_HEIGHT = 76.2;  // 3in

	/**
	 * @param array<string,mixed> $document  `Report_Document::build()`'s return value.
	 * @param bool                $signed    False prints an unsigned copy, marked as one.
	 * @param string|null         $page_size `letter` or `a4`; anything else prints on the site's default.
	 * @throws \RuntimeException When asked to sign and signing is not configured or its files are unreadable.
	 */
	public static function write( array $document, object $game, bool $signed = true, ?string $page_size = null ): string {
		$pdf = new \TCPDF( 'P', 'mm', Pdf_Writer::tcpdf_format( $page_size ), true, 'UTF-8', false );
		if ( $signed ) {
			Pdf_Signer::configure( $pdf, $game );
		}

		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );
		$pdf->setCreator( 'Beyond Elysium ' . BE_VERSION );
		$pdf->setAuthor( 'Beyond Elysium' );
		$pdf->setMargins( self::MARGIN, self::MARGIN, self::MARGIN );
		$pdf->setAutoPageBreak( true, self::MARGIN );
		$pdf->setTitle( (string) ( $document['title'] ?? '' ) );
		$pdf->AddPage();

		self::draw_title( $pdf, $document );

		switch ( $document['shape'] ?? '' ) {
			case 'table':
				self::draw_table( $pdf, $document );
				break;
			case 'card':
				self::draw_cards( $pdf, $document );
				break;
			case 'statistics':
				self::draw_statistics( $pdf, $document );
				break;
			case 'narrative':
				self::draw_narrative( $pdf, $document );
				break;
			case 'calendar':
				self::draw_calendar( $pdf, $document );
				break;
			case 'house_rules':
				self::draw_house_rules( $pdf, $document );
				break;
			default:
				$pdf->Cell( 0, 6, sprintf( '[unknown report shape "%s"]', (string) ( $document['shape'] ?? '?' ) ), 0, 1 );
		}

		if ( ! $signed ) {
			Pdf_Signer::mark_unsigned( $pdf );
		}

		return $pdf->Output( '', 'S' );
	}

	/**
	 * @param array<string,mixed> $document
	 */
	private static function draw_title( \TCPDF $pdf, array $document ): void {
		$pdf->setFont( self::FONT, 'B', self::TITLE_SIZE );
		$pdf->Cell( 0, 8, (string) ( $document['title'] ?? '' ), 0, 1 );

		$pdf->setFont( self::FONT, '', self::HEAD_SIZE );
		$pdf->Cell( 0, 5, (string) ( $document['game'] ?? '' ) . ' · ' . current_time( 'Y-m-d' ), 0, 1 );
		$pdf->Ln( 3 );
	}

	/**
	 * A header row (repeated whenever a page break lands after it, via TCPDF's own `setAutoPageBreak` page-break check on
	 * `Cell()`).
	 *
	 * @param array<string,mixed> $document
	 */
	private static function draw_table( \TCPDF $pdf, array $document ): void {
		$columns = is_array( $document['columns'] ?? null ) ? $document['columns'] : [];
		$rows    = is_array( $document['rows'] ?? null ) ? $document['rows'] : [];

		if ( empty( $columns ) ) {
			return;
		}

		$usable_width = $pdf->getPageWidth() - 2 * self::MARGIN;
		$col_width    = $usable_width / count( $columns );

		self::draw_table_header( $pdf, $columns, $col_width );

		$pdf->setFont( self::FONT, '', self::BODY_SIZE );
		foreach ( $rows as $row ) {
			if ( $pdf->GetY() + 6 > $pdf->getPageHeight() - self::MARGIN ) {
				$pdf->AddPage();
				self::draw_table_header( $pdf, $columns, $col_width );
				$pdf->setFont( self::FONT, '', self::BODY_SIZE );
			}
			foreach ( $row as $i => $value ) {
				$pdf->Cell( $col_width, 6, (string) $value, 1 );
			}
			$pdf->Ln();
		}

		if ( empty( $rows ) ) {
			$pdf->setFont( self::FONT, 'I', self::BODY_SIZE );
			$pdf->Cell( 0, 6, __( 'No results.', 'beyond-elysium' ), 0, 1 );
		}
	}

	/**
	 * @param string[] $columns
	 */
	private static function draw_table_header( \TCPDF $pdf, array $columns, float $col_width ): void {
		$pdf->setFont( self::FONT, 'B', self::BODY_SIZE );
		foreach ( $columns as $label ) {
			$pdf->Cell( $col_width, 7, (string) $label, 1, 0, 'C', true );
		}
		$pdf->Ln();
	}

	/**
	 * Draws the card grid at Grapevine's 5in×3in card size: one card per row, as many rows as fit the page.
	 *
	 * @param array<string,mixed> $document
	 */
	private static function draw_cards( \TCPDF $pdf, array $document ): void {
		$cards = is_array( $document['cards'] ?? null ) ? $document['cards'] : [];
		if ( empty( $cards ) ) {
			$pdf->setFont( self::FONT, 'I', self::BODY_SIZE );
			$pdf->Cell( 0, 6, __( 'No results.', 'beyond-elysium' ), 0, 1 );
			return;
		}

		$page_bottom = $pdf->getPageHeight() - self::MARGIN;

		foreach ( $cards as $card ) {
			if ( $pdf->GetY() + self::CARD_HEIGHT > $page_bottom ) {
				$pdf->AddPage();
			}

			$x = self::MARGIN;
			$y = $pdf->GetY();
			$pdf->Rect( $x, $y, self::CARD_WIDTH, self::CARD_HEIGHT );

			$pdf->setXY( $x + 3, $y + 3 );
			$pdf->setFont( self::FONT, '', self::BODY_SIZE );
			$lines = [];
			foreach ( $card as [ $label, $value ] ) {
				$lines[] = $label . ': ' . $value;
			}
			$pdf->MultiCell( self::CARD_WIDTH - 6, 0, implode( "\n", $lines ), 0, 'L', false, 1, $x + 3, $y + 3 );

			$pdf->setXY( $x, $y + self::CARD_HEIGHT + 4 );
		}
	}

	/**
	 * @param array<string,mixed> $document
	 */
	private static function draw_statistics( \TCPDF $pdf, array $document ): void {
		$blocks = is_array( $document['blocks'] ?? null ) ? $document['blocks'] : [];
		if ( empty( $blocks ) ) {
			$pdf->setFont( self::FONT, 'I', self::BODY_SIZE );
			$pdf->Cell( 0, 6, __( 'No data.', 'beyond-elysium' ), 0, 1 );
			return;
		}

		$usable_width = $pdf->getPageWidth() - 2 * self::MARGIN;

		foreach ( $blocks as $block ) {
			$pdf->setFont( self::FONT, 'B', self::HEAD_SIZE + 1 );
			$pdf->Cell( 0, 7, ucfirst( (string) $block['label'] ) . ' (n=' . (string) $block['total'] . ')', 0, 1 );

			$pdf->setFont( self::FONT, '', self::BODY_SIZE );
			$buckets = is_array( $block['buckets'] ?? null ) ? $block['buckets'] : [];
			if ( empty( $buckets ) ) {
				$pdf->Cell( 0, 6, __( 'No data.', 'beyond-elysium' ), 0, 1 );
			}
			foreach ( $buckets as $bucket_label => $count ) {
				$pdf->Cell( 0.6 * $usable_width, 5, (string) $bucket_label, 0 );
				$pdf->Cell( 0.4 * $usable_width, 5, (string) $count, 0, 1, 'R' );
			}
			$pdf->Ln( 4 );
		}
	}

	/**
	 * Plot Report: one block per plot, prose.
	 *
	 * @param array<string,mixed> $document
	 */
	private static function draw_narrative( \TCPDF $pdf, array $document ): void {
		$blocks = is_array( $document['blocks'] ?? null ) ? $document['blocks'] : [];
		if ( empty( $blocks ) ) {
			$pdf->setFont( self::FONT, 'I', self::BODY_SIZE );
			$pdf->Cell( 0, 6, __( 'No plots.', 'beyond-elysium' ), 0, 1 );
			return;
		}

		foreach ( $blocks as $block ) {
			$pdf->setFont( self::FONT, 'B', self::HEAD_SIZE + 1 );
			$pdf->Cell( 0, 7, (string) $block['title'] . ' (' . (string) $block['status'] . ')', 0, 1 );

			$pdf->setFont( self::FONT, '', self::BODY_SIZE );
			foreach ( (array) $block['lines'] as $line ) {
				$pdf->MultiCell( 0, 0, (string) $line, 0, 'L' );
			}
			foreach ( (array) $block['entries'] as $entry_line ) {
				$pdf->MultiCell( 0, 0, '  · ' . (string) $entry_line, 0, 'L' );
			}
			$pdf->Ln( 4 );
		}
	}

	/**
	 * Game Calendar: always the empty state.
	 *
	 * @param array<string,mixed> $document
	 */
	private static function draw_calendar( \TCPDF $pdf, array $document ): void {
		$pdf->setFont( self::FONT, 'I', self::BODY_SIZE );
		$pdf->MultiCell( 0, 0, (string) ( $document['note'] ?? '' ), 0, 'L' );
	}

	/**
	 * The only shape that renders actual rich HTML (tables, lists, formatting).
	 *
	 * @param array<string,mixed> $document
	 */
	private static function draw_house_rules( \TCPDF $pdf, array $document ): void {
		$groups = is_array( $document['groups'] ?? null ) ? $document['groups'] : [];
		if ( empty( $groups ) ) {
			$pdf->setFont( self::FONT, 'I', self::BODY_SIZE );
			$pdf->Cell( 0, 6, __( 'No house rules are set on this catalog yet.', 'beyond-elysium' ), 0, 1 );
			return;
		}

		$section_labels = [
			'reference'   => __( 'Reference', 'beyond-elysium' ),
			'description' => __( 'Description', 'beyond-elysium' ),
			'source'      => __( 'Source', 'beyond-elysium' ),
		];

		foreach ( $groups as $group ) {
			$pdf->setFont( self::FONT, 'B', self::HEAD_SIZE + 1 );
			$pdf->Cell( 0, 7, (string) $group['block_name'], 0, 1 );

			foreach ( (array) $group['entries'] as $entry ) {
				$pdf->setFont( self::FONT, 'B', self::BODY_SIZE );
				$pdf->Cell( 0, 5, '  ' . (string) $entry['name'], 0, 1 );

				foreach ( (array) $entry['sections'] as $section_key => $html ) {
					if ( $html === '' || $html === null ) {
						continue;
					}
					$pdf->setFont( self::FONT, 'I', self::BODY_SIZE - 1 );
					$pdf->Cell( 0, 4, '    ' . ( $section_labels[ $section_key ] ?? $section_key ) . ':', 0, 1 );

					$pdf->setFont( self::FONT, '', self::BODY_SIZE );
					$pdf->setX( $pdf->getX() + 4 );
					$pdf->writeHTML( Rich_Text_Sanitizer::sanitize( (string) $html ), true, false, true, false, '' );
				}
				$pdf->Ln( 2 );
			}
			$pdf->Ln( 3 );
		}
	}
}
