<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/PdfSigningTestFixture.php';

use BeyondElysium\Services\Pdf_Writer;
use BeyondElysium\Tests\Support\PdfSigningTestFixture;
use WP_UnitTestCase;

/**
 * `Pdf_Writer::write()` end to end: structural output, the one behavior that
 * must survive real `wp_kses()` narrowing untouched (a `<script>` tag in
 * prose HTML never reaches TCPDF's `writeHTML()`), and - since every output
 * is signed unconditionally (SP-8) - the three real proofs signed-pdf-
 * design.md's SP-8 names explicitly: a `/ByteRange`+`/Sig` dictionary is
 * present, a real reader reports a genuinely valid signature from an
 * untrusted (self-signed) signer, and flipping one byte of the signed
 * content breaks verification. WordPress-only, not database-only -
 * `draw_prose()` calls the real `wp_kses()`, which this project's own
 * unit/thread split (TESTING.md) puts here rather than in `tests/unit`.
 *
 * A real throwaway self-signed certificate is generated once, shared with
 * every other thread or workflow test needing signing configured, via
 * `PdfSigningTestFixture::ensure()` (`tests/support/` - see that class's own
 * docblock for why this must be idempotent, at one fixed path, and never
 * deleted). `PdfSignerTest.php` (unit) needs several *different*
 * defined-or-not states within one file and uses `@runInSeparateProcess`
 * for that reason; `bin/verify` runs the unit and thread suites as two
 * separate PHP processes, so these constants can never leak into that file's
 * own run regardless.
 *
 * @see BE_PROCESS/signed-pdf-design.md Section 3b, 3c, SP-7, SP-8
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

		// Word-wrap may fall inside the sentence, so match tolerant of a line break
		// between words rather than the whole phrase as one unbroken line.
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
	 * The test that proves the feature does what it claims (SP-8's own words):
	 * a signed sheet is not just present, it actually detects tampering.
	 */
	public function test_flipping_one_byte_of_signed_content_breaks_verification(): void {
		$bytes = $this->write();

		if ( ! preg_match( '/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $bytes, $m ) ) {
			$this->fail( 'could not locate /ByteRange in the signed output to find a byte inside the signed content' );
		}
		// The middle of the first signed chunk - deep inside real page content, far from
		// both the file header and the signature dictionary's own PDF syntax that sits
		// at the chunk's tail end (flipping a byte there corrupts the dictionary itself,
		// which fails to parse at all rather than cleanly failing a digest comparison).
		$offset           = intdiv( (int) $m[2], 2 );
		$bytes[ $offset ] = chr( ord( $bytes[ $offset ] ) ^ 0xFF );

		file_put_contents( $this->pdf_path(), $bytes );

		$this->assertStringContainsString( 'Digest Mismatch', self::pdfsig( $this->pdf_path() ) );
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
	 * Reads real text back out of a generated PDF via `pdftotext` (poppler) when
	 * available - the only reliable way to assert on TCPDF's actual rendered
	 * content, since its content streams are compressed by default. Skips the
	 * assertion rather than failing the whole suite on a machine without it.
	 */
	private static function extract_text( string $path ): string {
		if ( ! shell_exec( 'command -v pdftotext' ) ) {
			self::markTestSkipped( 'pdftotext (poppler) is not installed.' );
		}
		return (string) shell_exec( 'pdftotext ' . escapeshellarg( $path ) . ' - 2>/dev/null' );
	}

	/**
	 * `pdfsig` (poppler) is the one tool in this environment that actually parses a
	 * PDF's embedded PKCS7/CMS signature dictionary and reports on it - there is no
	 * PHP-native equivalent, and this project's own precedent (SP-1) is to verify a
	 * real signature with a real external tool rather than reimplement the check.
	 */
	private static function pdfsig( string $path ): string {
		if ( ! shell_exec( 'command -v pdfsig' ) ) {
			self::markTestSkipped( 'pdfsig (poppler) is not installed.' );
		}
		return (string) shell_exec( 'pdfsig ' . escapeshellarg( $path ) . ' 2>/dev/null' );
	}
}
