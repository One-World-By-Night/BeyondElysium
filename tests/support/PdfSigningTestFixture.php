<?php

namespace BeyondElysium\Tests\Support;

/**
 * Shared throwaway self-signed cert/key generation for every thread or
 * workflow test that needs `Pdf_Signer::availability()` to report ok.
 * `tests/` has no PSR-4 autoloading (only `beyond-elysium/includes/` does),
 * so this file is `require_once`d directly by each test file that calls
 * `ensure()`, rather than relying on class autoloading across test suites -
 * `--testsuite thread` and `--testsuite workflow` run as separate PHP
 * processes (`bin/verify --all`), and PHPUnit only `require`s files inside
 * whichever single suite directory it was told to run, so a class defined in
 * `tests/thread/SomeTest.php` is simply never loaded during a `workflow`-only
 * run and cannot be referenced from one.
 *
 * Idempotent and never deletes its files: PHP constants can only be
 * `define()`d once per process, so whichever test class runs first within a
 * suite "owns" the definition for the rest of that suite's run; a second
 * class deleting the file out from under an already-defined constant would
 * break every class using it that runs after. Temp files, cleaned up by the
 * OS in the ordinary course of things - throwaway self-signed test material,
 * nothing sensitive.
 *
 * @see BE_PROCESS/signed-pdf-design.md Section 3c, SP-7, SP-8, SP-12
 */
class PdfSigningTestFixture {

	public static function ensure(): void {
		if ( defined( 'BE_PDF_SIGNING_CERT' ) ) {
			return;
		}

		$key  = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		$csr  = openssl_csr_new( [ 'commonName' => 'Beyond Elysium Test Signer' ], $key );
		$cert = openssl_csr_sign( $csr, null, $key, 365 );

		openssl_x509_export( $cert, $cert_pem );
		openssl_pkey_export( $key, $key_pem );

		$cert_path = sys_get_temp_dir() . '/be-signed-pdf-tests.crt';
		$key_path  = sys_get_temp_dir() . '/be-signed-pdf-tests.key';
		file_put_contents( $cert_path, $cert_pem );
		file_put_contents( $key_path, $key_pem );

		define( 'BE_PDF_SIGNING_CERT', $cert_path );
		define( 'BE_PDF_SIGNING_KEY', $key_path );
		define( 'BE_PDF_SIGNING_PASSPHRASE', '' );
	}
}
