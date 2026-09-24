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
			'header'           => [ [ 'Name', 'Test Character' ], [ 'Type', 'Vampire' ] ],
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
			'/unknown section type\s+"legacy_type" for block\s+"mystery"/',
			self::extract_text( $this->pdf_path() )
		);
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
