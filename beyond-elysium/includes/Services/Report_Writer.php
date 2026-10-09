<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Attachment;

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

	private const CARD_WIDTH    = 127.0; // 5in
	private const CARD_HEIGHT   = 76.2;  // 3in
	private const CARD_PAD      = 3.0;
	private const CARD_PIC_SIZE = 38.0;
	private const CARD_TITLE_H  = 6.5;

	/**
	 * @param array<string,mixed> $document  `Report_Document::build()`'s return value.
	 * @param bool                $signed    False prints an unsigned copy, marked as one.
	 * @param string|null         $page_size `letter` or `a4`; anything else prints on the site's default.
	 * @throws \RuntimeException When asked to sign and signing is not configured or its files are unreadable.
	 */
	public static function write( array $document, object $game, bool $signed = true, ?string $page_size = null ): string {
		$orientation = ( $document['shape'] ?? '' ) === 'card' ? 'L' : 'P';
		$pdf         = new \TCPDF( $orientation, 'mm', Pdf_Writer::tcpdf_format( $page_size ), true, 'UTF-8', false );
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
	 * A header row, repeated whenever a page break lands after it.
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
	 * Draws the card grid at Grapevine's 5in×3in card size: landscape pages, two cards to a row, as many rows as fit
	 * the page. A card whose content doesn't fit one face continues on a second card printed beside it as its back
	 * (the Verify line moves there too), so the pair always takes a full row and folds down the middle into one card.
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
		$grid_left   = ( $pdf->getPageWidth() - 2 * self::CARD_WIDTH ) / 2;
		$column      = 0;
		$row_top     = $pdf->GetY();

		foreach ( $cards as $card ) {
			$faces = self::split_card_faces( $pdf, $card );
			$paired = count( $faces ) > 1;

			if ( $paired && $column === 1 ) {
				$column  = 0;
				$row_top += self::CARD_HEIGHT + self::CARD_PAD;
			}
			if ( $row_top + self::CARD_HEIGHT > $page_bottom ) {
				$pdf->AddPage();
				$row_top = $pdf->GetY();
				$column  = 0;
			}

			if ( $paired ) {
				self::draw_one_card( $pdf, $card, $faces[0], $grid_left, $row_top, false, false );
				self::draw_one_card( $pdf, $card, $faces[1], $grid_left + self::CARD_WIDTH, $row_top, true, true );
				$column   = 0;
				$row_top += self::CARD_HEIGHT + self::CARD_PAD;
				continue;
			}

			$x = $grid_left + $column * self::CARD_WIDTH;
			self::draw_one_card( $pdf, $card, $faces[0], $x, $row_top, true, false );
			if ( $column === 1 ) {
				$row_top += self::CARD_HEIGHT + self::CARD_PAD;
			}
			$column = 1 - $column;
		}
	}

	/**
	 * Splits a card's fields across one face, or two when they don't fit one. The first face always carries the
	 * picture and the uses count/boxes; only the last face carries the Verify line.
	 *
	 * @param array<string,mixed> $card
	 * @return array<int,array<string,mixed>> One element (a single face), or two (front, back).
	 */
	private static function split_card_faces( \TCPDF $pdf, array $card ): array {
		$fields         = is_array( $card['fields'] ?? null ) ? $card['fields'] : [];
		$has_picture    = ! empty( $card['picture'] );
		$text_width     = self::CARD_WIDTH - 2 * self::CARD_PAD - ( $has_picture ? self::CARD_PIC_SIZE + self::CARD_PAD : 0 );
		$available      = self::CARD_HEIGHT - self::CARD_TITLE_H - 2 * self::CARD_PAD;
		$uses_height    = ( $card['uses_max'] ?? null ) !== null ? Pdf_Rings::RING_STEP * Pdf_Rings::rows( (int) $card['uses_max'], 5 ) + 2.0 : 0.0;

		$pdf->setFont( self::FONT, '', self::BODY_SIZE );
		$front  = [];
		$back   = [];
		$height = $uses_height;
		$overflowed = false;

		foreach ( $fields as $field ) {
			$plain = $field['html'] ? wp_strip_all_tags( (string) $field['value'] ) : $field['label'] . ': ' . $field['value'];
			$field_height = $pdf->getStringHeight( $text_width, $plain ) + 0.8;

			if ( ! $overflowed && $height + $field_height <= $available ) {
				$front[]  = $field;
				$height  += $field_height;
			} else {
				$overflowed = true;
				$back[]     = $field;
			}
		}

		if ( $back === [] ) {
			return [ array_merge( $card, [ 'fields' => $front ] ) ];
		}

		return [
			array_merge( $card, [ 'fields' => $front, 'verify' => null ] ),
			array_merge( $card, [ 'fields' => $back, 'picture' => null, 'uses_max' => null, 'uses_used' => 0 ] ),
		];
	}

	/**
	 * Draws one card face: its frame, a centered title ("(cont.)" on a back face), its picture (front only), its
	 * fields (writeHTML for an HTML-kind one, bold-label/italic-value text otherwise), its uses count and boxes, and
	 * the Verify line when this is the face carrying it.
	 *
	 * @param array<string,mixed> $card The whole card, for its name.
	 * @param array<string,mixed> $face One face's own fields/picture/uses/verify.
	 */
	private static function draw_one_card( \TCPDF $pdf, array $card, array $face, float $x, float $y, bool $is_last_face, bool $is_back ): void {
		$pdf->Rect( $x, $y, self::CARD_WIDTH, self::CARD_HEIGHT );

		$title = (string) ( $card['name'] ?? '' );
		if ( $is_back ) {
			$title .= ' ' . __( '(cont.)', 'beyond-elysium' );
		}
		$pdf->setFont( self::FONT, 'B', self::HEAD_SIZE + 1 );
		$pdf->MultiCell( self::CARD_WIDTH, self::CARD_TITLE_H, $title, 0, 'C', false, 1, $x, $y + self::CARD_PAD * 0.5 );

		$content_top  = $y + self::CARD_TITLE_H + self::CARD_PAD * 0.5;
		$text_x       = $x + self::CARD_PAD;
		$picture_path = empty( $face['picture'] ) ? null : self::attachment_path( (int) $face['picture'] );

		if ( $picture_path !== null ) {
			$pdf->Image( $picture_path, $x + self::CARD_PAD, $content_top, self::CARD_PIC_SIZE, 0, '', '', '', false, 150, '', false, false, 0, true, false, false );
			$text_x += self::CARD_PIC_SIZE + self::CARD_PAD;
		}
		$text_width = $x + self::CARD_WIDTH - self::CARD_PAD - $text_x;

		$text_y = $content_top;
		foreach ( (array) ( $face['fields'] ?? [] ) as $field ) {
			if ( ! empty( $field['html'] ) ) {
				$pdf->setFont( self::FONT, 'B', self::BODY_SIZE );
				$pdf->setXY( $text_x, $text_y );
				$pdf->Cell( $text_width, 4, (string) $field['label'] . ':', 0, 1 );
				$text_y = $pdf->GetY();
				$pdf->setFont( self::FONT, '', self::BODY_SIZE );
				$pdf->writeHTMLCell( $text_width, 0, $text_x, $text_y, Rich_Text_Sanitizer::sanitize( (string) $field['value'] ), 0, 1, false, true, 'L' );
				$text_y = $pdf->GetY();
			} else {
				$html = '<b>' . esc_html( (string) $field['label'] ) . ':</b> <i>' . esc_html( (string) $field['value'] ) . '</i>';
				$pdf->writeHTMLCell( $text_width, 0, $text_x, $text_y, $html, 0, 1, false, true, 'L' );
				$text_y = $pdf->GetY();
			}
		}

		if ( ( $face['uses_max'] ?? null ) !== null ) {
			$pdf->setFont( self::FONT, 'BI', self::BODY_SIZE );
			$pdf->setXY( $text_x, $text_y );
			$pdf->Cell( $text_width, 4, sprintf( __( 'Count: %d', 'beyond-elysium' ), (int) $face['uses_max'] ), 0, 1 );
			Pdf_Rings::use_boxes( $pdf, (int) $face['uses_max'], (int) ( $face['uses_used'] ?? 0 ), $text_x, $pdf->GetY() + 0.5 );
		}

		if ( $is_last_face && ! empty( $card['verify'] ) ) {
			$pdf->setFont( self::FONT, 'I', self::BODY_SIZE - 2 );
			$pdf->MultiCell( self::CARD_WIDTH - 2 * self::CARD_PAD, 3, (string) $card['verify'], 0, 'L', false, 1, $x + self::CARD_PAD, $y + self::CARD_HEIGHT - 5 );
		}
	}

	/**
	 * Resolves an attachment id to a real filesystem path TCPDF can read directly, or null when the attachment row
	 * or its file is gone.
	 */
	private static function attachment_path( int $attachment_id ): ?string {
		$attachment = Attachment::find( $attachment_id );
		if ( $attachment === null ) {
			return null;
		}
		$path = Attachment_Storage::path_for( $attachment->stored_name, $attachment->original_name );
		return file_exists( $path ) ? $path : null;
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
	 * Game Calendar: one entry per game night - its date and start time, then its place and notes - or the empty note.
	 *
	 * @param array<string,mixed> $document
	 */
	private static function draw_calendar( \TCPDF $pdf, array $document ): void {
		$rows = is_array( $document['rows'] ?? null ) ? $document['rows'] : [];
		if ( empty( $rows ) ) {
			$pdf->setFont( self::FONT, 'I', self::BODY_SIZE );
			$pdf->MultiCell( 0, 0, (string) ( $document['note'] ?? '' ), 0, 'L' );
			return;
		}

		foreach ( $rows as $raw_row ) {
			$row = (array) $raw_row;

			$pdf->setFont( self::FONT, 'B', self::HEAD_SIZE );
			$pdf->Cell( 0, 6, trim( (string) ( $row['date'] ?? '' ) . ' ' . (string) ( $row['time'] ?? '' ) ), 0, 1 );

			$pdf->setFont( self::FONT, '', self::BODY_SIZE );
			$place = trim( (string) ( $row['place'] ?? '' ) );
			if ( $place !== '' ) {
				$pdf->MultiCell( 0, 0, $place, 0, 'L' );
			}
			$notes = trim( (string) ( $row['notes'] ?? '' ) );
			if ( $notes !== '' ) {
				$pdf->writeHTML( Rich_Text_Sanitizer::sanitize( $notes ), true, false, true, false, '' );
			}
			$pdf->Ln( 3 );
		}
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
