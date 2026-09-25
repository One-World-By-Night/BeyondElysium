<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/PdfSigningTestFixture.php';

use BeyondElysium\Services\Pdf_Writer;
use BeyondElysium\Tests\Support\PdfSigningTestFixture;
use WP_UnitTestCase;

/**
 * `Pdf_Writer::write()` end to end: structural output, prose HTML surviving real `wp_kses()` narrowing (a `<script>` tag
 * never reaches TCPDF's `writeHTML()`), the UNSIGNED stamp on an unsigned copy, and, for a signed output, a `/ByteRange`
 * and `/Sig` dictionary, a real reader reporting a valid signature from an untrusted (self-signed) signer, and one flipped
 * byte of the signed content breaking verification. A WordPress test rather than a database one: `draw_prose()` calls the
 * real `wp_kses()`.
 *
 * A throwaway self-signed certificate is generated once and shared with every other test needing signing configured,
 * via `PdfSigningTestFixture::ensure()`.
 */
class PdfWriterThreadTest extends WP_UnitTestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		PdfSigningTestFixture::ensure();
	}

	private function game(): object {
		return (object) [ 'name' => 'Test Chronicle' ];
	}

	private function document( array $overrides = [] ): array {
		return array_merge( [
			'title'            => 'Test Character',
			'subtitle'         => 'Vampire',
			'header'           => [ [ 'Printed', '2026-09-13' ], [ 'Status', 'Active' ], [ 'Clan', 'Tremere' ] ],
			'portrait_path'    => null,
			'style'            => [],
			'sections'         => [
				[ 'block_slug' => 'abilities', 'section_type' => 'trait_list', 'title' => 'Abilities', 'column' => 1, 'order' => 1, 'span' => 2, 'groups' => [ [ 'label' => null, 'rows' => [ 'Occult x3' ] ] ] ],
				[ 'block_slug' => 'mystery', 'section_type' => 'legacy_type', 'title' => 'Mystery', 'column' => 2, 'order' => 1, 'span' => 2 ],
			],
			'prose'            => [],
			'xp_history'       => [],
			'provenance_lines' => [ 'uuid-test', 'kony · 2026-09-13 · Beyond Elysium test' ],
		], $overrides );
	}

	private function write( array $overrides = [] ): string {
		return Pdf_Writer::write( [ $this->document( $overrides ) ], $this->game() );
	}

	public function test_write_produces_real_pdf_bytes(): void {
		$bytes = $this->write();

		$this->assertStringStartsWith( '%PDF-', $bytes );
		$this->assertStringEndsWith( '%%EOF', $bytes );
		$this->assertGreaterThan( 1000, strlen( $bytes ), 'a real page of content should produce more than a trivial byte count' );
	}

	public function test_one_page_per_document(): void {
		$bytes = Pdf_Writer::write(
			[ $this->document( [ 'title' => 'First' ] ), $this->document( [ 'title' => 'Second' ] ) ],
			$this->game()
		);

		$this->assertSame( 2, preg_match_all( '/\/Type\s*\/Page[^s]/', $bytes ) );
	}

	public function test_an_unrecognized_section_type_renders_the_visible_marker(): void {
		file_put_contents( $this->pdf_path(), $this->write() );

		// Word-wrap may fall inside the sentence.
		$this->assertMatchesRegularExpression(
			'/unknown\s+section\s+type\s+"legacy_type"\s+for\s+block\s+"mystery"/',
			self::extract_text( $this->pdf_path() )
		);
	}

	public function test_a_site_in_a_letter_country_prints_on_letter_and_anywhere_else_on_a4(): void {
		global $locale;
		$saved = $locale;

		$locale = 'en_US';
		$this->assertSame( 'letter', Pdf_Writer::default_page_size() );
		$locale = 'en_CA';
		$this->assertSame( 'letter', Pdf_Writer::default_page_size() );
		$locale = 'pt_BR';
		$this->assertSame( 'a4', Pdf_Writer::default_page_size() );
		$locale = 'en_GB';
		$this->assertSame( 'a4', Pdf_Writer::default_page_size() );

		$locale = $saved;
	}

	public function test_a_requested_page_size_wins_and_an_unknown_one_falls_back_to_the_default(): void {
		global $locale;
		$saved  = $locale;
		$locale = 'pt_BR';

		$this->assertSame( 'letter', Pdf_Writer::page_size( 'LETTER' ) );
		$this->assertSame( 'a4', Pdf_Writer::page_size( 'a4' ) );
		$this->assertSame( 'a4', Pdf_Writer::page_size( 'legal' ) );
		$this->assertSame( 'a4', Pdf_Writer::page_size( null ) );

		$locale = $saved;
	}

	public function test_the_page_is_the_size_asked_for(): void {
		$letter = Pdf_Writer::write( [ $this->document() ], $this->game(), false, 'letter' );
		$a4     = Pdf_Writer::write( [ $this->document() ], $this->game(), false, 'a4' );

		$this->assertMatchesRegularExpression( '/\/MediaBox\s*\[\s*0\.0+\s+0\.0+\s+612\.0+\s+792\.0+\s*\]/', $letter );
		$this->assertMatchesRegularExpression( '/\/MediaBox\s*\[\s*0\.0+\s+0\.0+\s+595\.27\d*\s+841\.89\d*\s*\]/', $a4 );
	}

	public function test_the_attribute_band_prints_side_by_side(): void {
		file_put_contents( $this->pdf_path(), $this->write( [
			'sections' => [
				[ 'block_slug' => 'physical', 'section_type' => 'trait_list', 'title' => 'Physical', 'band' => true, 'groups' => [ [ 'label' => null, 'rows' => [ 'Brawny 4' ] ] ] ],
				[ 'block_slug' => 'social', 'section_type' => 'trait_list', 'title' => 'Social', 'band' => true, 'groups' => [ [ 'label' => null, 'rows' => [ 'Charming 3' ] ] ] ],
				[ 'block_slug' => 'mental', 'section_type' => 'trait_list', 'title' => 'Mental', 'band' => true, 'groups' => [ [ 'label' => null, 'rows' => [ 'Clever 2' ] ] ] ],
				[ 'block_slug' => 'abilities', 'section_type' => 'trait_list', 'title' => 'Abilities', 'groups' => [ [ 'label' => null, 'rows' => [ 'Occult 3' ] ] ] ],
			],
		] ) );

		$text = self::extract_layout_text( $this->pdf_path() );
		$this->assertMatchesRegularExpression( '/Physical +Social +Mental/', $text, 'the three titles share one line' );
		$this->assertMatchesRegularExpression( '/Brawny 4 +Charming 3 +Clever 2/', $text, 'their first rows share one line' );
		$this->assertLessThan( strpos( $text, 'Abilities' ), strpos( $text, 'Brawny 4' ), 'the band comes before everything else' );
	}

	public function test_a_section_that_runs_past_its_column_continues_in_the_next_under_its_title(): void {
		$rows = [];
		for ( $i = 1; $i <= 90; $i++ ) {
			$rows[] = "Ability {$i} 3";
		}

		file_put_contents( $this->pdf_path(), $this->write( [
			'sections' => [
				[ 'block_slug' => 'abilities', 'section_type' => 'trait_list', 'title' => 'Abilities', 'groups' => [ [ 'label' => null, 'rows' => $rows ] ] ],
				[ 'block_slug' => 'backgrounds', 'section_type' => 'trait_list', 'title' => 'Backgrounds', 'groups' => [ [ 'label' => null, 'rows' => [ 'Allies 3' ] ] ] ],
			],
		] ) );

		$text = self::extract_layout_text( $this->pdf_path() );
		$this->assertStringContainsString( 'Abilities (cont.)', $text );
		$this->assertMatchesRegularExpression( '/Ability \d+ 3 +Ability \d+ 3/', $text, 'the continuation sits beside the column it came from' );
		$this->assertStringContainsString( 'Ability 90 3', $text );
		$this->assertStringContainsString( 'Allies 3', $text );
		$this->assertSame( 1, preg_match_all( '/\/Type\s*\/Page[^s]/', (string) file_get_contents( $this->pdf_path() ) ), 'ninety rows fit one page in three columns' );
	}

	public function test_a_short_sheet_spreads_across_the_three_columns(): void {
		$sections = [];
		foreach ( [ 'A', 'B', 'C', 'D', 'E', 'F' ] as $letter ) {
			$sections[] = [
				'block_slug' => 'section-' . $letter, 'section_type' => 'trait_list', 'title' => 'Section ' . $letter,
				'groups'     => [ [ 'label' => null, 'rows' => [ "Row {$letter}1 1", "Row {$letter}2 2", "Row {$letter}3 3" ] ] ],
			];
		}
		file_put_contents( $this->pdf_path(), $this->write( [ 'sections' => $sections ] ) );

		$text = self::extract_layout_text( $this->pdf_path() );
		$this->assertMatchesRegularExpression( '/Section A +Section C +Section E/', $text, 'two whole sections to a column' );
		$this->assertMatchesRegularExpression( '/Section B +Section D +Section F/', $text );
		$this->assertStringNotContainsString( '(cont.)', $text, 'no short section is split' );
	}

	public function test_the_provenance_lines_never_take_a_page_of_their_own(): void {
		$rows = [];
		for ( $i = 1; $i <= 70; $i++ ) {
			$rows[] = "Ability {$i} 3";
		}

		$bytes = $this->write( [
			'sections' => [
				[ 'block_slug' => 'abilities', 'section_type' => 'trait_list', 'title' => 'Abilities', 'groups' => [ [ 'label' => null, 'rows' => $rows ] ] ],
			],
		] );
		file_put_contents( $this->pdf_path(), $bytes );

		$this->assertSame( 1, preg_match_all( '/\/Type\s*\/Page[^s]/', $bytes ) );
		$this->assertStringContainsString( 'uuid-test', self::extract_text( $this->pdf_path() ) );
	}

	public function test_a_script_tag_in_prose_never_reaches_the_output(): void {
		$bytes = $this->write( [ 'prose' => [ [ 'Background', '<p>Safe text.</p><script>alert(1)</script>' ] ] ] );
		file_put_contents( $this->pdf_path(), $bytes );

		$text = self::extract_text( $this->pdf_path() );
		$this->assertStringContainsString( 'Safe text.', $text );
		$this->assertStringNotContainsString( 'alert(1)', $text );
		$this->assertStringNotContainsString( '<script>', $bytes );
	}

	public function test_signed_output_contains_byte_range_and_a_signature_dictionary(): void {
		$bytes = $this->write();

		$this->assertStringContainsString( '/ByteRange', $bytes );
		$this->assertStringContainsString( '/Sig', $bytes );
		$this->assertStringContainsString( '/Filter /Adobe.PPKLite', $bytes );
	}

	public function test_a_real_reader_reports_a_valid_signature_from_an_untrusted_signer(): void {
		file_put_contents( $this->pdf_path(), $this->write() );

		$report = self::pdfsig( $this->pdf_path() );

		$this->assertStringContainsString( 'Signature Validation: Signature is Valid.', $report );
		$this->assertStringContainsString( "Certificate issuer isn't Trusted.", $report, 'a self-signed certificate is expected to be untrusted, not invalid' );
	}

	/**
	 * A signed sheet is not just present, it actually detects tampering.
	 */
	public function test_flipping_one_byte_of_signed_content_breaks_verification(): void {
		$bytes = $this->write();

		if ( ! preg_match( '/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $bytes, $m ) ) {
			$this->fail( 'could not locate /ByteRange in the signed output to find a byte inside the signed content' );
		}
		// The middle of the first signed chunk.
		$offset           = intdiv( (int) $m[2], 2 );
		$bytes[ $offset ] = chr( ord( $bytes[ $offset ] ) ^ 0xFF );

		file_put_contents( $this->pdf_path(), $bytes );

		$this->assertStringContainsString( 'Digest Mismatch', self::pdfsig( $this->pdf_path() ) );
	}

	/**
	 * An unsigned copy says so on every page, however many pages its content runs to, and carries no signature dictionary
	 * at all.
	 */
	public function test_an_unsigned_copy_is_stamped_on_every_page(): void {
		$history = array_fill( 0, 90, [ '2026-09-01', 'Raised Occult', '+2' ] );
		$bytes   = Pdf_Writer::write( [ $this->document( [ 'xp_history' => $history ] ) ], $this->game(), false );
		file_put_contents( $this->pdf_path(), $bytes );

		$pages = preg_match_all( '/\/Type\s*\/Page[^s]/', $bytes );
		$this->assertGreaterThan( 1, $pages, 'the history must run past one page to prove every page is stamped' );
		$this->assertSame( $pages, substr_count( self::extract_text( $this->pdf_path() ), 'UNSIGNED' ) );
		$this->assertStringNotContainsString( '/ByteRange', $bytes );
	}

	public function test_a_signed_copy_is_never_stamped_unsigned(): void {
		file_put_contents( $this->pdf_path(), $this->write() );

		$this->assertStringNotContainsString( 'UNSIGNED', self::extract_text( $this->pdf_path() ) );
	}

	public function test_a_ringed_line_draws_a_ring_per_point_in_a_column_beside_its_text(): void {
		$bytes = $this->write( [
			'header'   => [ [ 'Clan', 'Tremere' ] ],
			'sections' => [ [
				'block_slug' => 'abilities', 'section_type' => 'trait_list', 'title' => 'Abilities', 'band' => true,
				'groups'     => [ [ 'label' => null, 'rows' => [
					[ 'text' => 'Occultish x3', 'indent' => 0, 'circles' => 3 ],
					[ 'text' => 'Alertness', 'indent' => 0, 'circles' => 1 ],
					[ 'text' => 'Nameonly', 'indent' => 0, 'circles' => 0 ],
					'Plainrow',
				] ] ],
			] ],
		] );
		file_put_contents( $this->pdf_path(), $bytes );
		$words = self::word_positions( $this->pdf_path() );

		$this->assertCount( 4, self::rings( $bytes ), 'one ring per point: 3 + 1 + 0' );
		$this->assertEqualsWithDelta( $words['Occultish'][0], $words['Alertness'][0], 0.1, 'text starts in one column' );
		$this->assertEqualsWithDelta( $words['Occultish'][0], $words['Nameonly'][0], 0.1, 'a line with no rating keeps the text column' );
		$this->assertEqualsWithDelta( 48.19, $words['Occultish'][0] - $words['Plainrow'][0], 0.6, 'the ring column is 17 mm wide' );
	}

	public function test_more_than_five_rings_continue_in_a_second_row_and_make_the_line_taller(): void {
		$bytes = $this->write( [
			'sections' => [ [
				'block_slug' => 'abilities', 'section_type' => 'trait_list', 'title' => 'Abilities', 'band' => true,
				'groups'     => [ [ 'label' => null, 'rows' => [
					[ 'text' => 'Seven x7', 'indent' => 0, 'circles' => 7 ],
					[ 'text' => 'Five x5', 'indent' => 0, 'circles' => 5 ],
					[ 'text' => 'Alpha', 'indent' => 0, 'circles' => 1 ],
					[ 'text' => 'Beta', 'indent' => 0, 'circles' => 1 ],
				] ] ],
			] ],
		] );
		file_put_contents( $this->pdf_path(), $bytes );
		$words = self::word_positions( $this->pdf_path() );
		$rings = self::rings( $bytes );

		$this->assertCount( 14, $rings );
		$this->assertEqualsWithDelta( $rings[0][0], $rings[5][0], 0.01, 'the sixth ring starts a new row under the first' );
		$this->assertEqualsWithDelta( 8.79, $rings[0][1] - $rings[5][1], 0.05, 'a row is 3.1 mm down' );
		$this->assertEqualsWithDelta(
			8.79,
			( $words['Five'][1] - $words['Seven'][1] ) - ( $words['Beta'][1] - $words['Alpha'][1] ),
			0.5,
			'the line with a second row of rings is one ring row taller'
		);
	}

	public function test_a_line_never_draws_more_than_twenty_rings(): void {
		$this->assertCount( 20, self::rings( $this->write( [
			'sections' => [ [
				'block_slug' => 'abilities', 'section_type' => 'trait_list', 'title' => 'Abilities', 'band' => true,
				'groups'     => [ [ 'label' => null, 'rows' => [ [ 'text' => 'Herd x50', 'indent' => 0, 'circles' => 50 ] ] ] ],
			] ],
		] ) ) );
	}

	public function test_a_pool_prints_its_rings_in_groups_of_five_below_the_plain_pairs(): void {
		$bytes = $this->write( [
			'header'   => [ [ 'Willpowerish', 'x12', 12 ], [ 'Bloodish', 'x20', 20 ], [ 'Claned', 'Tremere' ] ],
			'sections' => [],
		] );
		file_put_contents( $this->pdf_path(), $bytes );
		$words = self::word_positions( $this->pdf_path() );
		$rings = self::rings( $bytes );

		$this->assertCount( 32, $rings, '12 + 20' );
		$this->assertGreaterThan( $words['Claned'][1], $words['Willpowerish'][1], 'a rated pair follows the plain ones' );
		$this->assertEqualsWithDelta( 8.79, $rings[1][0] - $rings[0][0], 0.05, 'rings within a group are 3.1 mm apart' );
		$this->assertEqualsWithDelta( 12.19, $rings[5][0] - $rings[4][0], 0.05, 'a group of five is followed by a 1.2 mm gap' );
		$this->assertEqualsWithDelta( $rings[12][0], $rings[27][0], 0.01, 'a pool wider than the cell wraps whole groups: the sixteenth ring starts the second line' );
		$this->assertLessThan( $rings[12][1], $rings[27][1], 'and sits below the first line' );
	}

	public function test_a_pool_never_draws_more_than_sixty_rings(): void {
		$this->assertCount( 60, self::rings( $this->write( [
			'header'   => [ [ 'Bloodish', 'x99', 99 ] ],
			'sections' => [],
		] ) ) );
	}

	public function test_rings_are_thin_and_grey_and_the_rules_after_them_stay_black(): void {
		$strokes = self::strokes( $this->write( [
			'header'   => [ [ 'Willpowerish', 'x7', 7 ] ],
			'sections' => [
				[ 'block_slug' => 'abilities', 'section_type' => 'trait_list', 'title' => 'Abilities', 'band' => true, 'groups' => [ [ 'label' => null, 'rows' => [ [ 'text' => 'Occultish x3', 'indent' => 0, 'circles' => 3 ] ] ] ] ],
				[ 'block_slug' => 'plain', 'section_type' => 'trait_list', 'title' => 'Plain', 'band' => true, 'groups' => [ [ 'label' => null, 'rows' => [ 'Row' ] ] ] ],
			],
		] ) );

		$rings = array_filter( $strokes, static fn( $stroke ): bool => $stroke[0] === 'ring' );
		$lines = array_filter( $strokes, static fn( $stroke ): bool => $stroke[0] === 'line' );
		$this->assertCount( 10, $rings, '7 in the header, 3 beside the trait' );
		$this->assertNotEmpty( $lines );
		foreach ( $rings as $ring ) {
			$this->assertEqualsWithDelta( 0.4706, $ring[1], 0.01, 'a mid-grey outline' );
			$this->assertEqualsWithDelta( 0.425, $ring[2], 0.01, '0.15 mm thick' );
		}
		foreach ( $lines as $line ) {
			$this->assertSame( 0.0, $line[1], 'every rule is black, the ones drawn after the rings too' );
		}
	}

	/**
	 * Every stroked path on the document's pages, in the order drawn: `ring` for a curved one, `line` for a straight one, each with
	 * the grey level and the width in points it was stroked with.
	 *
	 * @return array<int,array{0:string,1:float,2:float}>
	 */
	private static function strokes( string $bytes ): array {
		$found = [];
		foreach ( self::page_streams( $bytes ) as $content ) {
			$tokens = preg_split( '/\s+/', (string) preg_replace( '/BT.*?ET/s', ' ', $content ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
			$level  = 0.0;
			$width  = 0.0;
			$curved = false;
			foreach ( $tokens as $i => $token ) {
				switch ( $token ) {
					case 'G':
						$level = (float) $tokens[ $i - 1 ];
						break;
					case 'RG':
						$level = (float) $tokens[ $i - 3 ];
						break;
					case 'w':
						$width = (float) $tokens[ $i - 1 ];
						break;
					case 'c':
						$curved = true;
						break;
					case 'S':
						$found[] = [ $curved ? 'ring' : 'line', $level, $width ];
						$curved  = false;
						break;
				}
			}
		}
		return $found;
	}

	/**
	 * The decoded content of every page, each stream read by its declared `/Length` the way a PDF reader reads it.
	 *
	 * @return string[]
	 */
	private static function page_streams( string $bytes ): array {
		$pages = [];
		preg_match_all( '/\/Contents (\d+) 0 R/', $bytes, $references );
		foreach ( array_unique( $references[1] ) as $number ) {
			if ( ! preg_match( '/(?:^|\n)' . $number . ' 0 obj\s*<<((?:(?!>>).)*)>>\s*stream\n/s', $bytes, $object, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			if ( ! preg_match( '/\/Length (\d+)/', $object[1][0], $length ) ) {
				continue;
			}
			$raw     = substr( $bytes, $object[0][1] + strlen( $object[0][0] ), (int) $length[1] );
			$content = str_contains( $object[1][0], '/FlateDecode' ) ? @gzuncompress( $raw ) : $raw;
			if ( $content !== false ) {
				$pages[] = $content;
			}
		}
		return $pages;
	}

	/**
	 * The start point of every ring drawn, in the order drawn, in PDF points.
	 *
	 * @return array<int,array{0:float,1:float}>
	 */
	private static function rings( string $bytes ): array {
		$found = [];
		foreach ( self::page_streams( $bytes ) as $content ) {
			if ( preg_match_all( '/([-\d.]+) ([-\d.]+) m\n(?:[-\d.]+ ){6}c\n/', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$found[] = [ (float) $match[1], (float) $match[2] ];
				}
			}
		}
		return $found;
	}

	/**
	 * Each word's left edge and top in points, by its first appearance, read back with `pdftotext -bbox`.
	 *
	 * @return array<string,array{0:float,1:float}>
	 */
	private static function word_positions( string $path ): array {
		if ( ! shell_exec( 'command -v pdftotext' ) ) {
			self::markTestSkipped( 'pdftotext (poppler) is not installed.' );
		}
		$xml = (string) shell_exec( 'pdftotext -bbox ' . escapeshellarg( $path ) . ' - 2>/dev/null' );
		preg_match_all( '/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="[\d.]+" yMax="[\d.]+">([^<]*)<\/word>/', $xml, $matches, PREG_SET_ORDER );

		$words = [];
		foreach ( $matches as $match ) {
			$words[ html_entity_decode( $match[3] ) ] ??= [ (float) $match[1], (float) $match[2] ];
		}
		return $words;
	}

	private function pdf_path(): string {
		return sys_get_temp_dir() . '/be-pdf-writer-test-' . $this->getName() . '.pdf';
	}

	protected function tearDown(): void {
		$path = $this->pdf_path();
		if ( is_file( $path ) ) {
			unlink( $path );
		}
		parent::tearDown();
	}

	/**
	 * Reads real text back out of a generated PDF via `pdftotext` (poppler) when available.
	 */
	private static function extract_text( string $path ): string {
		if ( ! shell_exec( 'command -v pdftotext' ) ) {
			self::markTestSkipped( 'pdftotext (poppler) is not installed.' );
		}
		return (string) shell_exec( 'pdftotext ' . escapeshellarg( $path ) . ' - 2>/dev/null' );
	}

	/**
	 * The same text with its layout kept, so side-by-side columns read across one line.
	 */
	private static function extract_layout_text( string $path ): string {
		if ( ! shell_exec( 'command -v pdftotext' ) ) {
			self::markTestSkipped( 'pdftotext (poppler) is not installed.' );
		}
		return (string) shell_exec( 'pdftotext -layout ' . escapeshellarg( $path ) . ' - 2>/dev/null' );
	}

	/**
	 * `pdfsig` (poppler) is the one tool in this environment that actually parses a PDF's embedded PKCS7/CMS signature
	 * dictionary and reports on it.
	 */
	private static function pdfsig( string $path ): string {
		if ( ! shell_exec( 'command -v pdfsig' ) ) {
			self::markTestSkipped( 'pdfsig (poppler) is not installed.' );
		}
		return (string) shell_exec( 'pdfsig ' . escapeshellarg( $path ) . ' 2>/dev/null' );
	}

	public function test_a_section_taller_than_one_page_does_not_lose_its_last_rows(): void {
		$rows = [];
		for ( $i = 1; $i <= 120; $i++ ) {
			$rows[] = "Ability {$i} x3 •••";
		}
		$rows[] = 'Streetwise x5 •••••';

		file_put_contents( $this->pdf_path(), $this->write( [
			'sections' => [
				[
					'block_slug'   => 'met-abilities', 'section_type' => 'trait_list', 'title' => 'Abilities',
					'column' => 1, 'order' => 1, 'span' => 2,
					'groups' => [ [ 'label' => null, 'rows' => $rows ] ],
				],
			],
		] ) );

		$text = self::extract_text( $this->pdf_path() );
		$this->assertStringContainsString( 'Ability 1 x3', $text, 'the section\'s first row' );
		$this->assertStringContainsString( 'Streetwise x5', $text, "the section's real last row must survive, not be cut off" );
	}
}
