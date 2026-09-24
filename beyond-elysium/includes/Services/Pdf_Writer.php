<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Turns `Sheet_Document::for_characters()`'s arrays into TCPDF pages.
 */
class Pdf_Writer {

	private const MARGIN               = 15.0; // mm
	private const GRID_TRACKS          = 6;
	private const ROW_GAP              = 4.0;
	private const SECTION_CELL_PADDING = 2.0;
	private const FONT                 = 'dejavusans';
	private const TITLE_SIZE           = 16;
	private const SECTION_TITLE_SIZE   = 11;
	private const BODY_SIZE            = 9;
	private const FOOTNOTE_SIZE        = 7;

	/**
	 * The tags `writeHTML()` renders acceptably.
	 */
	private const PROSE_ALLOWED_TAGS = [
		'p'          => [],
		'br'         => [],
		'strong'     => [],
		'b'          => [],
		'em'         => [],
		'i'          => [],
		'u'          => [],
		'ul'         => [],
		'ol'         => [],
		'li'         => [],
		'h1'         => [],
		'h2'         => [],
		'h3'         => [],
		'h4'         => [],
		'h5'         => [],
		'h6'         => [],
		'blockquote' => [],
		'a'          => [ 'href' => true, 'title' => true ],
	];

	/**
	 * Signed unless the caller says.
	 *
	 * @param array<int,array<string,mixed>> $documents One entry per `Sheet_Document` result.
	 * @param object                         $game      The issuing chronicle - `Pdf_Signer::configure()`'s own `Name`/`Reason` metadata.
	 * @param bool                           $signed    False prints an unsigned copy, marked as one.
	 * @throws \RuntimeException When asked to sign and signing is not configured or its files are unreadable.
	 */
	public static function write( array $documents, object $game, bool $signed = true ): string {
		$pdf = new \TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
		if ( $signed ) {
			Pdf_Signer::configure( $pdf, $game );
		}

		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );
		$pdf->setCreator( 'Beyond Elysium ' . BE_VERSION );
		$pdf->setAuthor( 'Beyond Elysium' );
		$pdf->setMargins( self::MARGIN, self::MARGIN, self::MARGIN );
		$pdf->setCellPaddings( self::SECTION_CELL_PADDING, 1, self::SECTION_CELL_PADDING, 1 );

		foreach ( $documents as $document ) {
			$pdf->setTitle( (string) ( $document['title'] ?? '' ) );
			$pdf->setAutoPageBreak( false );
			$pdf->AddPage();

			self::draw_header( $pdf, $document );
			self::draw_sections( $pdf, is_array( $document['sections'] ?? null ) ? $document['sections'] : [] );

			$pdf->setAutoPageBreak( true, self::MARGIN );
			self::draw_prose( $pdf, is_array( $document['prose'] ?? null ) ? $document['prose'] : [] );
			self::draw_xp_history( $pdf, is_array( $document['xp_history'] ?? null ) ? $document['xp_history'] : [] );
			self::draw_provenance_footer( $pdf, is_array( $document['provenance_lines'] ?? null ) ? $document['provenance_lines'] : [] );
		}

		if ( ! $signed ) {
			Pdf_Signer::mark_unsigned( $pdf );
		}

		return $pdf->Output( '', 'S' );
	}

	/**
	 * @param array<string,mixed> $document
	 */
	private static function draw_header( \TCPDF $pdf, array $document ): void {
		$y        = $pdf->GetY();
		$portrait = $document['portrait_path'] ?? null;
		$text_x   = self::MARGIN;

		if ( is_string( $portrait ) && $portrait !== '' && is_readable( $portrait ) ) {
			$pdf->Image( $portrait, self::MARGIN, $y, 30, 30 );
			$text_x = self::MARGIN + 34;
		}

		$pdf->setXY( $text_x, $y );
		$pdf->setFont( self::FONT, 'B', self::TITLE_SIZE );
		$pdf->Cell( 0, 8, (string) ( $document['title'] ?? '' ), 0, 1 );

		$pdf->setFont( self::FONT, '', self::BODY_SIZE );
		foreach ( (array) ( $document['header'] ?? [] ) as $pair ) {
			$pdf->setX( $text_x );
			$pdf->Cell( 0, 5, $pair[0] . ': ' . $pair[1], 0, 1 );
		}

		$portrait_bottom = ( $text_x > self::MARGIN ) ? $y + 32 : 0.0;
		$pdf->SetY( max( $pdf->GetY(), $portrait_bottom ) + self::ROW_GAP );
	}

	/**
	 * The 6-track row-flow: sections are placed in the order given (already `(column, order)`-sorted by
	 * `Sheet_Document`/`Layout_Flow`), and a section whose span doesn't fit what's left in the current row starts a new
	 * one.
	 *
	 * @param array<int,array<string,mixed>> $sections
	 */
	private static function draw_sections( \TCPDF $pdf, array $sections ): void {
		if ( empty( $sections ) ) {
			return;
		}

		$usable_width     = $pdf->getPageWidth() - 2 * self::MARGIN;
		$track_width      = $usable_width / self::GRID_TRACKS;
		$page_bottom      = $pdf->getPageHeight() - self::MARGIN;
		$max_page_height  = $pdf->getPageHeight() - 2 * self::MARGIN;

		$cursor     = 0;
		$row_y      = $pdf->GetY();
		$row_height = 0.0;

		foreach ( $sections as $section ) {
			$span = max( 1, min( self::GRID_TRACKS, (int) ( $section['span'] ?? self::GRID_TRACKS ) ) );

			if ( $cursor > 0 && $cursor + $span > self::GRID_TRACKS ) {
				$row_y     += $row_height + self::ROW_GAP;
				$cursor     = 0;
				$row_height = 0.0;
			}

			$box_width = $span * $track_width;
			$box_x     = self::MARGIN + $cursor * $track_width;

			[ $title_height, $body_height ] = self::measure_section( $pdf, $section, $box_width );
			$section_height                 = $title_height + $body_height;

			if ( $section_height > $max_page_height ) {
				// No position on any single page could ever hold this section.
				if ( $cursor > 0 ) {
					$row_y     += $row_height + self::ROW_GAP;
					$cursor     = 0;
					$row_height = 0.0;
				}
				$row_y = self::draw_overflowing_section( $pdf, $section, $row_y, $box_width, $page_bottom );
				continue;
			}

			if ( $row_y + $section_height > $page_bottom ) {
				$pdf->AddPage();
				$row_y      = $pdf->GetY();
				$cursor     = 0;
				$row_height = 0.0;
				$box_x      = self::MARGIN;
			}

			self::draw_section( $pdf, $section, $box_x, $row_y, $box_width, $title_height );

			$cursor     += $span;
			$row_height  = max( $row_height, $title_height + $body_height );

			if ( $cursor >= self::GRID_TRACKS ) {
				$row_y     += $row_height + self::ROW_GAP;
				$cursor     = 0;
				$row_height = 0.0;
			}
		}

		if ( $cursor > 0 ) {
			$row_y += $row_height + self::ROW_GAP;
		}
		$pdf->SetY( $row_y );
	}

	/**
	 * Draws a section too tall for any single page, letting TCPDF's own text flow carry it across as many pages as it
	 * needs.
	 *
	 * @param array<string,mixed> $section
	 * @return float The Y position immediately below the section, on whichever page
	 *               TCPDF's own pagination left it on - the next row starts there.
	 */
	private static function draw_overflowing_section( \TCPDF $pdf, array $section, float $row_y, float $box_width, float $page_bottom ): float {
		$pdf->AddPage();

		$pdf->setFont( self::FONT, 'B', self::SECTION_TITLE_SIZE );
		$pdf->MultiCell( $box_width, 0, (string) ( $section['title'] ?? '' ), 0, 'L', false, 1, self::MARGIN, $pdf->GetY() );

		$pdf->setAutoPageBreak( true, self::MARGIN );
		$pdf->setFont( self::FONT, '', self::BODY_SIZE );
		$pdf->MultiCell( $box_width, 0, self::section_body_text( $section ), 0, 'L', false, 1, self::MARGIN, $pdf->GetY() );
		$pdf->setAutoPageBreak( false );

		$end_y = $pdf->GetY();
		return $end_y >= $page_bottom ? $pdf->GetPageHeight() : $end_y;
	}

	/**
	 * @param array<string,mixed> $section
	 * @return array{0:float,1:float} Title height, body height - measured
	 *                                 separately since each is drawn in its
	 *                                 own font and `getStringHeight()` only
	 *                                 measures against whichever font is
	 *                                 currently set.
	 */
	private static function measure_section( \TCPDF $pdf, array $section, float $box_width ): array {
		$inner_width = $box_width - 2 * self::SECTION_CELL_PADDING;

		$pdf->setFont( self::FONT, 'B', self::SECTION_TITLE_SIZE );
		$title_height = $pdf->getStringHeight( $inner_width, (string) ( $section['title'] ?? '' ) );

		$pdf->setFont( self::FONT, '', self::BODY_SIZE );
		$body_height = $pdf->getStringHeight( $inner_width, self::section_body_text( $section ) );

		return [ $title_height, $body_height ];
	}

	/**
	 * @param array<string,mixed> $section
	 */
	private static function draw_section( \TCPDF $pdf, array $section, float $x, float $y, float $box_width, float $title_height ): void {
		$pdf->setFont( self::FONT, 'B', self::SECTION_TITLE_SIZE );
		$pdf->MultiCell( $box_width, 0, (string) ( $section['title'] ?? '' ), 0, 'L', false, 0, $x, $y );

		$pdf->setFont( self::FONT, '', self::BODY_SIZE );
		$pdf->MultiCell( $box_width, 0, self::section_body_text( $section ), 0, 'L', false, 0, $x, $y + $title_height );
	}

	/**
	 * Flattens a section's `groups` (trait_list) or `rows` (every other known type) into one newline-joined block.
	 *
	 * @param array<string,mixed> $section
	 */
	private static function section_body_text( array $section ): string {
		if ( isset( $section['groups'] ) && is_array( $section['groups'] ) ) {
			$lines = [];
			foreach ( $section['groups'] as $group ) {
				if ( ! empty( $group['label'] ) ) {
					$lines[] = $group['label'] . ':';
				}
				foreach ( (array) ( $group['rows'] ?? [] ) as $row ) {
					$lines[] = (string) $row;
				}
			}
			return implode( "\n", $lines );
		}

		if ( isset( $section['rows'] ) && is_array( $section['rows'] ) ) {
			return implode( "\n", array_map( 'strval', $section['rows'] ) );
		}

		return sprintf(
			'[unknown section type "%s" for block "%s"]',
			(string) ( $section['section_type'] ?? '?' ),
			(string) ( $section['block_slug'] ?? '?' )
		);
	}

	/**
	 * @param array<int,array{0:string,1:string}> $prose
	 */
	private static function draw_prose( \TCPDF $pdf, array $prose ): void {
		foreach ( $prose as $entry ) {
			[ $label, $html ] = $entry;

			$pdf->setFont( self::FONT, 'B', self::SECTION_TITLE_SIZE );
			$pdf->Cell( 0, 6, $label, 0, 1 );

			$pdf->setFont( self::FONT, '', self::BODY_SIZE );
			$pdf->writeHTML( self::sanitize_prose( (string) $html ), true, false, true, false, '' );
			$pdf->Ln( self::ROW_GAP );
		}
	}

	/**
	 * `wp_kses()` unwraps a disallowed tag but keeps its inner text.
	 */
	private static function sanitize_prose( string $html ): string {
		$html = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $html );
		return wp_kses( $html, self::PROSE_ALLOWED_TAGS );
	}

	/**
	 * @param array<int,array{0:string,1:string,2:string}> $rows
	 */
	private static function draw_xp_history( \TCPDF $pdf, array $rows ): void {
		if ( empty( $rows ) ) {
			return;
		}

		$usable_width = $pdf->getPageWidth() - 2 * self::MARGIN;
		$widths       = [ 0.18 * $usable_width, 0.7 * $usable_width, 0.12 * $usable_width ];

		$pdf->setFont( self::FONT, 'B', self::SECTION_TITLE_SIZE );
		$pdf->Cell( 0, 6, 'XP History', 0, 1 );

		$pdf->setFont( self::FONT, 'B', self::BODY_SIZE );
		$pdf->Cell( $widths[0], 6, 'Date', 1 );
		$pdf->Cell( $widths[1], 6, 'Description', 1 );
		$pdf->Cell( $widths[2], 6, 'XP', 1, 1, 'R' );

		$pdf->setFont( self::FONT, '', self::BODY_SIZE );
		foreach ( $rows as $row ) {
			[ $date, $description, $delta ] = $row;
			$pdf->Cell( $widths[0], 6, $date, 1 );
			$pdf->Cell( $widths[1], 6, $description, 1 );
			$pdf->Cell( $widths[2], 6, $delta, 1, 1, 'R' );
		}

		$pdf->Ln( self::ROW_GAP );
	}

	/**
	 * @param array<int,string> $lines
	 */
	private static function draw_provenance_footer( \TCPDF $pdf, array $lines ): void {
		if ( empty( $lines ) ) {
			return;
		}

		$pdf->setFont( self::FONT, 'I', self::FOOTNOTE_SIZE );
		$pdf->setTextColor( 120, 120, 120 );
		foreach ( $lines as $line ) {
			$pdf->Cell( 0, 4, (string) $line, 0, 1 );
		}
		$pdf->setTextColor( 0, 0, 0 );
	}
}
