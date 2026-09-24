<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Pdf_Signer;
use PHPUnit\Framework\TestCase;

/**
 * Every failure mode of `Pdf_Signer::availability()` and `configure()`, proving no generation is attempted and the
 * passphrase never surfaces anywhere. Each test runs in its own PHP process, since `define()` is irreversible within one.
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

		// A stdClass, not a real TCPDF instance.
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
