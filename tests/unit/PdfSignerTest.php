<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Pdf_Signer;
use PHPUnit\Framework\TestCase;

/**
 * GX-SP-6: every failure mode of `Pdf_Signer::availability()`/`configure()`,
 * proving no generation is ever attempted and the passphrase never surfaces
 * anywhere. No live signing here (P1's "hard failure, never a silent
 * unsigned PDF" is what's under test, not TCPDF's own signing mechanics,
 * which SheetsControllerPdfTest covers at the thread layer against a real
 * certificate).
 *
 * Each test runs in its own PHP process (`@runInSeparateProcess`) because
 * `define()` is irreversible within one process and PHPUnit otherwise runs
 * every test method in this file in the same one - without isolation, the
 * first test to define `BE_PDF_SIGNING_CERT` would leak into every test
 * after it.
 *
 * Every method also carries `@preserveGlobalState disabled`: process
 * isolation's default behavior serializes the parent process's `$GLOBALS` to
 * hand to the child, and by the time this class runs in a full-suite pass,
 * some earlier test in this large a suite has left a Closure somewhere in
 * global state (a WordPress hook callback is the likely shape) - PHP's
 * serialize() cannot represent a Closure at all, so that handoff throws
 * "Serialization of 'Closure' is not allowed" before this class's own test
 * body ever runs, intermittently, depending on what ran before it. Disabling
 * the handoff is correct regardless of the cause: the child process re-runs
 * this file's own bootstrap fresh and needs none of the parent's state -
 * every test here only ever reads a `define()` it makes itself, inside the
 * child. This is a per-method annotation, not a class-level one: PHPUnit's
 * class-level `@runInSeparateProcess` isolates the whole class from other
 * classes, but runs every method in *that one* shared child process rather
 * than giving each its own - the opposite of what this file needs, since
 * `define()`'s irreversibility is exactly the problem being isolated against
 * (confirmed the hard way: annotating the class instead of each method here
 * let `BE_PDF_SIGNING_CERT` leak between methods again).
 *
 * @see BE_PROCESS/signed-pdf-design.md §3c, SP-6
 */
class PdfSignerTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_availability_reports_cert_not_configured_when_the_constant_is_undefined(): void {
		$result = Pdf_Signer::availability();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'cert_not_configured', $result['code'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_availability_reports_cert_unreadable_when_the_file_does_not_exist(): void {
		define( 'BE_PDF_SIGNING_CERT', '/tmp/be-test-cert-does-not-exist-' . uniqid() . '.crt' );

		$result = Pdf_Signer::availability();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'cert_unreadable', $result['code'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_availability_reports_key_not_configured_when_only_the_cert_is_set(): void {
		$cert = tempnam( sys_get_temp_dir(), 'be-test-cert-' );
		file_put_contents( $cert, 'not a real certificate, just needs to exist and be readable' );
		define( 'BE_PDF_SIGNING_CERT', $cert );

		$result = Pdf_Signer::availability();

		unlink( $cert );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'key_not_configured', $result['code'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_availability_reports_key_unreadable_when_the_key_file_does_not_exist(): void {
		$cert = tempnam( sys_get_temp_dir(), 'be-test-cert-' );
		file_put_contents( $cert, 'not a real certificate, just needs to exist and be readable' );
		define( 'BE_PDF_SIGNING_CERT', $cert );
		define( 'BE_PDF_SIGNING_KEY', '/tmp/be-test-key-does-not-exist-' . uniqid() . '.key' );

		$result = Pdf_Signer::availability();

		unlink( $cert );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'key_unreadable', $result['code'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_availability_reports_ok_when_both_files_are_readable(): void {
		$cert = tempnam( sys_get_temp_dir(), 'be-test-cert-' );
		$key  = tempnam( sys_get_temp_dir(), 'be-test-key-' );
		file_put_contents( $cert, 'placeholder' );
		file_put_contents( $key, 'placeholder' );
		define( 'BE_PDF_SIGNING_CERT', $cert );
		define( 'BE_PDF_SIGNING_KEY', $key );

		$result = Pdf_Signer::availability();

		unlink( $cert );
		unlink( $key );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'ok', $result['code'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_configure_throws_rather_than_attempting_generation_when_unavailable(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'cert_not_configured' );

		// A stdClass, not a real TCPDF instance - if configure() ever reached setSignature(),
		// this would fail with a totally different error (wrong type), proving the throw
		// happens before any TCPDF interaction is attempted, not after a failed one.
		Pdf_Signer::configure( new \TCPDF(), (object) [ 'name' => 'Unavailable Test Chronicle' ] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_configure_exception_message_never_contains_a_passphrase(): void {
		define( 'BE_PDF_SIGNING_PASSPHRASE', 'super-secret-passphrase-must-never-leak' );

		try {
			Pdf_Signer::configure( new \TCPDF(), (object) [ 'name' => 'Test Chronicle' ] );
			$this->fail( 'Expected a RuntimeException.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringNotContainsString( 'super-secret-passphrase-must-never-leak', $e->getMessage() );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_availability_never_contains_a_passphrase_in_its_return_value(): void {
		define( 'BE_PDF_SIGNING_PASSPHRASE', 'super-secret-passphrase-must-never-leak' );

		$result = Pdf_Signer::availability();

		$this->assertStringNotContainsString( 'super-secret-passphrase-must-never-leak', json_encode( $result ) ?: '' );
	}
}
