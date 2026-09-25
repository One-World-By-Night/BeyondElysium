<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Turns `Sheet_Document::for_characters()`'s arrays into TCPDF pages: a header block, the attribute sections side by side,
 * then every other section running down three columns and on to the next column and page.
 */
class Pdf_Writer {

	private const MARGIN             = 12.0; // mm
	private const COLUMNS            = 3;
	private const COLUMN_GAP         = 5.0;
	private const ROW_GAP            = 4.0;
	private const SECTION_GAP        = 3.0;
	private const BALANCE_SLACK      = 4.0;
	private const INDENT             = 3.5;
	private const FONT               = 'dejavusans';
	private const TITLE_SIZE         = 15;
	private const SUBTITLE_SIZE      = 11;
	private const SECTION_TITLE_SIZE = 9.5;
	private const BODY_SIZE          = 8.5;
	private const FOOTNOTE_SIZE      = 7;
	private const RING_SIZE          = 2.6; // mm across
	private const RING_STEP          = 3.1; // mm from one ring to the next
	private const RING_STROKE        = 0.15;
	private const RING_GREY          = 120; // 0 is black, 255 white
	private const RING_ROW           = 5;   // rings to a row beside a trait
	private const RING_TEXT_GAP      = 1.5; // between a trait's rings and its text
	private const RING_GROUP_GAP     = 1.2; // between groups of five in a pool's rings
	private const MAX_LINE_RINGS     = 20;
	private const MAX_POOL_RINGS     = 60;

	/**
	 * Regions of a site language that print on Letter paper.
	 */
	private const LETTER_REGIONS = [ 'US', 'CA', 'MX', 'PH' ];

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
	 * The paper a site prints on by default: Letter where its language names a Letter country, A4 elsewhere.
	 */
	public static function default_page_size(): string {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		$parts  = explode( '_', $locale );
		$region = strtoupper( (string) ( $parts[1] ?? '' ) );
		return in_array( $region, self::LETTER_REGIONS, true ) ? 'letter' : 'a4';
	}

	/**
	 * The paper to print on: the requested size when it names a known one, the site's default otherwise.
	 */
	public static function page_size( ?string $requested ): string {
		$requested = strtolower( (string) $requested );
		return in_array( $requested, [ 'letter', 'a4' ], true ) ? $requested : self::default_page_size();
	}

	/**
	 * TCPDF's name for the paper to print on.
	 */
	public static function tcpdf_format( ?string $requested ): string {
		return self::page_size( $requested ) === 'letter' ? 'LETTER' : 'A4';
	}

	/**
	 * Signed unless the caller says.
	 *
	 * @param array<int,array<string,mixed>> $documents One entry per `Sheet_Document` result.
	 * @param object                         $game      The issuing chronicle - `Pdf_Signer::configure()`'s own `Name`/`Reason` metadata.
	 * @param bool                           $signed    False prints an unsigned copy, marked as one.
	 * @param string|null                    $page_size `letter` or `a4`; anything else prints on the site's default.
	 * @throws \RuntimeException When asked to sign and signing is not configured or its files are unreadable.
	 */
	public static function write( array $documents, object $game, bool $signed = true, ?string $page_size = null ): string {
		$pdf = new \TCPDF( 'P', 'mm', self::tcpdf_format( $page_size ), true, 'UTF-8', false );
		if ( $signed ) {
			Pdf_Signer::configure( $pdf, $game );
		}

		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );
		$pdf->setCreator( 'Beyond Elysium ' . BE_VERSION );
		$pdf->setAuthor( 'Beyond Elysium' );
		$pdf->setMargins( self::MARGIN, self::MARGIN, self::MARGIN );
		$pdf->setCellPaddings( 0, 0.3, 0, 0.3 );

		foreach ( $documents as $document ) {
			$pdf->setTitle( (string) ( $document['title'] ?? '' ) );
			$pdf->setAutoPageBreak( false );
			$pdf->AddPage();

			self::draw_header( $pdf, $document );
			self::draw_sections( $pdf, is_array( $document['sections'] ?? null ) ? $document['sections'] : [] );

			$pdf->setAutoPageBreak( true, self::MARGIN );
			self::draw_prose( $pdf, is_array( $document['prose'] ?? null ) ? $document['prose'] : [] );
			self::draw_xp_history( $pdf, is_array( $document['xp_history'] ?? null ) ? $document['xp_history'] : [] );

			$pdf->setAutoPageBreak( false );
			self::draw_provenance_footer( $pdf, is_array( $document['provenance_lines'] ?? null ) ? $document['provenance_lines'] : [] );
		}

		if ( ! $signed ) {
			Pdf_Signer::mark_unsigned( $pdf );
		}

		return $pdf->Output( '', 'S' );
	}

	/**
	 * The name and creature type over a rule, then the header's label and value pairs three to a row.
	 *
	 * @param array<string,mixed> $document
	 */
	private static function draw_header( \TCPDF $pdf, array $document ): void {
		$left     = self::MARGIN;
		$width    = $pdf->getPageWidth() - 2 * self::MARGIN;
		$y        = self::MARGIN;
		$portrait = $document['portrait_path'] ?? null;

		$has_portrait = is_string( $portrait ) && $portrait !== '' && is_readable( $portrait );
		if ( $has_portrait ) {
			$pdf->Image( $portrait, $left, $y, 24, 24 );
			$left  += 28;
			$width -= 28;
		}

		$pdf->setFont( self::FONT, 'B', self::TITLE_SIZE );
		$pdf->setXY( $left, $y );
		$pdf->Cell( $width, 8, (string) ( $document['title'] ?? '' ), 0, 0, 'L' );

		$subtitle = (string) ( $document['subtitle'] ?? '' );
		if ( $subtitle !== '' ) {
			$pdf->setFont( self::FONT, 'B', self::SUBTITLE_SIZE );
			$pdf->setXY( $left, $y + 1 );
			$pdf->Cell( $width, 7, $subtitle, 0, 0, 'R' );
		}

		$y += 8.5;
		$pdf->setLineWidth( 0.3 );
		$pdf->Line( $left, $y, $left + $width, $y );
		$y += 2.0;

		$pairs  = array_values( (array) ( $document['header'] ?? [] ) );
		$plain  = array_values( array_filter( $pairs, static fn( $pair ): bool => ! isset( $pair[2] ) ) );
		$rated  = array_values( array_filter( $pairs, static fn( $pair ): bool => isset( $pair[2] ) ) );
		$column = ( $width - ( self::COLUMNS - 1 ) * self::COLUMN_GAP ) / self::COLUMNS;

		$pdf->setFont( self::FONT, '', self::BODY_SIZE );
		foreach ( [ $plain, $rated ] as $group ) {
			foreach ( array_chunk( $group, self::COLUMNS ) as $row ) {
				$row_bottom = $y;
				foreach ( $row as $i => $pair ) {
					$x    = $left + $i * ( $column + self::COLUMN_GAP );
					$html = '<b>' . esc_html( (string) ( $pair[0] ?? '' ) ) . '</b> ' . esc_html( (string) ( $pair[1] ?? '' ) );
					$pdf->writeHTMLCell( $column, 0, $x, $y, $html, 0, 1, false, true, 'L' );
					$bottom = $pdf->GetY();
					if ( isset( $pair[2] ) && (int) $pair[2] > 0 ) {
						$bottom = self::draw_pool_rings( $pdf, (int) $pair[2], $x, $bottom + 0.3, $column ) + 1.0;
					}
					$row_bottom = max( $row_bottom, $bottom );
				}
				$y = $row_bottom;
			}
		}

		$portrait_bottom = $has_portrait ? self::MARGIN + 24 : 0.0;
		$pdf->SetY( max( $y, $portrait_bottom ) + self::ROW_GAP );
	}

	/**
	 * The attribute band side by side, then every other section down three columns.
	 *
	 * @param array<int,array<string,mixed>> $sections
	 */
	private static function draw_sections( \TCPDF $pdf, array $sections ): void {
		if ( empty( $sections ) ) {
			return;
		}

		$band = [];
		$flow = [];
		foreach ( $sections as $section ) {
			if ( ! empty( $section['band'] ) ) {
				$band[] = $section;
			} else {
				$flow[] = $section;
			}
		}

		$geometry = self::geometry( $pdf );
		$page     = $pdf->getPage();
		$y        = $pdf->GetY();

		if ( $band !== [] ) {
			[ $page, $y ] = self::draw_band( $pdf, $band, $page, $y, $geometry );
			$y           += self::ROW_GAP;
		}
		if ( $flow !== [] ) {
			[ $page, $y ] = self::draw_flow( $pdf, $flow, $page, $y, $geometry );
		}

		$pdf->setPage( $page );
		$pdf->SetY( $y );
	}

	/**
	 * Where the columns sit on a page.
	 *
	 * @return array{width:float,xs:array<int,float>,top:float,bottom:float}
	 */
	private static function geometry( \TCPDF $pdf ): array {
		$usable = $pdf->getPageWidth() - 2 * self::MARGIN;
		$width  = ( $usable - ( self::COLUMNS - 1 ) * self::COLUMN_GAP ) / self::COLUMNS;
		$xs     = [];
		for ( $i = 0; $i < self::COLUMNS; $i++ ) {
			$xs[] = self::MARGIN + $i * ( $width + self::COLUMN_GAP );
		}
		return [
			'width'  => $width,
			'xs'     => $xs,
			'top'    => self::MARGIN,
			'bottom' => $pdf->getPageHeight() - self::MARGIN,
		];
	}

	/**
	 * The attribute sections three to a row, each row starting level.
	 *
	 * @param array<int,array<string,mixed>>                                          $band
	 * @param array{width:float,xs:array<int,float>,top:float,bottom:float}           $geometry
	 * @return array{0:int,1:float} Where the band ends.
	 */
	private static function draw_band( \TCPDF $pdf, array $band, int $page, float $y, array $geometry ): array {
		foreach ( array_chunk( $band, self::COLUMNS ) as $row ) {
			$opening = 0.0;
			foreach ( $row as $section ) {
				$opening = max( $opening, self::opening_height( $pdf, $section, $geometry['width'] ) );
			}
			if ( $y + $opening > $geometry['bottom'] ) {
				[ $page, $y ] = self::next_page( $pdf, $page, $geometry );
			}

			$end_page = $page;
			$end_y    = $y;
			foreach ( $row as $i => $section ) {
				[ $section_page, $section_y ] = self::draw_in_column( $pdf, $section, $geometry['xs'][ $i ], $page, $y, $geometry );
				if ( $section_page > $end_page || ( $section_page === $end_page && $section_y > $end_y ) ) {
					$end_page = $section_page;
					$end_y    = $section_y;
				}
			}
			$page = $end_page;
			$y    = $end_y + self::ROW_GAP;
		}
		return [ $page, $y - self::ROW_GAP ];
	}

	/**
	 * One section down one column, carrying on at the top of the same column on the next page.
	 *
	 * @param array<string,mixed>                                           $section
	 * @param array{width:float,xs:array<int,float>,top:float,bottom:float} $geometry
	 * @return array{0:int,1:float} Where the section ends.
	 */
	private static function draw_in_column( \TCPDF $pdf, array $section, float $x, int $page, float $y, array $geometry ): array {
		$pdf->setPage( $page );
		$title = (string) ( $section['title'] ?? '' );
		$y     = self::draw_title( $pdf, $title, $x, $y, $geometry['width'] );

		foreach ( self::section_lines( $section ) as $line ) {
			$height = self::line_height( $pdf, $line, $geometry['width'] );
			if ( $y + $height > $geometry['bottom'] ) {
				[ $page, $y ] = self::next_page( $pdf, $page, $geometry );
				$y            = self::draw_title( $pdf, $title . ' (cont.)', $x, $y, $geometry['width'] );
			}
			$y = self::draw_line( $pdf, $line, $x, $y, $geometry['width'], $height );
		}
		return [ $page, $y ];
	}

	/**
	 * Sections in order down the first column, then the second and third, then on to the next page. When what is left
	 * fits on the page, the columns are balanced: each column ends near what is left divided by the columns left, and a
	 * section that would split moves whole to the next column when it fits one.
	 *
	 * @param array<int,array<string,mixed>>                                $sections
	 * @param array{width:float,xs:array<int,float>,top:float,bottom:float} $geometry
	 * @return array{0:int,1:float} The last page used and the lowest point reached on it.
	 */
	private static function draw_flow( \TCPDF $pdf, array $sections, int $page, float $y, array $geometry ): array {
		$width    = $geometry['width'];
		$measured = [];
		foreach ( $sections as $section ) {
			$title = (string) ( $section['title'] ?? '' );
			$lines = [];
			foreach ( self::section_lines( $section ) as $line ) {
				$lines[] = [ $line, self::line_height( $pdf, $line, $width ) ];
			}
			$measured[] = [
				'title'   => $title,
				'title_h' => self::title_height( $pdf, $title, $width ),
				'cont_h'  => self::title_height( $pdf, $title . ' (cont.)', $width ),
				'lines'   => $lines,
			];
		}

		// The height still to print from section $from, line $line on.
		$remaining = static function ( int $from, int $line ) use ( $measured ): float {
			$height = 0.0;
			foreach ( array_slice( $measured, $from, null, true ) as $index => $section ) {
				$height += ( $index === $from && $line > 0 ) ? $section['cont_h'] : $section['title_h'];
				foreach ( array_slice( $section['lines'], $index === $from ? $line : 0 ) as $row ) {
					$height += $row[1];
				}
				$height += self::SECTION_GAP;
			}
			return $height;
		};

		$column     = 0;
		$column_top = $y;
		$lowest     = [ $page => $y ];
		$balanced   = false;
		$limit      = $geometry['bottom'];

		$plan = static function ( int $from, int $line, int $in_column, float $top ) use ( &$balanced, &$limit, $geometry, $remaining ): void {
			$left = $remaining( $from, $line );
			if ( $in_column === 0 ) {
				$balanced = $left <= self::COLUMNS * ( $geometry['bottom'] - $top );
			}
			$limit = ( $balanced && $in_column < self::COLUMNS - 1 )
				? min( $geometry['bottom'], $top + $left / ( self::COLUMNS - $in_column ) + self::BALANCE_SLACK )
				: $geometry['bottom'];
		};

		$advance = static function ( int $from, int $line ) use ( $pdf, &$page, &$y, &$column, &$column_top, $geometry, $plan ): void {
			if ( $column < self::COLUMNS - 1 ) {
				++$column;
				$y = $column_top;
			} else {
				[ $page, $y ] = self::next_page( $pdf, $page, $geometry );
				$column       = 0;
				$column_top   = $y;
			}
			$plan( $from, $line, $column, $column_top );
		};

		$plan( 0, 0, $column, $column_top );
		foreach ( $measured as $index => $section ) {
			$whole   = $section['title_h'] + array_sum( array_column( $section['lines'], 1 ) );
			$opening = $section['title_h'] + (float) ( $section['lines'][0][1] ?? 0.0 );
			$moves   = $balanced && $column < self::COLUMNS - 1 && $y > $column_top
				&& $y + $whole > $limit && $whole <= $limit - $column_top;
			if ( $moves || $y + $opening > $limit ) {
				$advance( $index, 0 );
			}
			$pdf->setPage( $page );
			$y = self::draw_title( $pdf, $section['title'], $geometry['xs'][ $column ], $y, $width );

			foreach ( $section['lines'] as $number => [ $line, $height ] ) {
				if ( $y + $height > $limit ) {
					$lowest[ $page ] = max( $lowest[ $page ] ?? 0.0, $y );
					$advance( $index, (int) $number );
					$pdf->setPage( $page );
					$y = self::draw_title( $pdf, $section['title'] . ' (cont.)', $geometry['xs'][ $column ], $y, $width );
				}
				$y = self::draw_line( $pdf, $line, $geometry['xs'][ $column ], $y, $width, $height );
			}

			$y              += self::SECTION_GAP;
			$lowest[ $page ] = max( $lowest[ $page ] ?? 0.0, $y );
		}

		return [ $page, $lowest[ $page ] - self::SECTION_GAP ];
	}

	/**
	 * Moves to the top of the next page, making it when it does not exist yet.
	 *
	 * @param array{width:float,xs:array<int,float>,top:float,bottom:float} $geometry
	 * @return array{0:int,1:float}
	 */
	private static function next_page( \TCPDF $pdf, int $page, array $geometry ): array {
		if ( $page < $pdf->getNumPages() ) {
			$pdf->setPage( $page + 1 );
		} else {
			$pdf->AddPage();
		}
		return [ $pdf->getPage(), $geometry['top'] ];
	}

	/**
	 * How tall a section's title prints, with its rule.
	 */
	private static function title_height( \TCPDF $pdf, string $title, float $width ): float {
		$pdf->setFont( self::FONT, 'B', self::SECTION_TITLE_SIZE );
		return $pdf->getStringHeight( $width, $title ) + 0.8;
	}

	/**
	 * A section's title in bold over a thin rule.
	 */
	private static function draw_title( \TCPDF $pdf, string $title, float $x, float $y, float $width ): float {
		$pdf->setFont( self::FONT, 'B', self::SECTION_TITLE_SIZE );
		$height = $pdf->getStringHeight( $width, $title );
		$pdf->MultiCell( $width, $height, $title, 0, 'L', false, 1, $x, $y, true, 0, false, true, 0, 'T' );
		$y += $height;
		$pdf->setLineWidth( 0.2 );
		$pdf->Line( $x, $y, $x + $width, $y );
		return $y + 0.8;
	}

	/**
	 * How tall one line prints: a group label in italics, anything else in the body font, narrowed by its indent and, when
	 * it carries rings, by their column. A ringed line is at least as tall as its rings.
	 *
	 * @param array{text:string,indent:int,label:bool,circles:?int} $line
	 */
	private static function line_height( \TCPDF $pdf, array $line, float $width ): float {
		$pdf->setFont( self::FONT, $line['label'] ? 'I' : '', self::BODY_SIZE );
		$text = $pdf->getStringHeight( self::text_width( $line, $width ), $line['text'] );
		if ( $line['circles'] === null ) {
			return $text;
		}
		return max( $text, self::first_line_height( $pdf ) + ( self::ring_rows( $line['circles'], self::RING_ROW ) - 1 ) * self::RING_STEP );
	}

	/**
	 * Prints one line at its indent: any rings in the column at its left, the text in the column beside it.
	 *
	 * @param array{text:string,indent:int,label:bool,circles:?int} $line
	 */
	private static function draw_line( \TCPDF $pdf, array $line, float $x, float $y, float $width, float $height ): float {
		$indent = $line['indent'] * self::INDENT;
		$gutter = $line['circles'] === null ? 0.0 : self::ring_column();
		$pdf->setFont( self::FONT, $line['label'] ? 'I' : '', self::BODY_SIZE );

		if ( $line['circles'] !== null && $line['circles'] > 0 ) {
			$top = $y + ( self::first_line_height( $pdf ) - self::RING_SIZE ) / 2;
			self::draw_rings( $pdf, $line['circles'], $x + $indent, $top, self::RING_ROW, false, self::MAX_LINE_RINGS );
		}

		$pdf->MultiCell( $width - $indent - $gutter, $height, $line['text'], 0, 'L', false, 1, $x + $indent + $gutter, $y, true, 0, false, true, 0, 'T' );
		return $y + $height;
	}

	/**
	 * How wide a line's text prints: the column less its indent and any ring column.
	 *
	 * @param array{text:string,indent:int,label:bool,circles:?int} $line
	 */
	private static function text_width( array $line, float $width ): float {
		return $width - $line['indent'] * self::INDENT - ( $line['circles'] === null ? 0.0 : self::ring_column() );
	}

	/**
	 * The width a trait's rings take, with the gap before its text.
	 */
	private static function ring_column(): float {
		return self::RING_ROW * self::RING_STEP + self::RING_TEXT_GAP;
	}

	/**
	 * How tall one line of body text prints.
	 */
	private static function first_line_height( \TCPDF $pdf ): float {
		$pdf->setFont( self::FONT, '', self::BODY_SIZE );
		return $pdf->getStringHeight( 1000.0, 'X' );
	}

	/**
	 * How many rows of rings `$count` rings take, `$per_row` to a row.
	 */
	private static function ring_rows( int $count, int $per_row ): int {
		return max( 1, intdiv( max( 0, $count ) + $per_row - 1, $per_row ) );
	}

	/**
	 * Draws `$count` empty rings from `$top` down, `$per_row` to a row, with a gap after every fifth ring when `$grouped`.
	 *
	 * @return float The bottom edge of the last row.
	 */
	private static function draw_rings( \TCPDF $pdf, int $count, float $x, float $top, int $per_row, bool $grouped, int $limit ): float {
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
		return $top + ( self::ring_rows( $count, $per_row ) - 1 ) * self::RING_STEP + self::RING_SIZE;
	}

	/**
	 * A pool's rings in groups of five, as many whole groups to a line as fit the width.
	 *
	 * @return float The bottom edge of the last row.
	 */
	private static function draw_pool_rings( \TCPDF $pdf, int $count, float $x, float $top, float $width ): float {
		$group  = self::RING_ROW * self::RING_STEP;
		$groups = max( 1, (int) floor( ( $width + self::RING_GROUP_GAP ) / ( $group + self::RING_GROUP_GAP ) ) );
		return self::draw_rings( $pdf, $count, $x, $top, $groups * self::RING_ROW, true, self::MAX_POOL_RINGS );
	}

	/**
	 * How much room a section needs to start: its title and its first line.
	 *
	 * @param array<string,mixed> $section
	 */
	private static function opening_height( \TCPDF $pdf, array $section, float $width ): float {
		$pdf->setFont( self::FONT, 'B', self::SECTION_TITLE_SIZE );
		$height = $pdf->getStringHeight( $width, (string) ( $section['title'] ?? '' ) ) + 0.8;
		$lines  = self::section_lines( $section );
		if ( $lines !== [] ) {
			$height += self::line_height( $pdf, $lines[0], $width );
		}
		return $height;
	}

	/**
	 * A section's printed lines: each group's label and rows (trait_list), or its rows (every other known type).
	 *
	 * @param array<string,mixed> $section
	 * @return array<int,array{text:string,indent:int,label:bool,circles:?int}>
	 */
	private static function section_lines( array $section ): array {
		$lines = [];

		if ( isset( $section['groups'] ) && is_array( $section['groups'] ) ) {
			foreach ( $section['groups'] as $group ) {
				if ( ! empty( $group['label'] ) ) {
					$lines[] = [ 'text' => (string) $group['label'], 'indent' => 0, 'label' => true, 'circles' => null ];
				}
				foreach ( (array) ( $group['rows'] ?? [] ) as $row ) {
					$lines[] = self::line_for( $row );
				}
			}
			return $lines;
		}

		if ( isset( $section['rows'] ) && is_array( $section['rows'] ) ) {
			foreach ( $section['rows'] as $row ) {
				$lines[] = self::line_for( $row );
			}
			return $lines;
		}

		return [
			[
				'text'    => sprintf(
					'[unknown section type "%s" for block "%s"]',
					(string) ( $section['section_type'] ?? '?' ),
					(string) ( $section['block_slug'] ?? '?' )
				),
				'indent'  => 0,
				'label'   => false,
				'circles' => null,
			],
		];
	}

	/**
	 * One document row as a printed line: a plain string, or an indented row's text and indent, with the rating a ringed
	 * row carries (null for a row that has none, which prints from the column's left edge).
	 *
	 * @param string|array{text?:string,indent?:int,circles?:int} $row
	 * @return array{text:string,indent:int,label:bool,circles:?int}
	 */
	private static function line_for( $row ): array {
		if ( is_array( $row ) ) {
			return [
				'text'    => (string) ( $row['text'] ?? '' ),
				'indent'  => max( 0, (int) ( $row['indent'] ?? 0 ) ),
				'label'   => false,
				'circles' => isset( $row['circles'] ) ? max( 0, (int) $row['circles'] ) : null,
			];
		}
		return [ 'text' => (string) $row, 'indent' => 0, 'label' => false, 'circles' => null ];
	}

	/**
	 * @param array<int,array{0:string,1:string}> $prose
	 */
	private static function draw_prose( \TCPDF $pdf, array $prose ): void {
		foreach ( $prose as $entry ) {
			[ $label, $html ] = $entry;

			$pdf->Ln( self::ROW_GAP );
			$pdf->setFont( self::FONT, 'B', self::SECTION_TITLE_SIZE );
			$pdf->Cell( 0, 6, $label, 0, 1 );

			$pdf->setFont( self::FONT, '', self::BODY_SIZE );
			$pdf->writeHTML( self::sanitize_prose( (string) $html ), true, false, true, false, '' );
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

		$pdf->Ln( self::ROW_GAP );
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
	}

	/**
	 * The provenance lines in the bottom margin of the last page, or at the foot of a new page when the content there
	 * reaches the margin.
	 *
	 * @param array<int,string> $lines
	 */
	private static function draw_provenance_footer( \TCPDF $pdf, array $lines ): void {
		if ( empty( $lines ) ) {
			return;
		}

		$line_height = 3.5;
		$top         = $pdf->getPageHeight() - 3.0 - count( $lines ) * $line_height;
		if ( $pdf->GetY() > $top ) {
			$pdf->AddPage();
		}

		$pdf->setFont( self::FONT, 'I', self::FOOTNOTE_SIZE );
		$pdf->setTextColor( 120, 120, 120 );
		$pdf->SetY( $top );
		foreach ( $lines as $line ) {
			$pdf->Cell( 0, $line_height, (string) $line, 0, 1 );
		}
		$pdf->setTextColor( 0, 0, 0 );
	}
}
