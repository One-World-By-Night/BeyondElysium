<?php

namespace BeyondElysium\Tests\Support;

/**
 * Shared throwaway self-signed cert/key generation for every thread or workflow test that needs
 * `Pdf_Signer::availability()` to report ok.
 */
class PdfSigningTestFixture {

	public static function ensure(): void {
		update_option( \BeyondElysium\Services\Pdf_Signer::OPT_IN_OPTION, true );

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
